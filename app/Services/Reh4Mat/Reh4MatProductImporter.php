<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

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

final class Reh4MatProductImporter
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
     * @param  array<string, mixed>  $scraped
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(
        array $scraped,
        ProductStatus $status = ProductStatus::DRAFT,
        bool $importImages = true,
        ?int $imageLimit = 10,
    ): array {
        $this->warnings = [];

        $product = DB::transaction(function () use ($scraped, $status, $importImages, $imageLimit): Product {
            $externalId = $this->externalProductId($scraped);
            $product = $this->resolveProduct($scraped, $externalId, $status);

            $this->syncCategories($product, $scraped);
            $this->syncDefaultVariant($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages($product, $scraped, $imageLimit);
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
            ->where('external_source', 'reh4mat')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'reh4mat-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'reh4mat-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'Reh4Mat product '.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->stringOrNull($scraped['seo_description'] ?? null)
                ?: $this->plainTextSnippet($scraped['description'] ?? $scraped['short_description'] ?? null, 300),
            'status' => $status,
            'external_source' => 'reh4mat',
            'external_id' => $externalId,
            'external_parent_sku' => $this->limitNullableDatabaseString(
                $this->stringOrNull($scraped['canonical_url'] ?? null)
                ?: $this->stringOrNull($scraped['source_url'] ?? null)
            ),
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
            $slug = 'reh4mat-category-'.substr(sha1($name), 0, 12);
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
        $externalVariantId = 'reh4mat-'.$product->external_id.'-default';
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = VatRate::VAT_8;

        $variant = ProductVariant::updateOrCreate(
            [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
            ],
            [
                'sku' => $this->uniqueSku(
                    $this->stringOrNull($scraped['sku'] ?? null) ?: 'REH4MAT-'.$product->external_id,
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
    private function syncImages(Product $product, array $scraped, ?int $imageLimit): void
    {
        $imageRows = [];
        $seenUrls = [];
        $maxImages = $imageLimit !== null && $imageLimit > 0 ? $imageLimit : null;

        foreach (($scraped['images'] ?? []) as $imageData) {
            if (! is_array($imageData)) {
                continue;
            }

            if ($maxImages !== null && count($imageRows) >= $maxImages) {
                break;
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/reh4mat/'.$product->external_id.'/gallery',
                    'public',
                    ['reh4mat.com', 'stabilobedsystem.pl']
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

        foreach (($scraped['source_category_path'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        foreach (($scraped['categories'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        if ($names === []) {
            $category = $this->stringOrNull($scraped['category'] ?? null)
                ?: $this->stringOrNull($scraped['source_category_name'] ?? null)
                    ?: $this->stringOrNull($scraped['source_top_category_name'] ?? null);

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
                ?: (string) ($scraped['name'] ?? 'reh4mat-product');

        return substr(sha1($sourceUrl), 0, 32);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $short = $this->stringOrNull($scraped['short_description_html'] ?? null)
            ?: $this->stringOrNull($scraped['short_description'] ?? null);

        if ($short === null) {
            return null;
        }

        if (! str_contains($short, '<')) {
            $short = '<p>'.e($this->plainTextSnippet($short, 500) ?? $short).'</p>';
        }

        return $this->cleanHtml($short);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped): ?string
    {
        $sections = [];
        $mainHtml = $this->stringOrNull($scraped['description_html'] ?? null);

        if ($mainHtml === null && $this->stringOrNull($scraped['description'] ?? null) !== null) {
            $mainHtml = '<p>'.e((string) $scraped['description']).'</p>';
        }

        if ($mainHtml !== null) {
            $sections[] = $mainHtml;
        }

        $sections = array_merge($sections, $this->tabSections($scraped));

        $metaSection = $this->metadataSection($scraped);
        if ($metaSection !== null) {
            $sections[] = $metaSection;
        }

        $pictogramSection = $this->pictogramSection($scraped);
        if ($pictogramSection !== null) {
            $sections[] = $pictogramSection;
        }

        $regulatorySection = $this->regulatoryIconSection($scraped);
        if ($regulatorySection !== null) {
            $sections[] = $regulatorySection;
        }

        $downloadsSection = $this->downloadsSection($scraped);
        if ($downloadsSection !== null) {
            $sections[] = $downloadsSection;
        }

        $medicalNotice = $this->stringOrNull($scraped['medical_device_notice'] ?? null);
        if ($medicalNotice !== null) {
            $sections[] = '<section class="reh4mat-medical-notice"><p><strong>'.e($medicalNotice).'</strong></p></section>';
        }

        if ($sections === []) {
            return null;
        }

        return $this->cleanHtml(implode("\n", $sections));
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function tabSections(array $scraped): array
    {
        $sections = [];

        foreach (($scraped['tabs'] ?? []) as $tab) {
            if (! is_array($tab)) {
                continue;
            }

            $title = $this->stringOrNull($tab['title'] ?? null);
            $html = $this->stringOrNull($tab['html'] ?? null);

            if ($title === null || $html === null || Str::of($title)->lower()->ascii()->trim()->value() === 'opis') {
                continue;
            }

            $sections[] = '<section class="reh4mat-tab"><h2>'.e($title).'</h2>'.$html.'</section>';
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function metadataSection(array $scraped): ?string
    {
        $rows = [];

        foreach (($scraped['product_meta'] ?? []) as $key => $value) {
            $key = $this->stringOrNull($key);
            $value = $this->stringOrNull($value);

            if ($key !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($key).'</th><td>'.e($value).'</td></tr>';
            }
        }

        foreach (($scraped['codes'] ?? []) as $codeType => $values) {
            $codeType = $this->stringOrNull($codeType);
            $values = is_array($values) ? $this->stringList($values) : [];

            if ($codeType !== null && $values !== []) {
                $rows[] = '<tr><th>'.e($codeType).'</th><td>'.e(implode(', ', $values)).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="reh4mat-product-meta"><h2>Dane produktu</h2><table><tbody>'.implode('', $rows).'</tbody></table></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function pictogramSection(array $scraped): ?string
    {
        $items = [];

        foreach (($scraped['pictograms'] ?? []) as $pictogram) {
            if (! is_array($pictogram)) {
                continue;
            }

            $label = $this->stringOrNull($pictogram['label'] ?? null);
            $description = $this->stringOrNull($pictogram['description'] ?? null);

            if ($label === null) {
                continue;
            }

            $items[] = '<li><strong>'.e($label).'</strong>'.($description === null ? '' : ' — '.e($description)).'</li>';
        }

        if ($items === []) {
            return null;
        }

        return '<section class="reh4mat-pictograms"><h2>Piktogramy produktu</h2><ul>'.implode('', $items).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function regulatoryIconSection(array $scraped): ?string
    {
        $items = [];

        foreach (($scraped['regulatory_icons'] ?? []) as $icon) {
            if (! is_array($icon)) {
                continue;
            }

            $label = $this->stringOrNull($icon['label'] ?? null);
            $description = $this->stringOrNull($icon['description'] ?? null);

            if ($label === null) {
                continue;
            }

            $items[] = '<li><strong>'.e($label).'</strong>'.($description === null ? '' : ' — '.e($description)).'</li>';
        }

        if ($items === []) {
            return null;
        }

        return '<section class="reh4mat-regulatory-icons"><h2>Oznaczenia medyczne</h2><ul>'.implode('', $items).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function downloadsSection(array $scraped): ?string
    {
        $items = [];

        foreach (($scraped['downloads'] ?? []) as $download) {
            if (! is_array($download)) {
                continue;
            }

            $label = $this->stringOrNull($download['label'] ?? null);
            $url = $this->stringOrNull($download['url'] ?? null);

            if ($label === null) {
                continue;
            }

            if ($url !== null && filter_var($url, FILTER_VALIDATE_URL)) {
                $items[] = '<li><a href="'.e($url).'" rel="nofollow noopener" target="_blank">'.e($label).'</a></li>';
            } else {
                $items[] = '<li>'.e($label).'</li>';
            }
        }

        if ($items === []) {
            return null;
        }

        return '<section class="reh4mat-downloads"><h2>Do pobrania</h2><ul>'.implode('', $items).'</ul></section>';
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'reh4mat-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-reh4mat-'.$externalId;
            } else {
                $candidate = $baseSlug.'-reh4mat-'.$externalId.'-'.$suffix;
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
        $sku = $this->normaliseSku($sku) ?: 'REH4MAT-'.$externalVariantId;
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

    private function normaliseSku(string $sku): string
    {
        $sku = html_entity_decode(trim($sku), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($sku === '') {
            return '';
        }

        $sku = str_replace(['/', '\\'], '-', $sku);
        $sku = preg_replace('/\s+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/-+/', '-', $sku) ?? $sku;

        return Str::upper(trim($sku, '-._'));
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
        $html = preg_replace('/<embed\b[^>]*>.*?<\/embed>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?>/isu', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($string)) ?? strip_tags($string));

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $limit, '');
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            $string = $this->stringOrNull($value);

            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private function limitNullableDatabaseString(?string $value): ?string
    {
        return $value === null ? null : $this->limitDatabaseString($value);
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
