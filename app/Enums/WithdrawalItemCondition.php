<?php

declare(strict_types=1);

namespace App\Enums;

enum WithdrawalItemCondition: string
{
    case SEALED = 'sealed';
    case OPENED_UNUSED = 'opened_unused';
    case USED = 'used';
    case DAMAGED = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::SEALED => 'Nienaruszony, w zamkniętym opakowaniu',
            self::OPENED_UNUSED => 'Otwarty, bez śladów używania',
            self::USED => 'Używany',
            self::DAMAGED => 'Uszkodzony',
        };
    }
}
