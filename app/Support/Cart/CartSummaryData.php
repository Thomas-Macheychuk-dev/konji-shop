<?php

declare(strict_types=1);

namespace App\Support\Cart;

use App\Data\Cart\CartTotalsData;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Support\Collection;

class CartSummaryData
{
    public static function fromCart(?Cart $cart, CartTotalsData $totals): array
    {
        $currency = (string) ($cart?->currency ?? 'PLN');

        if (! $cart || $cart->items->isEmpty()) {
            return [
                'count' => 0,
                'subtotal_amount' => 0,
                'subtotal' => self::formatAmount(0, $currency),
                'currency' => $currency,
                'items' => [],
                'cart_url' => route('cart.show'),
                'checkout_url' => route('checkout.show'),
            ];
        }

        $items = $cart->items
            ->take(5)
            ->map(fn (CartItem $item): array => self::mapItem($item, $currency))
            ->values()
            ->all();

        return [
            'count' => (int) $cart->items->count(),
            'subtotal_amount' => $totals->subtotal,
            'subtotal' => self::formatAmount($totals->subtotal, $currency),
            'currency' => $currency,
            'items' => $items,
            'cart_url' => route('cart.show'),
            'checkout_url' => route('checkout.show'),
        ];
    }

    protected static function mapItem(CartItem $item, string $fallbackCurrency): array
    {
        $product = $item->product;
        $variant = $item->variant;
        $currency = (string) ($item->currency ?? $fallbackCurrency);
        $lineTotalAmount = $item->currentLineTotalAmount() ?? 0;

        return [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'product_name' => $product?->name ?? ($item->meta['product_name'] ?? 'Product'),
            'variant_name' => self::variantName($item),
            'product_url' => $product ? route('products.show', $product->slug) : null,
            'image_url' => self::resolveVariantImageUrl($item),
            'line_total_amount' => $lineTotalAmount,
            'line_total' => self::formatAmount($lineTotalAmount, $currency),
            'update_url' => route('cart.items.update', $item),
            'remove_url' => route('cart.items.destroy', $item),
        ];
    }

    protected static function variantName(CartItem $item): ?string
    {
        $variant = $item->variant;

        if (! $variant) {
            return $item->meta['variant_sku'] ?? null;
        }

        /** @var Collection<int, string> $parts */
        $parts = $variant->attributeValues
            ->map(function ($attributeValue): ?string {
                $attributeName = $attributeValue->attribute?->name;
                $value = $attributeValue->value;

                if (! $attributeName || ! $value) {
                    return null;
                }

                return "{$attributeName}: {$value}";
            })
            ->filter()
            ->values();

        if ($parts->isNotEmpty()) {
            return $parts->implode(', ');
        }

        return $variant->sku ?: ($item->meta['variant_sku'] ?? null);
    }

    protected static function formatAmount(int $amount, string $currency): string
    {
        return number_format($amount / 100, 2, '.', ' ').' '.$currency;
    }

    protected static function resolveVariantImageUrl(CartItem $item): ?string
    {
        $variant = $item->variant;
        return $variant?->main_image_url ?? ($item->meta['image_url'] ?? null);
    }
}
