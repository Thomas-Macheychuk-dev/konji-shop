<?php

declare(strict_types=1);

namespace App\Support\Cart;

final class CartLimits
{
    public const MIN_QUANTITY_PER_LINE = 1;

    public const MAX_QUANTITY_PER_LINE = 50;

    private function __construct() {}
}
