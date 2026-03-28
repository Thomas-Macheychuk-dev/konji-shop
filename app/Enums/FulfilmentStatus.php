<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfilmentStatus: string
{
    case UNFULFILLED = 'unfulfilled';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case RETURNED = 'returned';

    public function translationKey(): string
    {
        return 'enums.fulfilment_status.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function badgeColorClasses(): string
    {
        return match ($this) {
            self::UNFULFILLED => 'bg-zinc-100 text-zinc-800',
            self::PROCESSING => 'bg-amber-100 text-amber-800',
            self::SHIPPED => 'bg-blue-100 text-blue-800',
            self::DELIVERED => 'bg-green-100 text-green-800',
            self::RETURNED => 'bg-purple-100 text-purple-800',
        };
    }

    public function isUnfulfilled(): bool
    {
        return $this === self::UNFULFILLED;
    }

    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
    }

    public function isShipped(): bool
    {
        return $this === self::SHIPPED;
    }

    public function isDelivered(): bool
    {
        return $this === self::DELIVERED;
    }

    public function isReturned(): bool
    {
        return $this === self::RETURNED;
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
