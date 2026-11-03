<?php

declare(strict_types=1);

namespace App\Enums;

enum ComplaintStatus: string
{
    case RECEIVED = 'received';
    case IN_REVIEW = 'in_review';
    case ACCEPTED = 'accepted';
    case PARTIALLY_ACCEPTED = 'partially_accepted';
    case REJECTED = 'rejected';
    case CLOSED = 'closed';

    public function translationKey(): string
    {
        return 'enums.complaint_status.'.$this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function badgeColorClasses(): string
    {
        return match ($this) {
            self::RECEIVED => 'bg-blue-100 text-blue-800',
            self::IN_REVIEW => 'bg-amber-100 text-amber-800',
            self::ACCEPTED => 'bg-green-100 text-green-800',
            self::PARTIALLY_ACCEPTED => 'bg-teal-100 text-teal-800',
            self::REJECTED => 'bg-red-100 text-red-800',
            self::CLOSED => 'bg-zinc-100 text-zinc-800',
        };
    }

    /**
     * Statuses that record a decision sent to the customer, so they need a response date.
     */
    public function isAnswered(): bool
    {
        return in_array($this, [
            self::ACCEPTED,
            self::PARTIALLY_ACCEPTED,
            self::REJECTED,
            self::CLOSED,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function answered(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isAnswered()));
    }
}
