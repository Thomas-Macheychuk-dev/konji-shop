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
        return 'enums.order_status.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function badgeColorClasses(): string
    {
        return match ($this) {
            self::DRAFT => 'bg-zinc-100 text-zinc-800',
            self::PENDING_PAYMENT => 'bg-amber-100 text-amber-800',
            self::PAID => 'bg-emerald-100 text-emerald-800',
            self::PROCESSING => 'bg-blue-100 text-blue-800',
            self::SHIPPED => 'bg-sky-100 text-sky-800',
            self::COMPLETED => 'bg-green-100 text-green-800',
            self::CANCELLED => 'bg-red-100 text-red-800',
        };
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
