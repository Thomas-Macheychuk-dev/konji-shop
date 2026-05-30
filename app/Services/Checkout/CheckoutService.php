<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Enums\CartStatus;
use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\Polkurier\PolkurierPackBuilder;
use App\Services\Delivery\Polkurier\PolkurierShippingQuoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private readonly OrderNumberGenerator $orderNumberGenerator,
        private readonly PolkurierShippingQuoteService $shippingQuoteService,
        private readonly PolkurierPackBuilder $packBuilder,
    ) {}

    /**
     * @param array<string, mixed> $data
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

            $itemsNet = array_sum(array_column($preparedItems, 'line_net_amount'));
            $itemsTax = array_sum(array_column($preparedItems, 'line_tax_amount'));
            $itemsGross = array_sum(array_column($preparedItems, 'line_gross_amount'));

            $subtotal = $itemsGross;
            $discount = 0;
            $placedAt = Carbon::now();

            $deliveryProvider = DeliveryProvider::from(
                (string) ($data['delivery_provider'] ?? DeliveryProvider::POLKURIER->value)
            );

            $deliveryCarrier = DeliveryCarrier::from(
                (string) ($data['delivery_carrier'] ?? DeliveryCarrier::INPOST->value)
            );

            $deliveryService = (string) ($data['delivery_service'] ?? 'parcel_locker');
            $deliveryLockerCode = $this->nullableString($data['delivery_locker_code'] ?? null);

            $shippingAddressData = $this->buildShippingAddressData($data, $user);
            $packs = $this->packBuilder->fromCart($lockedCart);

            $shippingQuote = $this->shippingQuoteService->quote(
                provider: $deliveryProvider,
                carrier: $deliveryCarrier,
                service: $deliveryService,
                shippingAddress: $shippingAddressData,
                currency: $lockedCart->currency,
                packs: $packs,
            );

            $shippingGross = $shippingQuote->amount;
            $shippingNet = $this->shippingNetFromGross($shippingGross);
            $shippingTax = max(0, $shippingGross - $shippingNet);

            $tax = $itemsTax + $shippingTax;
            $total = max(0, $itemsGross + $shippingGross - $discount);

            $order = Order::query()->create([
                'user_id' => $user?->id,
                'number' => $this->orderNumberGenerator->generate(),
                'guest_email' => $user ? null : (string) $data['email'],
                'status' => OrderStatus::PENDING_PAYMENT,
                'currency' => $lockedCart->currency,

                'subtotal_amount' => $subtotal,
                'items_net_amount' => $itemsNet,
                'items_tax_amount' => $itemsTax,
                'items_gross_amount' => $itemsGross,

                'shipping_amount' => $shippingGross,
                'shipping_net_amount' => $shippingNet,
                'shipping_tax_amount' => $shippingTax,
                'shipping_gross_amount' => $shippingGross,

                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $total,

                'payment_status' => PaymentStatus::UNPAID,
                'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
                'delivery_provider' => $deliveryProvider,
                'delivery_service' => $deliveryService,
                'delivery_carrier' => $deliveryCarrier,
                'delivery_locker_code' => $deliveryLockerCode,
                'notes' => $data['notes'] ?? null,
                'placed_at' => $placedAt,
            ]);

            $order->events()->create([
                'type' => 'delivery_choice_selected',
                'description' => 'Delivery method selected.',
                'meta' => [
                    'provider' => $deliveryProvider->value,
                    'carrier' => $deliveryCarrier->value,
                    'service' => $deliveryService,
                    'locker_code' => $deliveryLockerCode,
                    'shipping_quote' => $shippingQuote->payload,
                    'packs' => $packs,
                ],
            ]);

            foreach ($preparedItems as $preparedItem) {
                $order->items()->create([
                    'product_id' => $preparedItem['product_id'],
                    'product_variant_id' => $preparedItem['product_variant_id'],
                    'product_name_snapshot' => $preparedItem['product_name_snapshot'],
                    'variant_name_snapshot' => $preparedItem['variant_name_snapshot'],
                    'sku_snapshot' => $preparedItem['sku_snapshot'],

                    'unit_price_amount' => $preparedItem['unit_price_amount'],
                    'unit_net_amount' => $preparedItem['unit_net_amount'],
                    'unit_tax_amount' => $preparedItem['unit_tax_amount'],
                    'unit_gross_amount' => $preparedItem['unit_gross_amount'],

                    'quantity' => $preparedItem['quantity'],

                    'line_total_amount' => $preparedItem['line_total_amount'],
                    'line_net_amount' => $preparedItem['line_net_amount'],
                    'line_tax_amount' => $preparedItem['line_tax_amount'],
                    'line_gross_amount' => $preparedItem['line_gross_amount'],

                    'vat_rate_snapshot' => $preparedItem['vat_rate_snapshot'],
                    'meta' => $preparedItem['meta'],
                ]);
            }

            $billingAddressData = $this->buildBillingAddressData($data, $user, $shippingAddressData);

            $order->addresses()->create($shippingAddressData);
            $order->addresses()->create($billingAddressData);

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
                'events',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShippingAddressData(array $data, ?User $user): array
    {
        if ($user !== null && $user->hasPersonalAddress()) {
            $address = $user->toPersonalOrderAddressSnapshot('shipping');
            $address['phone'] = (string) $data['phone'];
            $address['email'] = (string) $data['email'];

            return $address;
        }

        return [
            'type' => 'shipping',
            'first_name' => (string) $data['shipping_first_name'],
            'last_name' => (string) $data['shipping_last_name'],
            'company' => $this->nullableString($data['shipping_company'] ?? null),
            'phone' => (string) $data['phone'],
            'email' => (string) $data['email'],
            'address_line_1' => (string) $data['shipping_address_line_1'],
            'address_line_2' => $this->nullableString($data['shipping_address_line_2'] ?? null),
            'city' => (string) $data['shipping_city'],
            'postcode' => (string) $data['shipping_postcode'],
            'country_code' => $this->normalizeCountryCode($data['shipping_country_code'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $shippingAddressData
     * @return array<string, mixed>
     */
    private function buildBillingAddressData(array $data, ?User $user, array $shippingAddressData): array
    {
        $billingAddressSource = (string) ($data['billing_address_source'] ?? '');

        if (! empty($data['billing_same_as_shipping']) || $billingAddressSource === 'same_as_shipping') {
            return [
                ...$shippingAddressData,
                'type' => 'billing',
            ];
        }

        if ($billingAddressSource === 'company_address') {
            if (! $user || ! $user->hasCompanyAddress()) {
                throw new RuntimeException('Company billing address is not available.');
            }

            $address = $user->toCompanyOrderAddressSnapshot('billing');
            $address['phone'] = (string) $data['phone'];
            $address['email'] = (string) $data['email'];

            return $address;
        }

        return [
            'type' => 'billing',
            'first_name' => (string) $data['billing_first_name'],
            'last_name' => (string) $data['billing_last_name'],
            'company' => $this->nullableString($data['billing_company'] ?? null),
            'phone' => (string) $data['phone'],
            'email' => (string) $data['email'],
            'address_line_1' => (string) $data['billing_address_line_1'],
            'address_line_2' => $this->nullableString($data['billing_address_line_2'] ?? null),
            'city' => (string) $data['billing_city'],
            'postcode' => (string) $data['billing_postcode'],
            'country_code' => $this->normalizeCountryCode($data['billing_country_code'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeCountryCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return $value !== '' ? $value : 'PL';
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

            $unitGrossAmount = $variant->grossPriceAmount();

            if ($unitGrossAmount === null || $unitGrossAmount < 0) {
                throw new RuntimeException("Variant {$variant->id} has an invalid price.");
            }

            if (! $variant->vat_rate instanceof VatRate) {
                throw new RuntimeException("Variant {$variant->id} has no VAT rate.");
            }

            if ($variant->currency === null) {
                throw new RuntimeException("Variant {$variant->id} has no currency.");
            }

            if ($variant->currency->value !== $cart->currency) {
                throw new RuntimeException("Variant {$variant->id} currency does not match the cart currency.");
            }

            $quantity = (int) $item->quantity;
            $vatRate = $variant->vat_rate;

            $unitNetAmount = $vatRate->netFromGross($unitGrossAmount);
            $unitTaxAmount = max(0, $unitGrossAmount - $unitNetAmount);

            $lineGrossAmount = $unitGrossAmount * $quantity;
            $lineNetAmount = $vatRate->netFromGross($lineGrossAmount);
            $lineTaxAmount = max(0, $lineGrossAmount - $lineNetAmount);

            $preparedItems[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => (string) $product->name,
                'variant_name_snapshot' => $this->resolveVariantSnapshotName($variant),
                'sku_snapshot' => $variant->sku,

                'unit_price_amount' => $unitGrossAmount,
                'unit_net_amount' => $unitNetAmount,
                'unit_tax_amount' => $unitTaxAmount,
                'unit_gross_amount' => $unitGrossAmount,

                'quantity' => $quantity,

                'line_total_amount' => $lineGrossAmount,
                'line_net_amount' => $lineNetAmount,
                'line_tax_amount' => $lineTaxAmount,
                'line_gross_amount' => $lineGrossAmount,

                'vat_rate_snapshot' => $vatRate->value,
                'meta' => [
                    'cart_item_id' => $item->id,
                    'cart_unit_price_snapshot' => (int) $item->unit_price,
                    'cart_meta' => $item->meta,
                    'package' => [
                        'weight_grams' => $variant->package_weight_grams,
                        'length_mm' => $variant->package_length_mm,
                        'width_mm' => $variant->package_width_mm,
                        'height_mm' => $variant->package_height_mm,
                    ],
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

    private function shippingNetFromGross(int $shippingGross): int
    {
        if ($shippingGross <= 0) {
            return 0;
        }

        return $this->shippingVatRate()->netFromGross($shippingGross);
    }

    private function shippingVatRate(): VatRate
    {
        return VatRate::VAT_23;
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
