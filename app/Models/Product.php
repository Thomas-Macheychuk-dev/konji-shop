<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use SoftDeletes;

    public const DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE = 'product_image';
    public const DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE = 'attribute_value_image';

    /**
     * @var list<string>
     */
    public const DEFAULT_IMAGE_TYPES = [
        self::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE,
        self::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE,
    ];

    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'description',
        'status',
        'seo_title',
        'seo_description',
        'published_at',
        'external_source',
        'external_id',
        'external_parent_sku',
        'default_image_type',
        'default_image_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
            'default_image_id' => 'integer',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_attribute_value')
            ->withTimestamps();
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_main', true)
            ->orderBy('id');
    }

    public function attributeValueImages(): HasMany
    {
        return $this->hasMany(ProductAttributeValueImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }


    public function selectedDefaultImage(): ProductImage|ProductAttributeValueImage|null
    {
        $configuredImage = match ($this->default_image_type) {
            self::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE => $this->findProductImageById($this->default_image_id),
            self::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE => $this->findAttributeValueImageById($this->default_image_id),
            default => null,
        };

        return $configuredImage ?? $this->fallbackDefaultImage();
    }

    public function fallbackDefaultImage(): ProductImage|ProductAttributeValueImage|null
    {
        return $this->mainImage
            ?? $this->images->first()
            ?? $this->attributeValueImages->first();
    }

    public function getDefaultImageUrlAttribute(): ?string
    {
        return $this->selectedDefaultImage()?->url;
    }

    public function getMainImageUrlAttribute(): ?string
    {
        return $this->default_image_url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->default_image_url;
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->default_image_url;
    }

    private function findProductImageById(?int $imageId): ?ProductImage
    {
        if ($imageId === null) {
            return null;
        }

        if ($this->relationLoaded('images')) {
            return $this->images->firstWhere('id', $imageId);
        }

        return ProductImage::query()
            ->where('product_id', $this->id)
            ->whereKey($imageId)
            ->first();
    }

    private function findAttributeValueImageById(?int $imageId): ?ProductAttributeValueImage
    {
        if ($imageId === null) {
            return null;
        }

        if ($this->relationLoaded('attributeValueImages')) {
            return $this->attributeValueImages->firstWhere('id', $imageId);
        }

        return ProductAttributeValueImage::query()
            ->where('product_id', $this->id)
            ->whereKey($imageId)
            ->first();
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)
            ->where('is_default', true)
            ->orderByDesc('id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
