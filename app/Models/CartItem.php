<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'product_variant_id',
        'configuration_hash',
        'quantity',
        'unit_price',
        'currency',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'meta' => 'array',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return list<array{group_code?: string, group_label?: string, value_id?: int, value_label?: string}>
     */
    public function selectedOptions(): array
    {
        $options = data_get($this->meta, 'selected_options', []);

        return is_array($options) ? array_values($options) : [];
    }

    public function selectedOptionsLabel(): ?string
    {
        $label = collect($this->selectedOptions())
            ->map(function (array $option): ?string {
                $group = trim((string) ($option['group_label'] ?? ''));
                $value = trim((string) ($option['value_label'] ?? ''));

                if ($group === '' || $value === '') {
                    return null;
                }

                return "{$group}: {$value}";
            })
            ->filter()
            ->implode(', ');

        return $label !== '' ? $label : null;
    }

    public function currentUnitPriceAmount(): ?int
    {
        return $this->variant?->grossPriceAmount();
    }

    public function currentLineTotalAmount(): ?int
    {
        $unitPrice = $this->currentUnitPriceAmount();

        if ($unitPrice === null) {
            return null;
        }

        return $unitPrice * $this->quantity;
    }
}
