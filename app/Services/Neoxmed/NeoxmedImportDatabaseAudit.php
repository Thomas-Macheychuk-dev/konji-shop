<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;

final class NeoxmedImportDatabaseAudit
{
    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    public function audit(array $map): array
    {
        $products = array_values(array_filter($map['products'] ?? [], 'is_array'));
        $externalIds = [];
        $slugs = [];
        $variantSkus = [];
        $categorySlugs = [];

        foreach ($products as $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $externalId = $this->stringOrNull($product['external_id'] ?? null);
            $slug = $this->stringOrNull($product['slug'] ?? null);

            if ($externalId !== null) {
                $externalIds[] = $externalId;
            }

            if ($slug !== null) {
                $slugs[] = $slug;
            }

            foreach (array_values(array_filter($mapped['variants'] ?? [], 'is_array')) as $variant) {
                $sku = $this->stringOrNull($variant['sku'] ?? null);
                if ($sku !== null) {
                    $variantSkus[] = $sku;
                }
            }

            foreach (array_values(array_filter($mapped['categories'] ?? [], 'is_array')) as $category) {
                $targetSlug = $this->stringOrNull($category['target_slug'] ?? null);
                if ($targetSlug !== null) {
                    $categorySlugs[] = $targetSlug;
                }
            }
        }

        $externalIds = array_values(array_unique($externalIds));
        $slugs = array_values(array_unique($slugs));
        $variantSkus = array_values(array_unique($variantSkus));
        $categorySlugs = array_values(array_unique($categorySlugs));

        $existingNeoxmedProducts = $externalIds === [] ? [] : Product::withTrashed()
            ->where('external_source', 'neoxmed')
            ->whereIn('external_id', $externalIds)
            ->orderBy('external_id')
            ->get(['id', 'external_id', 'slug', 'deleted_at'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'external_id' => $product->external_id,
                'slug' => $product->slug,
                'trashed' => $product->trashed(),
            ])
            ->all();

        $externalIdOverlaps = $externalIds === [] ? [] : Product::withTrashed()
            ->whereIn('external_id', $externalIds)
            ->where(function ($query): void {
                $query->whereNull('external_source')->orWhere('external_source', '!=', 'neoxmed');
            })
            ->orderBy('external_id')
            ->get(['id', 'external_source', 'external_id', 'slug'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'external_source' => $product->external_source,
                'external_id' => $product->external_id,
                'slug' => $product->slug,
            ])
            ->all();

        $slugCollisions = $slugs === [] ? [] : Product::withTrashed()
            ->whereIn('slug', $slugs)
            ->where(function ($query): void {
                $query->whereNull('external_source')->orWhere('external_source', '!=', 'neoxmed');
            })
            ->orderBy('slug')
            ->get(['id', 'external_source', 'external_id', 'slug'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'external_source' => $product->external_source,
                'external_id' => $product->external_id,
                'slug' => $product->slug,
            ])
            ->all();

        $skuCollisions = $variantSkus === [] ? [] : ProductVariant::withTrashed()
            ->whereIn('sku', $variantSkus)
            ->whereHas('product', function ($query): void {
                $query->withTrashed()->where(function ($query): void {
                    $query->whereNull('external_source')->orWhere('external_source', '!=', 'neoxmed');
                });
            })
            ->orderBy('sku')
            ->get(['id', 'product_id', 'sku', 'external_variant_id'])
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'sku' => $variant->sku,
                'external_variant_id' => $variant->external_variant_id,
            ])
            ->all();

        $categoryMatches = $categorySlugs === [] ? [] : Category::withTrashed()
            ->whereIn('slug', $categorySlugs)
            ->orderBy('slug')
            ->get(['id', 'parent_id', 'name', 'slug', 'status', 'deleted_at'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status?->value,
                'trashed' => $category->trashed(),
                'usable' => ! $category->trashed() && $category->status?->value === 'active',
            ])
            ->all();

        $usableCategoryMatches = array_values(array_filter(
            $categoryMatches,
            static fn (array $category): bool => ($category['usable'] ?? false) === true,
        ));
        $matchedCategorySlugs = array_values(array_unique(array_filter(array_column($usableCategoryMatches, 'slug'), 'is_string')));
        $unmatchedCategorySlugs = array_values(array_diff($categorySlugs, $matchedCategorySlugs));
        $errors = [];

        if ($slugCollisions !== []) {
            $errors[] = 'Non-NeoxMed product slug collisions exist.';
        }

        if ($skuCollisions !== []) {
            $errors[] = 'Non-NeoxMed variant SKU collisions exist.';
        }

        if ($unmatchedCategorySlugs !== []) {
            $errors[] = 'Required active Konji target categories are missing, archived, or soft-deleted.';
        }

        return [
            'database_writes' => false,
            'existing_neoxmed_products' => $existingNeoxmedProducts,
            'external_id_overlaps_other_sources' => $externalIdOverlaps,
            'slug_collisions' => $slugCollisions,
            'variant_sku_collisions' => $skuCollisions,
            'required_target_category_slugs' => $categorySlugs,
            'category_matches' => $categoryMatches,
            'unmatched_category_slugs' => $unmatchedCategorySlugs,
            'summary' => [
                'existing_neoxmed_products' => count($existingNeoxmedProducts),
                'external_id_overlaps_other_sources' => count($externalIdOverlaps),
                'slug_collisions' => count($slugCollisions),
                'variant_sku_collisions' => count($skuCollisions),
                'matched_category_slugs' => count($matchedCategorySlugs),
                'unmatched_category_slugs' => count($unmatchedCategorySlugs),
            ],
            'errors' => $errors,
            'safe_for_future_import_implementation' => $errors === [],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
