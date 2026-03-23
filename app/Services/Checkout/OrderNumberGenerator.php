<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\Order;
use Random\RandomException;

class OrderNumberGenerator
{
    /**
     * @throws RandomException
     */
    public function generate(): string
    {
        do {
            $number = $this->makeCandidate();
        } while (
            Order::query()->where('number', $number)->exists()
        );

        return $number;
    }

    /**
     * @throws RandomException
     */
    private function makeCandidate(): string
    {
        return sprintf(
            'ORDER-%s-%04d',
            now()->format('Ymd'),
            random_int(1, 99999)
        );
    }
}
