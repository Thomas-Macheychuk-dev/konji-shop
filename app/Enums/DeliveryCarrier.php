<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryCarrier: string
{
    case INPOST = 'inpost';
    case UPS = 'ups';
    case DPD = 'dpd';
    case LOCAL_PICKUP = 'local_pickup';

    public function label(): string
    {
        return match ($this) {
            self::INPOST => 'InPost',
            self::UPS => 'UPS',
            self::DPD => 'DPD',
            self::LOCAL_PICKUP => 'Local pickup',
        };
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }
}
