<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProvider: string
{
    case PRZELEWY24 = 'przelewy24';

    public function label(): string
    {
        return match ($this) {
            self::PRZELEWY24 => 'Przelewy24',
        };
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
