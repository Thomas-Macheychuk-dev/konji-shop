<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Data\Cart\CartTotalsData;
use App\Models\Cart;

class CartPricingService
{
    public function calculate(?Cart $cart): CartTotalsData
    {
        if (! $cart || $cart->items->isEmpty()) {
            return new CartTotalsData(
                subtotal: 0,
                shipping: 0,
                discount: 0,
                total: 0,
            );
        }

        $subtotal = 0;

        foreach ($cart->items as $item) {
            $lineTotalAmount = $item->currentLineTotalAmount();

            if ($lineTotalAmount === null) {
                continue;
            }

            $subtotal += $lineTotalAmount;
        }

        $shipping = 0;
        $discount = 0;
        $total = max(0, $subtotal + $shipping - $discount);

        return new CartTotalsData(
            subtotal: $subtotal,
            shipping: $shipping,
            discount: $discount,
            total: $total,
        );
    }
}
