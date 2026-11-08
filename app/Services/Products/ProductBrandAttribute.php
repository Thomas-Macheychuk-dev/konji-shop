<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\AttributeDisplayType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Stores a product's manufacturer as the shared "Producent" attribute, the same attribute the other
 * supplier importers use and Product structured data reads as the brand.
 */
final class ProductBrandAttribute
{
    public const ATTRIBUTE_NAME = 'Producent';

    /**
     * Attach the brand unless the product already has a manufacturer. Returns whether it was attached.
     */
    public function attach(Product $product, string $brand): bool
    {
        $brand = trim($brand);

        if ($brand === '' || $this->hasBrand($product)) {
            return false;
        }

        $product->attributeValues()->syncWithoutDetaching([$this->value($brand)->id]);
        $product->unsetRelation('attributeValues');

        return true;
    }

    /**
     * For importers that replace all product attributes: when the attributes they sync carry no brand,
     * keep the brand the product already has, e.g. one catalogue:assign-missing-brands attached.
     *
     * @param  array<int, array<string, mixed>>  $syncIds  attribute value id => pivot data
     * @return array<int, array<string, mixed>>
     */
    public function keepingBrand(Product $product, array $syncIds): array
    {
        $isBrand = fn ($query) => $query->whereHas('attribute', fn ($query) => $query->whereIn('slug', ['producent', 'marka']));

        if ($syncIds !== [] && AttributeValue::query()->whereKey(array_keys($syncIds))->where($isBrand)->exists()) {
            return $syncIds;
        }

        foreach ($product->attributeValues()->where($isBrand)->pluck('attribute_values.id') as $brandValueId) {
            $syncIds[(int) $brandValueId] ??= [];
        }

        return $syncIds;
    }

    public function hasBrand(Product $product): bool
    {
        return $product->attributeValues()
            ->whereHas('attribute', fn ($query) => $query->whereIn('slug', ['producent', 'marka']))
            ->exists();
    }

    /**
     * The "Producent" value for a brand, created when missing.
     */
    public function value(string $brand): AttributeValue
    {
        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => Str::slug(self::ATTRIBUTE_NAME)],
            ['name' => self::ATTRIBUTE_NAME, 'external_attribute_id' => null, 'display_type' => AttributeDisplayType::SELECT],
        );

        return AttributeValue::query()->firstOrCreate(
            ['attribute_id' => $attribute->id, 'slug' => Str::slug($brand) ?: 'producent-'.substr(sha1($brand), 0, 10)],
            ['value' => Str::limit($brand, 190, ''), 'external_option_id' => null, 'sort_order' => 0],
        );
    }
}
