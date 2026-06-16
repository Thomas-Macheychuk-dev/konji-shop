<?php

declare(strict_types=1);

namespace App\Services\Peruka;

final class PerukaPriceCalculator
{
    public const DISCOUNT_PERCENTAGE = 6;

    private const PRICE_MULTIPLIER = 0.94;

    public static function adjustedGrossMinorFromSource(mixed $value): ?int
    {
        $grossMinor = self::grossAmountMinor($value);

        return $grossMinor === null ? null : self::applyDiscountToGrossMinor($grossMinor);
    }

    public static function applyDiscountToGrossMinor(int $grossMinor): int
    {
        return max(0, (int) round($grossMinor * self::PRICE_MULTIPLIER));
    }

    public static function grossAmountMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(',', '.', $value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }
}
