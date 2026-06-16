<?php

declare(strict_types=1);

namespace App\Services\TwojaPeruka;

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

final class TwojaPerukaProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(
        array $scraped,
        ProductStatus $status = ProductStatus::DRAFT,
        bool $importImages = true,
    ): array {
        $this->warnings = [];

        $product = DB::transaction(function () use ($scraped, $status, $importImages): Product {
            $externalId = $this->externalProductId($scraped);
            $product = $this->resolveProduct($scraped, $externalId, $status);

            $this->syncCategories($product, $scraped);
            $this->syncDefaultVariant($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages($product, $scraped);
            }

            return $product->fresh([
                'categories.parent',
                'images',
                'variants.attributeValues.attribute',
            ]);
        });

        return [
            'product' => $product,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function resolveProduct(array $scraped, string $externalId, ProductStatus $status): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'twojaperuka')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: Str::slug((string) ($scraped['name'] ?? 'twojaperuka-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'twojaperuka-product-'.$externalId;
        }

        $attributes = [
            'name' => $this->stringOrNull($scraped['name'] ?? null) ?: 'TwojaPeruka product '.$externalId,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->cleanHtml(
                $this->stringOrNull($scraped['short_description_html'] ?? null)
                ?: $this->stringOrNull($scraped['short_description'] ?? null)
            ),
            'description' => $this->cleanHtml(
                $this->stringOrNull($scraped['description_html'] ?? null)
                ?: $this->stringOrNull($scraped['description'] ?? null)
            ),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null),
            'seo_description' => $this->stringOrNull($scraped['seo_description'] ?? null),
            'status' => $status,
            'external_source' => 'twojaperuka',
            'external_id' => $externalId,
            'external_parent_sku' => $this->stringOrNull($scraped['canonical_url'] ?? null)
                ?: $this->stringOrNull($scraped['source_url'] ?? null),
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
     * @param  array<string, mixed>  $scraped
     */
    private function syncCategories(Product $product, array $scraped): void
    {
        $categoryNames = $this->categoryNames($scraped);

        if ($categoryNames === []) {
            return;
        }

        $parent = null;
        $resolved = [];

        foreach ($categoryNames as $categoryName) {
            $parent = $this->resolveCategory($categoryName, $parent);
            $resolved[] = $parent;
        }

        if ($resolved === []) {
            return;
        }

        $leafCategory = end($resolved);
        $syncPayload = [];

        foreach ($resolved as $category) {
            $syncPayload[$category->id] = [
                'is_primary' => $category->is($leafCategory),
            ];
        }

        $product->categories()->sync($syncPayload);
    }

    private function resolveCategory(string $name, ?Category $parent): Category
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'twojaperuka-category-'.substr(sha1($name), 0, 12);
        }

        $category = Category::withTrashed()->where('slug', $slug)->first();

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
            'slug' => $slug,
        ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncDefaultVariant(Product $product, array $scraped, ProductStatus $status): void
    {
        $externalVariantId = 'twojaperuka-'.$product->external_id.'-default';
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = VatRate::VAT_8;

        $variant = ProductVariant::updateOrCreate(
            [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
            ],
            [
                'sku' => $this->uniqueSku(
                    $this->stringOrNull($scraped['sku'] ?? null) ?: 'TWOJAPERUKA-'.$product->external_id,
                    $product->id,
                    $externalVariantId,
                ),
                'status' => $this->variantStatusForProductStatus($status),
                'price_net_amount' => $grossAmount === null ? null : $vatRate->netFromGross($grossAmount),
                'price_gross_amount' => $grossAmount,
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($scraped),
                'is_default' => true,
            ]
        );

        $variant->attributeValues()->sync([]);

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('id', '!=', $variant->id)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(Product $product, array $scraped): void
    {
        $imageRows = [];
        $seenUrls = [];

        foreach (($scraped['images'] ?? []) as $imageData) {
            if (! is_array($imageData)) {
                continue;
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/twojaperuka/'.$product->external_id.'/gallery',
                    'public',
                    ['twojaperuka.pl']
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $alt = $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name;

            $imageRows[] = [
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'alt_text' => $alt,
                'title' => $alt,
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
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function categoryNames(array $scraped): array
    {
        $names = [];

        foreach (($scraped['categories'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        if ($names === []) {
            $category = $this->stringOrNull($scraped['category'] ?? null);

            if ($category !== null) {
                $names[] = $category;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function stockStatus(array $scraped): StockStatus
    {
        $availability = Str::of((string) ($scraped['availability'] ?? ''))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        $label = Str::of((string) ($scraped['availability_label'] ?? ''))
            ->lower()
            ->ascii()
            ->value();

        if (in_array($availability, ['out_of_stock', 'unavailable', 'sold_out', 'not_available'], true)
            || Str::of($label)->contains(['brak', 'niedostepn', 'wyprzedan'])) {
            return StockStatus::OUT_OF_STOCK;
        }

        return StockStatus::IN_STOCK;
    }

    private function variantStatusForProductStatus(ProductStatus $status): ProductVariantStatus
    {
        return match ($status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null)
            ?: $this->stringOrNull($scraped['external_id'] ?? null)
                ?: $this->stringOrNull($scraped['sku'] ?? null)
                    ?: $this->stringOrNull($scraped['slug'] ?? null);

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $sourceUrl = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: (string) ($scraped['name'] ?? 'twojaperuka-product');

        return substr(sha1($sourceUrl), 0, 32);
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'twojaperuka-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-twojaperuka-'.$externalId;
            } else {
                $candidate = $baseSlug.'-twojaperuka-'.$externalId.'-'.$suffix;
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

    private function uniqueSku(string $sku, int $productId, string $externalVariantId): string
    {
        $sku = Str::upper(Str::slug($sku, '-')) ?: 'TWOJAPERUKA-'.$externalVariantId;
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
            $candidate = $sku.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round(((float) $value) * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? $normalized;

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = end($parts);

        return is_string($slug) && $slug !== '' ? Str::slug($slug) : null;
    }

    private function cleanHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*\/?>/isu', '', $html) ?? $html;
        $html = preg_replace_callback(
            '/<a\b[^>]*>(.*?)<\/a>/isu',
            fn (array $matches): string => $matches[1],
            $html
        ) ?? $html;
        $html = preg_replace('/(?<!["\'=])https?:\/\/[^\s<>"\']+/iu', '', $html) ?? $html;
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
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

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
