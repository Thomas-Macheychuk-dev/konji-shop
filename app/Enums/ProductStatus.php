<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    public function translationKey(): string
    {
        return 'enums.product_status.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
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
