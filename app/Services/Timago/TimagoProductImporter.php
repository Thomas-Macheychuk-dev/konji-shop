<?php

declare(strict_types=1);

namespace App\Services\Timago;

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
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class TimagoProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;
    private const MAX_PRODUCT_ATTRIBUTE_VALUE_LENGTH = 190;

    /**
     * @var list<string>
     */
    private const DOWNLOAD_ALLOWED_HOSTS = ['www.timago.com', 'timago.com'];

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
        $externalId = $this->externalProductId($scraped);

        $product = DB::transaction(function () use ($scraped, $externalId, $status, $importImages, $imageLimit): Product {
            $product = $this->resolveProduct($scraped, $externalId, $status, $importImages);

            $this->syncCategories($product, $scraped);
            $this->syncProductAttributes($product, $scraped);
            $this->syncDefaultVariant($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages($product, $scraped, $imageLimit);
            }

            return $product->fresh([
                'categories.parent',
                'images',
                'attributeValues.attribute',
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
    private function resolveProduct(array $scraped, string $externalId, ProductStatus $status, bool $localizeInlineImages): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'timago')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'timago-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'timago-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'Timago product '.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped, $externalId, $localizeInlineImages),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($scraped),
            'status' => $status,
            'external_source' => 'timago',
            'external_id' => $externalId,
            'external_parent_sku' => $this->parentSku($scraped, $externalId),
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
            $slug = 'timago-category-'.substr(sha1($name), 0, 12);
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
    private function syncProductAttributes(Product $product, array $scraped): void
    {
        $attributeValueIds = [];
        $seen = [];

        foreach ($this->productAttributePayloads($scraped) as $index => $attributeData) {
            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null || $this->isGeneratedMetaLabel($label)) {
                continue;
            }

            if (mb_strlen($value) > self::MAX_PRODUCT_ATTRIBUTE_VALUE_LENGTH) {
                $this->warnings[] = sprintf('Product attribute skipped because value is too long: %s', $label);

                continue;
            }

            $code = $this->attributeCode($attributeData, $label);
            $dedupeKey = $code.'|'.Str::lower($value);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;

            $attribute = $this->resolveAttribute(
                externalAttributeId: 'timago-'.$code,
                name: $label,
                displayType: $this->displayTypeForAttribute($code, $label),
            );

            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'timago-'.$code.'-'.$this->attributeValueSlug($attributeData, $value),
                value: $value,
                sortOrder: $index,
            );

            $attributeValueIds[] = $attributeValue->id;
        }

        $product->attributeValues()->sync(array_values(array_unique($attributeValueIds)));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncDefaultVariant(Product $product, array $scraped, ProductStatus $status): void
    {
        $externalVariantId = 'timago-'.$product->external_id.'-default';
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = VatRate::VAT_8;

        $variant = ProductVariant::updateOrCreate(
            [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
            ],
            [
                'sku' => $this->uniqueSku(
                    $this->stringOrNull($scraped['sku'] ?? null) ?: 'TIMAGO-'.$product->external_id,
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
                    'products/timago/'.$product->external_id.'/gallery',
                    'public',
                    self::DOWNLOAD_ALLOWED_HOSTS,
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
                fn ($query) => $query,
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

        foreach (($scraped['product_link_category_path'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null && ! $this->isIgnoredCategoryName($categoryName)) {
                $names[] = $categoryName;
            }
        }

        foreach (($scraped['categories'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null && ! $this->isIgnoredCategoryName($categoryName)) {
                $names[] = $categoryName;
            }
        }

        if ($names === []) {
            foreach (['category', 'product_link_category_name', 'product_link_top_category_name'] as $key) {
                $categoryName = $this->stringOrNull($scraped[$key] ?? null);

                if ($categoryName !== null && ! $this->isIgnoredCategoryName($categoryName)) {
                    $names[] = $categoryName;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function isIgnoredCategoryName(string $name): bool
    {
        $normalized = Str::of($name)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();

        return $normalized === ''
            || $normalized === 'strona glowna'
            || $normalized === 'nowosci';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        $sku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->productAttributeValue($scraped, 'Kod produktu')
                ?: $this->productAttributeValue($scraped, 'Model')
                    ?: 'TIMAGO-'.$externalId;

        return $this->limitDatabaseString($this->normaliseSku($sku));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productAttributeValue(array $scraped, string $label): ?string
    {
        foreach (($scraped['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $attributeLabel = $this->stringOrNull($attribute['label'] ?? null)
                ?: $this->stringOrNull($attribute['name'] ?? null);

            if ($attributeLabel !== null && Str::lower($attributeLabel) === Str::lower($label)) {
                return $this->stringOrNull($attribute['value'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $short = $this->stringOrNull($scraped['short_description'] ?? null)
            ?: $this->plainTextSnippet($scraped['seo_description'] ?? null, 240)
                ?: $this->plainTextSnippet($scraped['description_html'] ?? null, 240);

        if ($short === null) {
            return null;
        }

        return '<p>'.e($short).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function seoDescription(array $scraped): ?string
    {
        return $this->plainTextSnippet($scraped['seo_description'] ?? null, 300)
            ?: $this->plainTextSnippet($scraped['short_description'] ?? null, 300)
                ?: $this->plainTextSnippet($scraped['description_html'] ?? null, 300);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped, string $externalId, bool $localizeInlineImages): ?string
    {
        $sections = [];
        $description = $this->cleanHtml($this->stringOrNull($scraped['description_html'] ?? null), $externalId, $localizeInlineImages);

        if ($description !== null) {
            $sections[] = $description;
        }

        $metaRows = $this->descriptionMetaRows($scraped);

        if ($metaRows !== []) {
            $sections[] = $this->metaTableHtml($metaRows);
        }

        if (($scraped['is_medical_device'] ?? false) === true) {
            $sections[] = '<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"><strong>To jest wyrób medyczny.</strong> Dla bezpieczeństwa używaj go zgodnie z instrukcją użytkowania lub etykietą.</div>';
        }

        if ($sections === []) {
            return null;
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array{label: string, value: string}>
     */
    private function descriptionMetaRows(array $scraped): array
    {
        $rows = [];
        $seenLabels = [];

        $this->appendMetaRow($rows, $seenLabels, 'Kod produktu', $this->stringOrNull($scraped['sku'] ?? null));

        $brand = is_array($scraped['brand'] ?? null)
            ? $this->stringOrNull($scraped['brand']['name'] ?? null)
            : null;
        $this->appendMetaRow($rows, $seenLabels, 'Producent', $brand ?: 'Timago');

        $this->appendMetaRow($rows, $seenLabels, 'EAN', $this->stringOrNull($scraped['ean'] ?? null));

        foreach (($scraped['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $label = $this->stringOrNull($attribute['label'] ?? null)
                ?: $this->stringOrNull($attribute['name'] ?? null);
            $value = $this->stringOrNull($attribute['value'] ?? null);

            if ($label === null || $value === null || $this->isGeneratedMetaLabel($label)) {
                continue;
            }

            $this->appendMetaRow($rows, $seenLabels, $label, $value);
        }

        if (($scraped['is_medical_device'] ?? false) === true) {
            $this->appendMetaRow($rows, $seenLabels, 'Wyrób medyczny', 'Tak');
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     * @param  array<string, bool>  $seenLabels
     */
    private function appendMetaRow(array &$rows, array &$seenLabels, string $label, ?string $value): void
    {
        $label = trim($label);
        $value = $this->stringOrNull($value);

        if ($label === '' || $value === null || $this->isGeneratedMetaLabel($label)) {
            return;
        }

        $key = Str::of($label)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();

        if (isset($seenLabels[$key])) {
            return;
        }

        $seenLabels[$key] = true;

        $rows[] = [
            'label' => $label,
            'value' => $this->limitDatabaseString($value),
        ];
    }

    private function isGeneratedMetaLabel(string $label): bool
    {
        $normalized = Str::of($label)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();

        return in_array($normalized, ['dostepnosc', 'wysylka', 'czas wysylki', 'shipping', 'availability'], true);
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    private function metaTableHtml(array $rows): string
    {
        $html = '<h2>Dane produktu</h2><table><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><th>'.e($row['label']).'</th><td>'.e($row['value']).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    private function cleanHtml(?string $html, string $externalId, bool $localizeInlineImages): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('#<(script|style|nav|header|footer|form)\b[^>]*>.*?</\1>#isu', '', $html) ?? $html;
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#isu', '', $html) ?? $html;
        $html = preg_replace('#<iframe\b[^>]*\/?>#isu', '', $html) ?? $html;
        $html = $this->removeLinks($html);
        $html = $localizeInlineImages
            ? $this->localizeInlineImages($html, $externalId)
            : $this->removeImageTags($html);
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return trim(strip_tags($html)) === '' && ! str_contains($html, '<img') ? null : $html;
    }


    private function removeImageTags(string $html): string
    {
        return preg_replace('#<img\b[^>]*>#isu', '', $html) ?? $html;
    }

    private function removeLinks(string $html): string
    {
        $html = preg_replace_callback(
            '#<a\b[^>]*>(.*?)</a>#isu',
            fn (array $matches): string => $matches[1],
            $html,
        ) ?? $html;

        return preg_replace('#(?<!["\'=])https?://[^\s<>"]+#iu', '', $html) ?? $html;
    }

    private function localizeInlineImages(string $html, string $externalId): string
    {
        return preg_replace_callback(
            '#<img\b([^>]*)>#isu',
            function (array $matches) use ($externalId): string {
                $attributes = $matches[1];
                $src = $this->imageAttribute($attributes, 'src')
                    ?: $this->imageAttribute($attributes, 'data-src')
                        ?: $this->firstSrcsetUrl($this->imageAttribute($attributes, 'srcset'));

                if ($src === null || ! $this->isAllowedDownloadUrl($src)) {
                    return '';
                }

                try {
                    $imported = $this->remoteImageImporter->import(
                        $src,
                        'products/timago/'.$externalId.'/content',
                        'public',
                        self::DOWNLOAD_ALLOWED_HOSTS,
                    );
                } catch (Throwable $exception) {
                    $this->warnings[] = 'Inline image skipped for '.$externalId.': '.$src.' — '.$exception->getMessage();

                    return '';
                }

                $alt = $this->imageAttribute($attributes, 'alt') ?: '';

                return '<img src="'.e('/storage/'.$imported['path']).'" alt="'.e($alt).'">';
            },
            $html,
        ) ?? $html;
    }

    private function imageAttribute(string $attributes, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'\s*=\s*(["\'])(.*?)\1/isu', $attributes, $match) !== 1) {
            return null;
        }

        return $this->stringOrNull(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function firstSrcsetUrl(?string $srcset): ?string
    {
        if ($srcset === null) {
            return null;
        }

        $first = trim(explode(',', $srcset)[0] ?? $srcset);
        $parts = preg_split('/\s+/u', $first);

        return $this->stringOrNull($parts[0] ?? null);
    }

    private function isAllowedDownloadUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array(Str::lower($host), self::DOWNLOAD_ALLOWED_HOSTS, true);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function productAttributePayloads(array $scraped): array
    {
        $payloads = [];

        foreach (($scraped['attributes'] ?? []) as $attribute) {
            if (is_array($attribute)) {
                $payloads[] = $attribute;
            }
        }

        $brand = is_array($scraped['brand'] ?? null)
            ? $this->stringOrNull($scraped['brand']['name'] ?? null)
            : null;

        if ($brand !== null) {
            $payloads[] = [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => $brand,
                'slug' => Str::slug($brand),
            ];
        }

        if (($scraped['is_medical_device'] ?? false) === true) {
            $payloads[] = [
                'code' => 'wyrob-medyczny',
                'label' => 'Wyrób medyczny',
                'value' => 'Tak',
                'slug' => 'tak',
            ];
        }

        return $payloads;
    }

    private function resolveAttribute(string $externalAttributeId, string $name, AttributeDisplayType $displayType): Attribute
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'timago-attribute-'.substr(sha1($name), 0, 12);
        }

        $attribute = Attribute::query()
            ->where('external_attribute_id', $externalAttributeId)
            ->first();

        if ($attribute === null) {
            $attribute = Attribute::query()->where('slug', $slug)->first();
        }

        if ($attribute !== null) {
            $updates = [
                'name' => $name,
                'display_type' => $displayType,
            ];

            if (! filled($attribute->external_attribute_id)) {
                $updates['external_attribute_id'] = $externalAttributeId;
            }

            $attribute->update($updates);

            return $attribute;
        }

        return Attribute::query()->create([
            'external_attribute_id' => $externalAttributeId,
            'name' => $name,
            'slug' => $slug,
            'display_type' => $displayType,
        ]);
    }

    private function resolveAttributeValue(Attribute $attribute, string $externalOptionId, string $value, int $sortOrder): AttributeValue
    {
        $value = $this->limitDatabaseString($value);
        $externalOptionId = $this->limitDatabaseString($externalOptionId);
        $slug = $this->limitDatabaseString(Str::slug($value));

        if ($slug === '') {
            $slug = substr(sha1($value), 0, 12);
        }

        $attributeValue = AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->where('external_option_id', $externalOptionId)
            ->first();

        if ($attributeValue === null) {
            $attributeValue = AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where('slug', $slug)
                ->first();
        }

        $attributes = [
            'value' => $value,
            'sort_order' => $sortOrder,
            'swatch_type' => null,
            'swatch_value' => null,
        ];

        if ($attributeValue !== null) {
            if (! filled($attributeValue->external_option_id)) {
                $attributes['external_option_id'] = $externalOptionId;
            }

            $attributeValue->update($attributes);

            return $attributeValue;
        }

        return AttributeValue::query()->create($attributes + [
            'attribute_id' => $attribute->id,
            'external_option_id' => $externalOptionId,
            'slug' => $slug,
        ]);
    }

    private function displayTypeForAttribute(string $code, string $label): AttributeDisplayType
    {
        $normalized = Str::of($code.' '.$label)->lower()->ascii()->value();

        return str_contains($normalized, 'kolor') || str_contains($normalized, 'color')
            ? AttributeDisplayType::COLOR_SWATCH
            : AttributeDisplayType::TEXT;
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function attributeCode(array $attributeData, string $label): string
    {
        $code = $this->stringOrNull($attributeData['code'] ?? null) ?: Str::slug($label, '-');

        return $code !== '' ? $code : substr(sha1($label), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function attributeValueSlug(array $attributeData, string $value): string
    {
        $slug = $this->stringOrNull($attributeData['slug'] ?? null) ?: Str::slug($value);

        return $slug !== '' ? $slug : substr(sha1($value), 0, 12);
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

        if ($availability === 'preorder' || Str::of($label)->contains(['wkrotce', 'zamowienie'])) {
            return StockStatus::PREORDER;
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

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'timago-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-timago-'.$externalId;
            } else {
                $candidate = $baseSlug.'-timago-'.$externalId.'-'.$suffix;
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
        $sku = $this->normaliseSku($sku);
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
            $candidate = $this->limitDatabaseString($sku.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        return $this->limitDatabaseString(Str::upper(Str::slug($sku, '-')) ?: 'TIMAGO-SKU');
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null)
            ?: $this->stringOrNull($scraped['external_id'] ?? null)
                ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                    ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null));

        if ($externalId !== null) {
            return $externalId;
        }

        return substr(sha1((string) ($scraped['name'] ?? 'timago-product')), 0, 16);
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

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Str::slug(preg_replace('/\.html$/i', '', $slug) ?? $slug);
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = str_replace([' ', "\xc2\xa0"], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($string), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $limit, '');
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
