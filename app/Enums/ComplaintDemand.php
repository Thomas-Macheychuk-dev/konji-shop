<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the consumer asks for when goods do not conform to the contract (art. 43d-43e of the consumer rights act).
 */
enum ComplaintDemand: string
{
    case REPAIR = 'repair';
    case REPLACEMENT = 'replacement';
    case PRICE_REDUCTION = 'price_reduction';
    case WITHDRAWAL = 'withdrawal';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::REPAIR => 'Naprawa',
            self::REPLACEMENT => 'Wymiana',
            self::PRICE_REDUCTION => 'Obniżenie ceny',
            self::WITHDRAWAL => 'Odstąpienie od umowy',
            self::OTHER => 'Inne',
        };
    }
}
