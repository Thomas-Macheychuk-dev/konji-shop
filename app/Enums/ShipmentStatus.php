<?php

declare(strict_types=1);

namespace App\Enums;

enum ShipmentStatus: string
{
    case PENDING = 'pending';
    case CREATED = 'created';
    case DISPATCHED = 'dispatched';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function translationKey(): string
    {
        return 'enums.shipment_status.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function badgeColorClasses(): string
    {
        return match ($this) {
            self::PENDING => 'bg-zinc-100 text-zinc-800',
            self::CREATED => 'bg-blue-100 text-blue-800',
            self::DISPATCHED, self::IN_TRANSIT => 'bg-amber-100 text-amber-800',
            self::DELIVERED => 'bg-emerald-100 text-emerald-800',
            self::FAILED, self::CANCELLED => 'bg-red-100 text-red-800',
            self::RETURNED => 'bg-orange-100 text-orange-800',
        };
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }
}
