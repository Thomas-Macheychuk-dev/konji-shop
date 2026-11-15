<?php

declare(strict_types=1);

namespace App\Services\Sanipur;

use App\Enums\AttributeDisplayType;
use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Imports one grouped Sanipur product (SanipurProductGrouper output) as a draft product with one variant per
 * sanipur.eu page. Re-running updates prices and content in place; existing product status is kept.
 */
final class SanipurProductImporter
{
    public const SOURCE = 'sanipur';

    public const ROOT_CATEGORY = ['slug' => 'sanipur', 'name' => 'Sanipur'];

    public const FALLBACK_CATEGORY = 'Pozostałe';

    /**
     * GPSR manufacturer of Sanipur, Matex and BabyMatex: Multitex, which runs sanipur.eu and describes itself
     * as the manufacturer of all three brands (sanipur.eu/pl/o-nas, regulamin_sanipur; multitex.com.pl).
     * Filled in only for brands without details, so edits made in the admin panel are kept.
     */
    public const MANUFACTURER = [
        'name' => 'Multitex Sp. z o.o.',
        'address' => 'ul. 3 Maja 40, 48-250 Głogówek, Polska',
        'email' => 'bok@multitex.com.pl',
        'phone' => '+48 77 406 99 66',
        'website' => 'https://multitex.com.pl',
    ];

    /**
     * Brands made by Multitex (comparable form).
     */
    private const MULTITEX_BRANDS = ['sanipur', 'matex', 'babymatex'];

    private const MAX_DATABASE_STRING_LENGTH = 190;

    private const MAX_CATEGORY_DEPTH = 3;

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = ['sanipur.eu'];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * Source breadcrumbs without the "według rozmiaru" and per-size levels: sizes become variants here.
     *
     * @param  list<string>  $breadcrumbs
     * @return list<string>
     */
    public static function categoryPath(array $breadcrumbs): array
    {
        $path = [];

        foreach ($breadcrumbs as $crumb) {
            $crumb = trim(preg_replace('/\s+/u', ' ', (string) $crumb) ?? '');

            if ($crumb === '' || preg_match('/\d{2,3}\s*x\s*\d{2,3}/iu', $crumb) === 1) {
                break;
            }

            if (preg_match('/^(.*?)\s+według\s+(?:rozmiaru|materiału)$/iu', $crumb, $m) === 1) {
                $crumb = trim($m[1]);

                if ($crumb !== '' && mb_strtolower($crumb) !== mb_strtolower((string) end($path))) {
                    $path[] = $crumb;
                }

                break;
            }

            $path[] = $crumb;
        }

        $path = array_slice($path, 0, self::MAX_CATEGORY_DEPTH);

        return $path === [] ? [self::FALLBACK_CATEGORY] : $path;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(
        array $group,
        ?VatRate $vatRate,
        ?VatRate $medicalVatRate,
        bool $importImages = true,
        ?int $imageLimit = 10,
    ): array {
        $this->warnings = [];

        $externalId = $this->stringOrNull($group['external_product_id'] ?? null)
            ?? throw new InvalidArgumentException('Sanipur product is missing external_product_id.');
        $candidates = array_values(array_filter($group['variant_candidates'] ?? [], 'is_array'));

        if ($candidates === []) {
            throw new InvalidArgumentException('Sanipur product '.$externalId.' has no variants.');
        }

        foreach ($candidates as $candidate) {
            $this->resolveVatRate($candidate, $vatRate, $medicalVatRate);
        }

        $product = DB::transaction(function () use ($group, $externalId, $candidates, $vatRate, $medicalVatRate, $importImages, $imageLimit): Product {
            $product = $this->resolveProduct($group, $externalId);

            $this->syncCategory($product, $group);
            $this->syncProductAttributes($product, $group);
            $this->syncVariants($product, $candidates, $vatRate, $medicalVatRate);

            if ($importImages) {
                $this->syncImages($product, $group, $imageLimit);
            }

            return $product->fresh(['categories', 'images', 'variants.attributeValues.attribute']);
        });

        return ['product' => $product, 'warnings' => $this->warnings];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function resolveVatRate(array $candidate, ?VatRate $vatRate, ?VatRate $medicalVatRate): VatRate
    {
        $isMedical = (bool) ($candidate['is_medical_device'] ?? false);
        $rate = $isMedical ? ($medicalVatRate ?? $vatRate) : $vatRate;

        if ($rate === null) {
            throw new InvalidArgumentException($isMedical
                ? 'Sanipur VAT rate is not explicit for medical devices. Pass --medical-vat-rate or --vat-rate.'
                : 'Sanipur VAT rate is not explicit. Pass --vat-rate.');
        }

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function resolveProduct(array $group, string $externalId): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        $name = $this->limitDatabaseString($this->stringOrNull($group['name'] ?? null) ?? 'Sanipur '.$externalId);
        $baseSlug = Str::slug($this->stringOrNull($group['slug'] ?? null) ?? $name) ?: 'sanipur-'.$externalId;

        $attributes = [
            'name' => $name,
            // A published product keeps its URL when sanipur.eu renames it.
            'slug' => $product?->slug ?? $this->uniqueProductSlug($baseSlug, null, $externalId),
            'short_description' => $this->shortDescriptionHtml($group),
            'description' => $this->descriptionHtml($group),
            'seo_title' => $name,
            'seo_description' => $this->plainTextSnippet($this->summary($group), 300)
                ?? $this->plainTextSnippet($group['description_html'] ?? null, 300),
            'status' => $product?->status ?? ProductStatus::DRAFT,
            'external_source' => self::SOURCE,
            'external_id' => $externalId,
            'external_parent_sku' => $this->limitDatabaseString($this->normaliseSku('SNP-'.$externalId)),
        ];

        if ($product === null) {
            return Product::query()->create($attributes);
        }

        if ($product->trashed()) {
            $product->restore();
        }

        $product->update($attributes);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function syncCategory(Product $product, array $group): void
    {
        $parent = $this->category(self::ROOT_CATEGORY['slug'], self::ROOT_CATEGORY['name'], null);
        $slugPath = self::ROOT_CATEGORY['slug'];

        foreach (self::categoryPath(array_values(array_filter($group['breadcrumbs'] ?? [], 'is_string'))) as $name) {
            $slugPath .= '-'.Str::slug($name);
            $parent = $this->category($this->limitDatabaseString($slugPath), $name, $parent->id);
        }

        $product->categories()->sync([$parent->id => ['is_primary' => true]]);
    }

    private function category(string $slug, string $name, ?int $parentId): Category
    {
        $category = Category::withTrashed()->where('slug', $slug)->first();

        if ($category === null) {
            return Category::query()->create([
                'parent_id' => $parentId,
                'name' => $this->limitDatabaseString($name),
                'slug' => $slug,
                'status' => CategoryStatus::ACTIVE,
            ]);
        }

        if ($category->trashed()) {
            $category->restore();
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function syncProductAttributes(Product $product, array $group): void
    {
        $ids = [];
        $brand = $this->stringOrNull($group['brand'] ?? null);

        if ($brand !== null) {
            $brandValue = $this->resolveAttributeValue('Producent', $brand);
            $ids[$brandValue->id] = [];

            if (in_array(Str::slug($brand, ''), self::MULTITEX_BRANDS, true) && ! $brandValue->manufacturer()->exists()) {
                Manufacturer::query()->create(['attribute_value_id' => $brandValue->id] + self::MANUFACTURER);
            }
        }

        if ((bool) ($group['is_medical_device'] ?? false)) {
            $ids[$this->resolveAttributeValue('Wyrób medyczny', 'Tak')->id] = [];
        }

        foreach (($group['attributes'] ?? []) as $row) {
            $label = is_array($row) ? $this->stringOrNull($row['label'] ?? null) : null;
            $value = is_array($row) ? $this->stringOrNull($row['value'] ?? null) : null;

            if ($label !== null && $value !== null && mb_strlen($value) <= 120) {
                $ids[$this->resolveAttributeValue($label, $value)->id] = [];
            }
        }

        $product->attributeValues()->sync($ids);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function syncVariants(Product $product, array $candidates, ?VatRate $vatRate, ?VatRate $medicalVatRate): void
    {
        $status = match ($product->status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
        $syncedIds = [];

        foreach ($candidates as $index => $candidate) {
            $sourceId = $this->stringOrNull($candidate['external_variant_id'] ?? null) ?? (string) ($index + 1);
            $externalVariantId = $this->limitDatabaseString(self::SOURCE.'-'.$sourceId);
            $gross = $this->moneyToMinorUnits($candidate['price_gross_amount'] ?? null);

            if ($gross === null) {
                throw new InvalidArgumentException('Sanipur variant '.$sourceId.' has no valid gross price.');
            }

            $rate = $this->resolveVatRate($candidate, $vatRate, $medicalVatRate);
            $sourceSku = $this->stringOrNull($candidate['source_sku'] ?? null);
            $sku = $this->normaliseSku('SNP-'.($sourceSku ?? $sourceId));

            $variant = ProductVariant::withTrashed()->where('external_variant_id', $externalVariantId)->first();
            $attributes = [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
                'sku' => $this->uniqueSku($sku, $variant?->id),
                'status' => $status,
                'price_net_amount' => $rate->netFromGross($gross),
                'price_gross_amount' => $gross,
                'currency' => Currency::PLN,
                'vat_rate' => $rate,
                'stock_status' => StockStatus::IN_STOCK,
                'is_default' => $index === 0,
            ];

            if ($variant === null) {
                $variant = ProductVariant::query()->create($attributes);
            } else {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            }

            $valueIds = [];

            foreach (($candidate['attributes'] ?? []) as $row) {
                $label = is_array($row) ? $this->stringOrNull($row['label'] ?? null) : null;
                $value = is_array($row) ? $this->stringOrNull($row['value'] ?? null) : null;

                if ($label !== null && $value !== null) {
                    $valueIds[] = $this->resolveAttributeValue($label, $value)->id;
                }
            }

            $variant->attributeValues()->sync(array_values(array_unique($valueIds)));
            $syncedIds[] = $variant->id;
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function syncImages(Product $product, array $group, ?int $imageLimit): void
    {
        $rows = [];
        $seen = [];

        foreach (($group['images'] ?? []) as $image) {
            if ($imageLimit !== null && $imageLimit > 0 && count($rows) >= $imageLimit) {
                break;
            }

            $url = is_array($image) ? $this->stringOrNull($image['url'] ?? null) : null;

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/sanipur/'.$product->external_id.'/gallery',
                    'public',
                    self::IMAGE_ALLOWED_HOSTS,
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for Sanipur product '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $alt = $this->stringOrNull($image['alt'] ?? null) ?? $product->name;
            $rows[] = $imported + ['alt' => $this->limitDatabaseString($alt)];
        }

        if ($rows === []) {
            if (($group['images'] ?? []) !== []) {
                $this->warnings[] = 'No Sanipur images were imported for product '.$product->external_id.'; existing images were preserved.';
            }

            return;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('path', array_column($rows, 'path'))
            ->delete();

        foreach ($rows as $order => $row) {
            ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'path' => $row['path']],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $row['alt'],
                    'title' => $row['alt'],
                    'is_main' => $order === 0,
                    'sort_order' => $order,
                ],
            );
        }
    }

    private function resolveAttributeValue(string $attributeName, string $value): AttributeValue
    {
        $attributeName = trim($attributeName, " \t\n\r\0\x0B:");
        $value = trim($value);

        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => Str::slug($attributeName) ?: 'sanipur-attribute-'.substr(sha1($attributeName), 0, 10)],
            ['name' => $attributeName, 'external_attribute_id' => null, 'display_type' => AttributeDisplayType::SELECT],
        );

        return AttributeValue::query()->firstOrCreate(
            [
                'attribute_id' => $attribute->id,
                'slug' => Str::slug(str_replace(['/', '\\', ',', '.'], '-', $value)) ?: 'sanipur-value-'.substr(sha1($value), 0, 10),
            ],
            ['value' => $this->limitDatabaseString($value), 'external_option_id' => null, 'sort_order' => 0],
        );
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function shortDescriptionHtml(array $group): ?string
    {
        $summary = $this->plainTextSnippet($this->summary($group), 500);

        return $summary === null ? null : '<p>'.e($summary).'</p>';
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function descriptionHtml(array $group): ?string
    {
        $sections = [];
        $main = $this->cleanImportedHtml($this->stringOrNull($group['description_html'] ?? null));

        if ($main !== null) {
            $sections[] = $main;
        }

        $rows = [];

        foreach (($group['attributes'] ?? []) as $row) {
            $label = is_array($row) ? $this->stringOrNull($row['label'] ?? null) : null;
            $value = is_array($row) ? $this->stringOrNull($row['value'] ?? null) : null;

            if ($label !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        if ($rows !== []) {
            $sections[] = '<section class="sanipur-parameters"><h2>Parametry produktu</h2><table><tbody>'.implode('', array_unique($rows)).'</tbody></table></section>';
        }

        return $sections === [] ? null : implode("\n", $sections);
    }

    private function cleanImportedHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('/<(script|style|iframe|object)\b[^>]*>.*?<\/\1>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?\s*>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+(?:style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/<\/?a\b[^>]*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<h4\b[^>]*>(.*?)<\/h4>/isu', '<h3>$1</h3>', $html) ?? $html;
        $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><h2><h3><table><thead><tbody><tr><th><td>');
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = $this->limitDatabaseString($baseSlug);
        $candidate = $baseSlug;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists()) {
            $candidate = $this->limitDatabaseString($suffix === 2 ? $baseSlug.'-sanipur' : $baseSlug.'-sanipur-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueSku(string $sku, ?int $currentVariantId): string
    {
        $sku = $this->limitDatabaseString($sku);
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->when($currentVariantId !== null, fn ($query) => $query->whereKeyNot($currentVariantId))
            ->exists()) {
            $candidate = $this->limitDatabaseString($sku.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        $sku = str_replace(['/', '\\'], '-', trim($sku));
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/-+/', '-', $sku) ?? $sku;

        return Str::upper(trim($sku, '-._'));
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (int) round($value * 100) : null;
        }

        if (is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
            $amount = (float) str_replace(',', '.', trim($value));

            return $amount > 0 ? (int) round($amount * 100) : null;
        }

        return null;
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $string)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= self::MAX_DATABASE_STRING_LENGTH) {
            return $value;
        }

        $hash = substr(sha1($value), 0, 10);

        return rtrim(mb_substr($value, 0, self::MAX_DATABASE_STRING_LENGTH - 11), '-_ .').'_'.$hash;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function summary(array $group): ?string
    {
        $summary = $this->stringOrNull($group['short_description'] ?? null);

        return $summary === null || preg_match('/^custom\s*value$/iu', $summary) === 1 ? null : $summary;
    }

    /**
     * Archives Sanipur products that are not in the given import (renamed or regrouped on sanipur.eu, or
     * removed there), with their variants. Returns the archived products' names.
     *
     * @param  list<string>  $keepExternalIds
     * @return list<string>
     */
    public function archiveMissing(array $keepExternalIds, bool $dryRun): array
    {
        $missing = Product::query()
            ->where('external_source', self::SOURCE)
            ->where('status', '!=', ProductStatus::ARCHIVED->value)
            ->whereNotIn('external_id', $keepExternalIds)
            ->orderBy('id')
            ->get(['id', 'name']);

        if (! $dryRun && $missing->isNotEmpty()) {
            DB::transaction(function () use ($missing): void {
                ProductVariant::query()->whereIn('product_id', $missing->pluck('id'))->update(['status' => ProductVariantStatus::ARCHIVED->value]);
                Product::query()->whereKey($missing->pluck('id'))->update(['status' => ProductStatus::ARCHIVED->value]);
            });
        }

        return $missing->map(fn (Product $product): string => '#'.$product->id.' '.$product->name)->all();
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
