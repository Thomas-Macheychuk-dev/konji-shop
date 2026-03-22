<?php

declare(strict_types=1);

namespace App\Enums;

enum AttributeDisplayType: string
{
    case SELECT = 'select';
    case RADIO = 'radio';
    case COLOR_SWATCH = 'color_swatch';
    case TEXT = 'text';

    public function translationKey(): string
    {
        return 'enums.attribute_display_type.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function isSelect(): bool
    {
        return $this === self::SELECT;
    }

    public function isRadio(): bool
    {
        return $this === self::RADIO;
    }

    public function isColorSwatch(): bool
    {
        return $this === self::COLOR_SWATCH;
    }

    public function isText(): bool
    {
        return $this === self::TEXT;
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
