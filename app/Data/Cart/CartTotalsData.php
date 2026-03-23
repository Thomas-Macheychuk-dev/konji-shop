<?php

declare(strict_types=1);

namespace App\Data\Cart;

final class CartTotalsData
{
    public function __construct(
        public readonly int $subtotal,
        public readonly int $shipping,
        public readonly int $discount,
        public readonly int $total,
    ) {}
}
