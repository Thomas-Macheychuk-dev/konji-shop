<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeValue extends Model
{
    protected $fillable = [
        'attribute_id',
        'value',
        'slug',
        'external_option_id',
        'swatch_value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_attribute_value'
        )->withTimestamps();
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductAttributeValueImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function hasSwatch(): bool
    {
        return filled($this->swatch_value);
    }

    public function swatchKind(): ?string
    {
        if (! filled($this->swatch_value)) {
            return null;
        }

        if (str_starts_with($this->swatch_value, '#')) {
            return 'color';
        }

        if (filter_var($this->swatch_value, FILTER_VALIDATE_URL)) {
            return 'image';
        }

        return null;
    }

    public function isColorSwatch(): bool
    {
        return $this->attribute?->display_type?->isColorSwatch() ?? false;
    }
}
