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
