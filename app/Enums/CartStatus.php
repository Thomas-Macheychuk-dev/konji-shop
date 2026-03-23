<?php

declare(strict_types=1);

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
    case Expired = 'expired';

    public function translationKey(): string
    {
        return 'enums.cart_status.'.$this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
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

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isConverted(): bool
    {
        return $this === self::Converted;
    }

    public function isAbandoned(): bool
    {
        return $this === self::Abandoned;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }
}
