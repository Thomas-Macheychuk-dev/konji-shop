<?php

declare(strict_types=1);

namespace App\Enums;

enum ComplaintChannel: string
{
    case EMAIL = 'email';
    case POST = 'post';
    case PHONE = 'phone';
    case IN_PERSON = 'in_person';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'E-mail',
            self::POST => 'Poczta / list',
            self::PHONE => 'Telefon',
            self::IN_PERSON => 'Osobiście',
            self::OTHER => 'Inny',
        };
    }

    /**
     * Ways of answering a complaint that leave the consumer a copy (paper or another durable medium).
     *
     * @return list<self>
     */
    public static function durableMediumChannels(): array
    {
        return [self::EMAIL, self::POST, self::IN_PERSON];
    }
}
