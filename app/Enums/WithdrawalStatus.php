<?php

declare(strict_types=1);

namespace App\Enums;

enum WithdrawalStatus: string
{
    case SUBMITTED = 'submitted';
    case ACKNOWLEDGED = 'acknowledged';
    case UNDER_REVIEW = 'under_review';
    case AWAITING_GOODS = 'awaiting_goods';
    case GOODS_RECEIVED = 'goods_received';
    case REFUND_PENDING = 'refund_pending';
    case REFUNDED = 'refunded';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function translationKey(): string
    {
        return 'enums.withdrawal_status.'.$this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function badgeColorClasses(): string
    {
        return match ($this) {
            self::SUBMITTED => 'bg-blue-100 text-blue-800',
            self::ACKNOWLEDGED => 'bg-indigo-100 text-indigo-800',
            self::UNDER_REVIEW => 'bg-amber-100 text-amber-800',
            self::AWAITING_GOODS => 'bg-orange-100 text-orange-800',
            self::GOODS_RECEIVED => 'bg-purple-100 text-purple-800',
            self::REFUND_PENDING => 'bg-cyan-100 text-cyan-800',
            self::REFUNDED => 'bg-green-100 text-green-800',
            self::REJECTED => 'bg-red-100 text-red-800',
            self::CANCELLED => 'bg-zinc-100 text-zinc-800',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::REFUNDED,
            self::REJECTED,
            self::CANCELLED,
        ], true);
    }
}
