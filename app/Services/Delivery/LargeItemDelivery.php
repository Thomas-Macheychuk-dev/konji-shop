<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Data\Delivery\ShippingQuoteResult;
use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Models\Cart;
use App\Models\CartItem;

/**
 * Products too large for a parcel (products.large_item_delivery_amount) go by courier at a flat price: 80 zł
 * for couches, treatment tables, changing tables and blood-draw stations, 72 zł for screens and IV stands
 * (Krzysztof, 16 November 2026). A cart with several of them pays the highest price once. Parcel lockers are
 * not offered; collecting from the shop stays free.
 */
final class LargeItemDelivery
{
    public const SOURCE = 'large_item';

    public const LOCKER_MESSAGE = 'Produkty wielkogabarytowe nie zmieszczą się w paczkomacie. Wybierz dostawę kurierem lub odbiór osobisty.';

    /** The flat delivery price in grosze, or null when the cart has no large item. */
    public function amountForCart(?Cart $cart): ?int
    {
        if ($cart === null) {
            return null;
        }

        $cart->loadMissing('items.product');

        $amounts = $cart->items
            ->map(fn (CartItem $item): ?int => $item->product?->large_item_delivery_amount)
            ->filter(fn (?int $amount): bool => $amount !== null && $amount > 0);

        return $amounts->isEmpty() ? null : (int) $amounts->max();
    }

    public function rejectsService(?int $amount, string $service): bool
    {
        return $amount !== null && $service === 'parcel_locker';
    }

    public function applies(?int $amount, string $service): bool
    {
        return $amount !== null && $service !== 'local_pickup' && $service !== 'parcel_locker';
    }

    public function quote(int $amount, DeliveryProvider $provider, DeliveryCarrier $carrier, string $service, string $currency): ShippingQuoteResult
    {
        return new ShippingQuoteResult(
            amount: $amount,
            currency: $currency,
            provider: $provider->value,
            carrier: $carrier->value,
            service: $service,
            providerServiceCode: null,
            providerServiceName: 'Dostawa wielkogabarytowa',
            payload: ['source' => self::SOURCE],
        );
    }
}
