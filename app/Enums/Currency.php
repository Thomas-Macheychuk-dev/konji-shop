<?php

declare(strict_types=1);

namespace App\Enums;

enum Currency: string
{
    case PLN = 'PLN';

    public function translationKey(): string
    {
        return 'enums.currency.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function symbol(): string
    {
        return match ($this) {
            self::PLN => 'zł',
        };
    }

    public function locale(): string
    {
        return match ($this) {
            self::PLN => 'pl_PL',
        };
    }

    public static function default(): self
    {
        return self::PLN;
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
