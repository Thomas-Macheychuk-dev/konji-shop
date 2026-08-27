<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentRefundStatus: string
{
    case REQUESTED = 'requested';
    case NEW = 'new';
    case PENDING = 'pending';
    case SUCCESSFUL = 'successful';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, [self::REQUESTED, self::NEW, self::PENDING], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::SUCCESSFUL;
    }

    public function isTerminalFailure(): bool
    {
        return in_array($this, [self::FAILED, self::CANCELLED], true);
    }

    public static function fromPaynow(string $status): self
    {
        return match (strtoupper(trim($status))) {
            'NEW' => self::NEW,
            'PENDING' => self::PENDING,
            'SUCCESSFUL' => self::SUCCESSFUL,
            'FAILED' => self::FAILED,
            'CANCELLED' => self::CANCELLED,
            default => throw new \InvalidArgumentException('Unsupported Paynow refund status.'),
        };
    }
}
