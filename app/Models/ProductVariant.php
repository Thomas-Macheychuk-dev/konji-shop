<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'status',
        'price_net_amount',
        'price_gross_amount',
        'currency',
        'vat_rate',
        'stock_status',
        'is_default',
        'external_variant_id',
        'package_weight_grams',
        'package_length_mm',
        'package_width_mm',
        'package_height_mm',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductVariantStatus::class,
            'currency' => Currency::class,
            'vat_rate' => VatRate::class,
            'stock_status' => StockStatus::class,
            'is_default' => 'boolean',
            'price_gross_amount' => 'integer',
            'deleted_at' => 'datetime',
            'package_weight_grams' => 'integer',
            'package_length_mm' => 'integer',
            'package_width_mm' => 'integer',
            'package_height_mm' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'product_variant_attribute_value'
        )->withTimestamps();
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'product_variant_id');
    }

    public function grossPriceAmount(): ?int
    {
        if ($this->price_gross_amount !== null) {
            return $this->price_gross_amount;
        }

        if ($this->price_net_amount === null || $this->vat_rate === null) {
            return null;
        }

        return $this->vat_rate->grossFromNet($this->price_net_amount);
    }

    public function vatAmount(): ?int
    {
        if ($this->price_net_amount === null || $this->vat_rate === null) {
            return null;
        }

        if ($this->price_gross_amount !== null) {
            return $this->price_gross_amount - $this->price_net_amount;
        }

        return $this->vat_rate->vatAmountFromNet($this->price_net_amount);
    }

    public function getMainImageAttribute(): ?ProductAttributeValueImage
    {
        $attributeValueIds = $this->relationLoaded('attributeValues')
            ? $this->attributeValues->pluck('id')->all()
            : $this->attributeValues()->pluck('attribute_values.id')->all();

        if ($attributeValueIds === []) {
            return null;
        }

        return ProductAttributeValueImage::query()
            ->where('product_id', $this->product_id)
            ->whereIn('attribute_value_id', $attributeValueIds)
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function getMainImageUrlAttribute(): ?string
    {
        return $this->main_image?->url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->main_image_url;
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->main_image_url;
    }

    public function hasCompletePackageDimensions(): bool
    {
        return $this->package_weight_grams !== null
            && $this->package_length_mm !== null
            && $this->package_width_mm !== null
            && $this->package_height_mm !== null;
    }

    public function packageWeightKg(): ?float
    {
        return $this->package_weight_grams === null
            ? null
            : round($this->package_weight_grams / 1000, 3);
    }

    public function packageLengthCm(): ?int
    {
        return $this->package_length_mm === null
            ? null
            : (int) ceil($this->package_length_mm / 10);
    }

    public function packageWidthCm(): ?int
    {
        return $this->package_width_mm === null
            ? null
            : (int) ceil($this->package_width_mm / 10);
    }

    public function packageHeightCm(): ?int
    {
        return $this->package_height_mm === null
            ? null
            : (int) ceil($this->package_height_mm / 10);
    }
}
