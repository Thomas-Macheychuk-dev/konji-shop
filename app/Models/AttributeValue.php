<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class AttributeValue extends Model
{
    protected $fillable = [
        'attribute_id',
        'value',
        'slug',
        'external_option_id',
        'swatch_type',
        'swatch_value',
        'swatch_image_disk',
        'swatch_image_path',
        'swatch_source_url',
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

    public function productImage(): HasOne
    {
        return $this->hasOne(ProductAttributeValueImage::class)
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function hasSwatch(): bool
    {
        return filled($this->swatch_value) || filled($this->swatch_image_path);
    }

    public function swatchKind(): ?string
    {
        if (filled($this->swatch_image_path)) {
            return 'image';
        }

        if (filled($this->swatch_value) && str_starts_with($this->swatch_value, '#')) {
            return 'color';
        }

        return null;
    }

    public function getSwatchImageUrlAttribute(): ?string
    {
        if (! $this->swatch_image_disk || ! $this->swatch_image_path) {
            return null;
        }

        return Storage::disk($this->swatch_image_disk)->url($this->swatch_image_path);
    }

    public function getProductImageUrlAttribute(): ?string
    {
        return $this->productImage?->url;
    }
}
