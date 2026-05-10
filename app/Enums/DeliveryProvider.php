<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryProvider: string
{
    case INPOST = 'inpost';

    public function label(): string
    {
        return match ($this) {
            self::INPOST => 'InPost',
        };
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }
}
