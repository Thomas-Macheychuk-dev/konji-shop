<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Enums\CartStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private readonly OrderNumberGenerator $orderNumberGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function placeOrder(Cart $cart, array $data, ?User $user = null): Order
    {
        return DB::transaction(function () use ($cart, $data, $user): Order {
            /** @var Cart|null $lockedCart */
            $lockedCart = Cart::query()
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedCart) {
                throw new RuntimeException('Cart not found.');
            }

            $lockedCart->load([
                'items.product',
                'items.variant.attributeValues.attribute',
            ]);

            if ($lockedCart->status !== CartStatus::Active) {
                throw new RuntimeException('Only active carts can be checked out.');
            }

            if ($lockedCart->items->isEmpty()) {
                throw new RuntimeException('Cannot place an order from an empty cart.');
            }

            $preparedItems = $this->prepareCheckoutItems($lockedCart);

            $subtotal = array_sum(array_column($preparedItems, 'line_total_amount'));
            $shipping = 0;
            $discount = 0;
            $total = max(0, $subtotal + $shipping - $discount);
            $placedAt = Carbon::now();

            $order = Order::query()->create([
                'user_id' => $user?->id,
                'number' => $this->orderNumberGenerator->generate(),
                'guest_email' => $user ? null : (string) $data['email'],
                'status' => OrderStatus::PENDING_PAYMENT,
                'currency' => $lockedCart->currency,
                'subtotal_amount' => $subtotal,
                'shipping_amount' => $shipping,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_status' => PaymentStatus::UNPAID,
                'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
                'notes' => $data['notes'] ?? null,
                'placed_at' => $placedAt,
            ]);

            foreach ($preparedItems as $preparedItem) {
                $order->items()->create([
                    'product_id' => $preparedItem['product_id'],
                    'product_variant_id' => $preparedItem['product_variant_id'],
                    'product_name_snapshot' => $preparedItem['product_name_snapshot'],
                    'variant_name_snapshot' => $preparedItem['variant_name_snapshot'],
                    'sku_snapshot' => $preparedItem['sku_snapshot'],
                    'unit_price_amount' => $preparedItem['unit_price_amount'],
                    'quantity' => $preparedItem['quantity'],
                    'line_total_amount' => $preparedItem['line_total_amount'],
                    'vat_rate_snapshot' => $preparedItem['vat_rate_snapshot'],
                    'meta' => $preparedItem['meta'],
                ]);
            }

            $order->addresses()->create([
                'type' => 'shipping',
                'first_name' => (string) $data['shipping_first_name'],
                'last_name' => (string) $data['shipping_last_name'],
                'company' => $data['shipping_company'] ?? null,
                'phone' => (string) $data['phone'],
                'email' => (string) $data['email'],
                'address_line_1' => (string) $data['shipping_address_line_1'],
                'address_line_2' => $data['shipping_address_line_2'] ?? null,
                'city' => (string) $data['shipping_city'],
                'postcode' => (string) $data['shipping_postcode'],
                'country_code' => strtoupper((string) $data['shipping_country_code']),
            ]);

            if (! empty($data['billing_same_as_shipping'])) {
                $order->addresses()->create([
                    'type' => 'billing',
                    'first_name' => (string) $data['shipping_first_name'],
                    'last_name' => (string) $data['shipping_last_name'],
                    'company' => $data['shipping_company'] ?? null,
                    'phone' => (string) $data['phone'],
                    'email' => (string) $data['email'],
                    'address_line_1' => (string) $data['shipping_address_line_1'],
                    'address_line_2' => $data['shipping_address_line_2'] ?? null,
                    'city' => (string) $data['shipping_city'],
                    'postcode' => (string) $data['shipping_postcode'],
                    'country_code' => strtoupper((string) $data['shipping_country_code']),
                ]);
            } else {
                $order->addresses()->create([
                    'type' => 'billing',
                    'first_name' => (string) $data['billing_first_name'],
                    'last_name' => (string) $data['billing_last_name'],
                    'company' => $data['billing_company'] ?? null,
                    'phone' => (string) $data['phone'],
                    'email' => (string) $data['email'],
                    'address_line_1' => (string) $data['billing_address_line_1'],
                    'address_line_2' => $data['billing_address_line_2'] ?? null,
                    'city' => (string) $data['billing_city'],
                    'postcode' => (string) $data['billing_postcode'],
                    'country_code' => strtoupper((string) $data['billing_country_code']),
                ]);
            }

            $order->payments()->create([
                'provider' => null,
                'provider_reference' => null,
                'status' => PaymentStatus::UNPAID,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'paid_at' => null,
                'payload' => null,
            ]);

            $lockedCart->update([
                'status' => CartStatus::Converted,
            ]);

            return $order->load([
                'items',
                'shippingAddress',
                'billingAddress',
                'payments',
            ]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareCheckoutItems(Cart $cart): array
    {
        $preparedItems = [];

        foreach ($cart->items as $item) {
            $product = $item->product;
            $variant = $item->variant;

            if (! $product) {
                throw new RuntimeException('A cart item references a missing product.');
            }

            if (! $variant) {
                throw new RuntimeException('A cart item references a missing variant.');
            }

            if ($variant->status !== ProductVariantStatus::ACTIVE) {
                throw new RuntimeException("Variant {$variant->id} is not available for checkout.");
            }

            if ($variant->stock_status === StockStatus::OUT_OF_STOCK) {
                throw new RuntimeException("Variant {$variant->id} is out of stock.");
            }

            if ((int) $item->quantity < 1) {
                throw new RuntimeException('A cart item has an invalid quantity.');
            }

            $unitPriceAmount = $variant->grossPriceAmount();

            if ($unitPriceAmount === null || $unitPriceAmount < 0) {
                throw new RuntimeException("Variant {$variant->id} has an invalid price.");
            }

            if ($variant->currency === null) {
                throw new RuntimeException("Variant {$variant->id} has no currency.");
            }

            if ($variant->currency->value !== $cart->currency) {
                throw new RuntimeException("Variant {$variant->id} currency does not match the cart currency.");
            }

            $preparedItems[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => (string) $product->name,
                'variant_name_snapshot' => $this->resolveVariantSnapshotName($variant),
                'sku_snapshot' => $variant->sku,
                'unit_price_amount' => $unitPriceAmount,
                'quantity' => (int) $item->quantity,
                'line_total_amount' => $unitPriceAmount * (int) $item->quantity,
                'vat_rate_snapshot' => $variant->vat_rate?->value,
                'meta' => [
                    'cart_item_id' => $item->id,
                    'cart_unit_price_snapshot' => (int) $item->unit_price,
                    'cart_meta' => $item->meta,
                    'attribute_values' => $variant->attributeValues->map(function ($attributeValue): array {
                        return [
                            'attribute_id' => $attributeValue->attribute_id,
                            'attribute_name' => $attributeValue->attribute?->name,
                            'value_id' => $attributeValue->id,
                            'value' => $attributeValue->value,
                            'slug' => $attributeValue->slug,
                        ];
                    })->values()->all(),
                ],
            ];
        }

        return $preparedItems;
    }

    private function resolveVariantSnapshotName($variant): ?string
    {
        if ($variant->relationLoaded('attributeValues') && $variant->attributeValues->isNotEmpty()) {
            $parts = $variant->attributeValues
                ->map(fn ($attributeValue): string => (string) $attributeValue->value)
                ->filter()
                ->values();

            if ($parts->isNotEmpty()) {
                return $parts->implode(' / ');
            }
        }

        if (filled($variant->sku)) {
            return (string) $variant->sku;
        }

        return null;
    }
}
