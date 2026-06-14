<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PerukaProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * @param  array<string, mixed>  $productData
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(
        array $productData,
        ProductStatus $productStatus = ProductStatus::DRAFT,
        bool $importImages = true,
    ): array {
        $this->warnings = [];

        $product = DB::transaction(function () use ($productData, $productStatus, $importImages): Product {
            $externalId = $this->externalProductId($productData);
            $product = $this->resolveProduct($productData, $externalId, $productStatus);

            $this->syncCategories($product, $productData);
            $this->syncDefaultVariant($product, $productData);

            if ($importImages) {
                $this->syncImages($product, $productData);
            }

            return $product->fresh([
                'categories.parent',
                'images',
                'variants',
            ]);
        });

        return [
            'product' => $product,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function resolveProduct(array $productData, string $externalId, ProductStatus $productStatus): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'peruka')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($productData['slug'] ?? null)
            ?: Str::slug((string) ($productData['name'] ?? 'peruka-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'peruka-product-'.$externalId;
        }

        $descriptionHtml = $this->stringOrNull($productData['description_html'] ?? null);
        $shortDescriptionHtml = $this->stringOrNull($productData['short_description_html'] ?? null);
        $shortDescription = $this->stringOrNull($productData['short_description'] ?? null);

        $attributes = [
            'name' => $this->stringOrNull($productData['name'] ?? null) ?: 'Peruka product '.$externalId,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $shortDescriptionHtml ?: $shortDescription,
            'description' => $descriptionHtml,
            'seo_title' => $this->stringOrNull($productData['seo_title'] ?? null),
            'seo_description' => $this->stringOrNull($productData['seo_description'] ?? null),
            'status' => $productStatus,
            'external_source' => 'peruka',
            'external_id' => $externalId,
            'external_parent_sku' => $this->stringOrNull($productData['canonical_url'] ?? null)
                ?: $this->stringOrNull($productData['source_url'] ?? null),
        ];

        if ($product !== null) {
            if ($product->trashed()) {
                $product->restore();
            }

            $product->update($attributes);

            return $product;
        }

        return Product::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function syncCategories(Product $product, array $productData): void
    {
        $categoryNames = array_values(array_filter(
            array_map(fn (mixed $value): ?string => $this->stringOrNull($value), is_array($productData['categories'] ?? null) ? $productData['categories'] : []),
        ));

        if ($categoryNames === []) {
            $category = $this->stringOrNull($productData['category'] ?? null);
            $categoryNames = $category !== null ? [$category] : [];
        }

        if ($categoryNames === []) {
            return;
        }

        $parent = null;
        $categoryIds = [];

        foreach ($categoryNames as $index => $categoryName) {
            $category = $this->resolveCategory($categoryName, $parent, array_slice($categoryNames, 0, $index + 1));
            $categoryIds[] = $category->id;
            $parent = $category;
        }

        $primaryCategoryId = end($categoryIds);

        if (! is_int($primaryCategoryId)) {
            return;
        }

        $sync = [];

        foreach ($categoryIds as $categoryId) {
            $sync[$categoryId] = ['is_primary' => $categoryId === $primaryCategoryId];
        }

        $product->categories()->sync($sync);
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'peruka-category-'.substr(sha1(implode('|', $pathSegments)), 0, 10);
        }

        $category = Category::withTrashed()->where('slug', $slug)->first();

        if ($category !== null && $parent !== null && $category->parent_id !== null && $category->parent_id !== $parent->id) {
            $pathSlug = Str::slug(implode(' ', $pathSegments));
            $category = Category::withTrashed()->where('slug', $pathSlug)->first();
            $slug = $pathSlug ?: $slug;
        }

        $attributes = [
            'parent_id' => $parent?->id,
            'name' => $name,
            'status' => CategoryStatus::ACTIVE,
        ];

        if ($category !== null) {
            if ($category->trashed()) {
                $category->restore();
            }

            $category->update($attributes);

            return $category;
        }

        return Category::query()->create($attributes + [
            'slug' => $this->uniqueCategorySlug($slug),
        ]);
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function syncDefaultVariant(Product $product, array $productData): void
    {
        $externalVariantId = 'peruka-'.$product->external_id.'-default';
        $vatRate = $this->vatRateForProduct($productData);
        $grossAmount = $this->grossAmountMinor($productData['price_gross_amount'] ?? null);

        $variant = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->where('external_variant_id', $externalVariantId)
            ->first();

        $attributes = [
            'product_id' => $product->id,
            'external_variant_id' => $externalVariantId,
            'sku' => $this->uniqueSku($this->skuForProduct($product, $productData), $product->id, $externalVariantId),
            'status' => $this->variantStatusForProduct($productData),
            'price_net_amount' => $grossAmount !== null ? $vatRate->netFromGross($grossAmount) : null,
            'price_gross_amount' => $grossAmount,
            'currency' => Currency::PLN,
            'vat_rate' => $vatRate,
            'stock_status' => $this->stockStatusForProduct($productData),
            'is_default' => true,
        ];

        if ($variant !== null) {
            if ($variant->trashed()) {
                $variant->restore();
            }

            $variant->update($attributes);
        } else {
            $variant = ProductVariant::query()->create($attributes);
        }

        $variant->attributeValues()->sync([]);

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereKeyNot($variant->id)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function syncImages(Product $product, array $productData): void
    {
        $imageRows = [];
        $seen = [];

        foreach (($productData['images'] ?? []) as $imageData) {
            $url = is_array($imageData)
                ? $this->stringOrNull($imageData['url'] ?? null)
                : $this->stringOrNull($imageData);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/peruka/'.$product->external_id.'/gallery',
                    'public',
                    ['peruka.pl', 'www.peruka.pl']
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for Peruka product '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $altText = is_array($imageData)
                ? $this->stringOrNull($imageData['alt'] ?? null)
                : null;

            $imageRows[] = [
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'alt_text' => $altText ?: $product->name,
                'title' => $altText ?: $product->name,
                'sort_order' => count($imageRows),
                'is_main' => count($imageRows) === 0,
            ];
        }

        $paths = array_column($imageRows, 'path');

        ProductImage::query()
            ->where('product_id', $product->id)
            ->when(
                $paths !== [],
                fn ($query) => $query->whereNotIn('path', $paths),
                fn ($query) => $query
            )
            ->delete();

        foreach ($imageRows as $row) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'path' => $row['path'],
                ],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $row['alt_text'],
                    'title' => $row['title'],
                    'is_main' => $row['is_main'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function externalProductId(array $productData): string
    {
        $externalId = $this->stringOrNull($productData['external_product_id'] ?? null)
            ?: $this->stringOrNull($productData['sku'] ?? null)
                ?: $this->stringOrNull($productData['slug'] ?? null);

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $sourceUrl = $this->stringOrNull($productData['canonical_url'] ?? null)
            ?: $this->stringOrNull($productData['source_url'] ?? null)
                ?: (string) ($productData['name'] ?? 'peruka-product');

        return substr(sha1($sourceUrl), 0, 40);
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'peruka-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-peruka-'.$externalId;
            } else {
                $candidate = $baseSlug.'-peruka-'.$externalId.'-'.$suffix;
            }

            $suffix++;
        }

        return $candidate;
    }

    private function productSlugExists(string $slug, ?int $currentProductId): bool
    {
        return Product::withTrashed()
            ->where('slug', $slug)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists();
    }

    private function uniqueCategorySlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'peruka-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function skuForProduct(Product $product, array $productData): string
    {
        $sku = $this->stringOrNull($productData['sku'] ?? null)
            ?: $this->stringOrNull($productData['external_product_id'] ?? null)
                ?: $product->external_id;

        return $sku ?: 'PERUKA-'.$product->external_id;
    }

    private function uniqueSku(string $sku, int $productId, string $externalVariantId): string
    {
        $sku = Str::upper(Str::slug($sku, '-')) ?: 'PERUKA-'.$externalVariantId;
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->where(function ($query) use ($productId, $externalVariantId): void {
                $query
                    ->where('product_id', '!=', $productId)
                    ->orWhere('external_variant_id', '!=', $externalVariantId);
            })
            ->exists()) {
            if ($suffix === 2) {
                $candidate = $sku.'-PERUKA-'.$externalVariantId;
            } else {
                $candidate = $sku.'-PERUKA-'.$externalVariantId.'-'.$suffix;
            }

            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function vatRateForProduct(array $productData): VatRate
    {
        return filter_var($productData['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? VatRate::VAT_8
            : VatRate::VAT_23;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function stockStatusForProduct(array $productData): StockStatus
    {
        $availability = Str::of((string) ($productData['availability'] ?? ''))->lower()->ascii()->value();
        $stockQuantity = $this->integerOrNull($productData['stock_quantity'] ?? null);

        if ($availability === 'out_of_stock' || $stockQuantity === 0) {
            return StockStatus::OUT_OF_STOCK;
        }

        if ($availability === 'preorder') {
            return StockStatus::PREORDER;
        }

        return StockStatus::IN_STOCK;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function variantStatusForProduct(array $productData): ProductVariantStatus
    {
        return ProductVariantStatus::ACTIVE;
    }

    private function grossAmountMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(',', '.', $value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (! is_string($value) || trim($value) === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= self::MAX_DATABASE_STRING_LENGTH) {
            return $value;
        }

        $hash = substr(sha1($value), 0, 10);
        $prefixLength = self::MAX_DATABASE_STRING_LENGTH - mb_strlen($hash) - 1;

        return rtrim(mb_substr($value, 0, max(1, $prefixLength)), '-_ .').'_'.$hash;
    }
}
