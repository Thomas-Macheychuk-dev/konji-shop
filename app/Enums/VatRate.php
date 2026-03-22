<?php

declare(strict_types=1);

namespace App\Enums;

enum VatRate: int
{
    case VAT_23 = 23;
    case VAT_8 = 8;
    case VAT_5 = 5;
    case VAT_0 = 0;

    public function translationKey(): string
    {
        return 'enums.vat_rate.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function percentage(): int
    {
        return $this->value;
    }

    public function fraction(): float
    {
        return $this->value / 100;
    }

    public function multiplier(): float
    {
        return 1 + $this->fraction();
    }

    public function grossFromNet(int $netAmount): int
    {
        return (int) round($netAmount * $this->multiplier());
    }

    public function netFromGross(int $grossAmount): int
    {
        return (int) round($grossAmount / $this->multiplier());
    }

    public function vatAmountFromNet(int $netAmount): int
    {
        return $this->grossFromNet($netAmount) - $netAmount;
    }

    public function vatAmountFromGross(int $grossAmount): int
    {
        return $grossAmount - $this->netFromGross($grossAmount);
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[(string) $case->value] = $case->label();
        }

        return $labels;
    }
}
