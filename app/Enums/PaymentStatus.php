<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';

    public function translationKey(): string
    {
        return 'enums.payment_status.'.$this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function badgeColorClasses(): string
    {
        return match ($this) {
            self::UNPAID => 'bg-zinc-100 text-zinc-800',
            self::PENDING => 'bg-amber-100 text-amber-800',
            self::PAID => 'bg-emerald-100 text-emerald-800',
            self::FAILED => 'bg-red-100 text-red-800',
            self::REFUNDED => 'bg-purple-100 text-purple-800',
        };
    }

    public function isUnpaid(): bool
    {
        return $this === self::UNPAID;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isRefunded(): bool
    {
        return $this === self::REFUNDED;
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
