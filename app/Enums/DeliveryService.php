<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryService: string
{
    case PARCEL_LOCKER = 'parcel_locker';
    case COURIER = 'courier';
    case LOCAL_PICKUP = 'local_pickup';

    public function label(): string
    {
        return match ($this) {
            self::PARCEL_LOCKER => __('Parcel locker'),
            self::COURIER => __('Courier delivery'),
            self::LOCAL_PICKUP => __('Pickup from shop'),
        };
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }
}
