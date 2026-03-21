<?php

declare(strict_types=1);

namespace App\Enums;

enum StockStatus: string
{
    case IN_STOCK = 'in_stock';
    case OUT_OF_STOCK = 'out_of_stock';
    case PREORDER = 'preorder';

    public function translationKey(): string
    {
        return 'enums.stock_status.' . $this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    public function isInStock(): bool
    {
        return $this === self::IN_STOCK;
    }

    public function isOutOfStock(): bool
    {
        return $this === self::OUT_OF_STOCK;
    }

    public function isPreorder(): bool
    {
        return $this === self::PREORDER;
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
