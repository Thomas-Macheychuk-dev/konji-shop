<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case PENDING_PAYMENT = 'pending_payment';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function translationKey(): string
    {
        return 'enums.order_status.'.$this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isPendingPayment(): bool
    {
        return $this === self::PENDING_PAYMENT;
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
    }

    public function isShipped(): bool
    {
        return $this === self::SHIPPED;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
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
