<?php

declare(strict_types=1);

namespace App\Enums;

enum CategoryStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    public function translationKey(): string
    {
        return 'enums.category_status.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this === self::ARCHIVED;
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
