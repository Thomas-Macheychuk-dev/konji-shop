<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductPageCacheVersion
{
    /**
     * Return the complete public product-page cache version using one database
     * round trip. Each scalar subquery remains indexable by product/variant id,
     * while avoiding the previous sequence of independent aggregate queries.
     *
     * @return array<string, mixed>
     */
    public function for(Product $product): array
    {
        $productId = (int) $product->getKey();

        $row = DB::table('products')
            ->where('products.id', $productId)
            ->select([
                'products.updated_at as product',
                'products.deleted_at as product_deleted',
            ])
            ->selectSub(
                DB::table('product_images')
                    ->whereColumn('product_images.product_id', 'products.id')
                    ->selectRaw('MAX(product_images.updated_at)'),
                'images',
            )
            ->selectSub(
                DB::table('product_attribute_value_images')
                    ->whereColumn('product_attribute_value_images.product_id', 'products.id')
                    ->selectRaw('MAX(product_attribute_value_images.updated_at)'),
                'attribute_value_images',
            )
            ->selectSub(
                DB::table('product_attribute_value')
                    ->whereColumn('product_attribute_value.product_id', 'products.id')
                    ->selectRaw('MAX(product_attribute_value.updated_at)'),
                'product_attribute_value_pivot',
            )
            ->selectSub(
                DB::table('attribute_values')
                    ->join(
                        'product_attribute_value',
                        'product_attribute_value.attribute_value_id',
                        '=',
                        'attribute_values.id',
                    )
                    ->whereColumn('product_attribute_value.product_id', 'products.id')
                    ->selectRaw('MAX(attribute_values.updated_at)'),
                'product_attribute_values',
            )
            ->selectSub(
                DB::table('attributes')
                    ->join('attribute_values', 'attribute_values.attribute_id', '=', 'attributes.id')
                    ->join(
                        'product_attribute_value',
                        'product_attribute_value.attribute_value_id',
                        '=',
                        'attribute_values.id',
                    )
                    ->whereColumn('product_attribute_value.product_id', 'products.id')
                    ->selectRaw('MAX(attributes.updated_at)'),
                'product_attributes',
            )
            ->selectSub(
                DB::table('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->selectRaw('MAX(product_variants.updated_at)'),
                'variants',
            )
            ->selectSub(
                DB::table('product_variant_attribute_value')
                    ->join(
                        'product_variants',
                        'product_variants.id',
                        '=',
                        'product_variant_attribute_value.product_variant_id',
                    )
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->selectRaw('MAX(product_variant_attribute_value.updated_at)'),
                'variant_values',
            )
            ->selectSub(
                DB::table('attribute_values')
                    ->join(
                        'product_variant_attribute_value',
                        'product_variant_attribute_value.attribute_value_id',
                        '=',
                        'attribute_values.id',
                    )
                    ->join(
                        'product_variants',
                        'product_variants.id',
                        '=',
                        'product_variant_attribute_value.product_variant_id',
                    )
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->selectRaw('MAX(attribute_values.updated_at)'),
                'attribute_values',
            )
            ->selectSub(
                DB::table('attributes')
                    ->join('attribute_values', 'attribute_values.attribute_id', '=', 'attributes.id')
                    ->join(
                        'product_variant_attribute_value',
                        'product_variant_attribute_value.attribute_value_id',
                        '=',
                        'attribute_values.id',
                    )
                    ->join(
                        'product_variants',
                        'product_variants.id',
                        '=',
                        'product_variant_attribute_value.product_variant_id',
                    )
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->selectRaw('MAX(attributes.updated_at)'),
                'attributes',
            )
            ->selectSub(
                DB::table('category_product')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->selectRaw('MAX(category_product.updated_at)'),
                'category_product',
            )
            ->selectSub(
                DB::table('categories')
                    ->join('category_product', 'category_product.category_id', '=', 'categories.id')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->selectRaw('MAX(categories.updated_at)'),
                'categories',
            )
            ->first();

        if ($row === null) {
            throw new RuntimeException('Cannot build a storefront cache version for a missing product.');
        }

        return collect((array) $row)
            ->map(fn (mixed $value): ?string => $this->timestampForCache($value))
            ->all();
    }

    private function timestampForCache(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('U.u');
        }

        return $value === null ? null : (string) $value;
    }
}
