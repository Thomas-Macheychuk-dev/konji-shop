<?php

declare(strict_types=1);

namespace App\Services\Butterfly;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ButterflyProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = ['butterfly-mag.com'];

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
            $this->syncVariants($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages($product, $scraped, $imageLimit);
            }

            return $product->fresh([
                'categories.parent',
                'attributeValues.attribute',
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
    private function resolveProduct(array $scraped, string $externalId, ProductStatus $status, bool $importDescriptionImages): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'butterfly')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'butterfly-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'butterfly-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'Butterfly product '.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped, $externalId, $importDescriptionImages),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($scraped),
            'status' => $status,
            'external_source' => 'butterfly',
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

        foreach ($categoryNames as $index => $categoryName) {
            $parent = $this->resolveCategory($categoryName, $parent, array_slice($categoryNames, 0, $index + 1));
            $resolved[] = $parent;
        }

        $leafCategory = end($resolved);

        if (! $leafCategory instanceof Category) {
            return;
        }

        $syncPayload = [];

        foreach ($resolved as $category) {
            $syncPayload[$category->id] = [
                'is_primary' => $category->is($leafCategory),
            ];
        }

        $product->categories()->sync($syncPayload);
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'butterfly-category-'.substr(sha1(implode('|', $pathSegments)), 0, 10);
        }

        $category = Category::withTrashed()->where('slug', $baseSlug)->first();

        if ($category !== null && $parent !== null && $category->parent_id !== null && $category->parent_id !== $parent->id) {
            $pathSlug = Str::slug(implode(' ', $pathSegments));
            $category = Category::withTrashed()->where('slug', $pathSlug)->first();
            $baseSlug = $pathSlug ?: $baseSlug;
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
            'slug' => $this->uniqueCategorySlug($baseSlug),
        ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncProductAttributes(Product $product, array $scraped): void
    {
        $values = [];
        $variantAttributeSlugs = $this->variantAttributeSlugs($scraped);

        $brandName = $this->brandName($scraped);

        if ($brandName !== null) {
            $values[] = $this->resolveAttributeValue('Producent', $brandName);
        }

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $values[] = $this->resolveAttributeValue('Wyrób medyczny', 'Tak');
        }

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null || ! $this->isSafeFilterAttributeValue($value)) {
                continue;
            }

            $attributeSlug = $this->attributeSlug($label);

            if ($attributeSlug !== null && isset($variantAttributeSlugs[$attributeSlug])) {
                continue;
            }

            $values[] = $this->resolveAttributeValue($label, $value);
        }

        $syncIds = [];

        foreach ($values as $value) {
            $syncIds[$value->id] = [];
        }

        $product->attributeValues()->sync($syncIds);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, true>
     */
    private function variantAttributeSlugs(array $scraped): array
    {
        $slugs = [];

        foreach (($scraped['variant_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach (($candidate['attributes'] ?? []) as $attributeData) {
                if (! is_array($attributeData)) {
                    continue;
                }

                $label = $this->stringOrNull($attributeData['label'] ?? null);

                if ($label === null) {
                    continue;
                }

                $slug = $this->attributeSlug($label);

                if ($slug !== null) {
                    $slugs[$slug] = true;
                }
            }
        }

        return $slugs;
    }

    private function attributeSlug(string $attributeName): ?string
    {
        $attributeName = trim($attributeName, " \t\n\r\0\x0B:");
        $slug = Str::slug($attributeName);

        return $slug !== '' ? $slug : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncVariants(Product $product, array $scraped, ProductStatus $status): void
    {
        $candidates = $this->variantCandidates($scraped);
        $seenExternalVariantIds = [];
        $syncedVariantIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $index => $candidate) {
            $sourceExternalId = $this->stringOrNull($candidate['external_variant_id'] ?? null)
                ?: (string) ($index + 1);
            $externalVariantId = $this->limitDatabaseString('butterfly-'.$product->external_id.'-'.$sourceExternalId);

            if (isset($seenExternalVariantIds[$externalVariantId])) {
                continue;
            }

            $seenExternalVariantIds[$externalVariantId] = true;

            $attributeValueIds = $this->variantAttributeValueIds($candidate);
            $label = $this->stringOrNull($candidate['label'] ?? null);
            $sku = $this->variantSku($scraped, $candidate, $product->external_id, $label, $sourceExternalId);
            $grossAmount = $this->moneyToMinorUnits($candidate['price_gross_amount'] ?? $scraped['price_gross_amount'] ?? null);
            $vatRate = $this->vatRateForProduct($scraped);
            $isDefault = ! $defaultAssigned;

            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();

            $attributes = [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
                'sku' => $this->uniqueSku($sku, $product->id, $externalVariantId),
                'status' => $this->variantStatusForProductStatus($status),
                'price_net_amount' => $grossAmount === null ? null : $vatRate->netFromGross($grossAmount),
                'price_gross_amount' => $grossAmount,
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($scraped),
                'is_default' => $isDefault,
            ];

            if ($variant !== null) {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            } else {
                $variant = ProductVariant::query()->create($attributes);
            }

            $variant->attributeValues()->sync($attributeValueIds);
            $syncedVariantIds[] = $variant->id;
            $defaultAssigned = true;
        }

        if ($syncedVariantIds === []) {
            return;
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedVariantIds)
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
                    'products/butterfly/'.$product->external_id.'/gallery',
                    'public',
                    self::IMAGE_ALLOWED_HOSTS,
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for Butterfly product '.$product->external_id.': '.$url.' — '.$exception->getMessage();

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
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<int>
     */
    private function variantAttributeValueIds(array $candidate): array
    {
        $ids = [];

        foreach (($candidate['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null) {
                continue;
            }

            $ids[] = $this->resolveAttributeValue($label, $value)->id;
        }

        return array_values(array_unique($ids));
    }

    private function resolveAttributeValue(string $attributeName, string $value): AttributeValue
    {
        $attributeName = trim($attributeName, " \t\n\r\0\x0B:");
        $value = trim($value);
        $attributeSlug = Str::slug($attributeName) ?: 'butterfly-attribute-'.substr(sha1($attributeName), 0, 10);
        $valueSlug = Str::slug($value) ?: 'butterfly-value-'.substr(sha1($value), 0, 10);

        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => $attributeSlug],
            [
                'name' => $attributeName,
                'external_attribute_id' => null,
                'display_type' => AttributeDisplayType::SELECT,
            ],
        );

        return AttributeValue::query()->firstOrCreate(
            [
                'attribute_id' => $attribute->id,
                'slug' => $valueSlug,
            ],
            [
                'value' => $this->limitDatabaseString($value),
                'external_option_id' => null,
                'sort_order' => 0,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function variantCandidates(array $scraped): array
    {
        $candidates = [];

        foreach (($scraped['variant_candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates !== []) {
            return $candidates;
        }

        return [[
            'external_variant_id' => 'default',
            'sku' => $this->stringOrNull($scraped['sku'] ?? null),
            'label' => null,
            'attributes' => [],
            'price_gross_amount' => $scraped['price_gross_amount'] ?? null,
            'currency' => $scraped['currency'] ?? 'PLN',
        ]];
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function categoryNames(array $scraped): array
    {
        $names = [];

        foreach (($scraped['source_category_path'] ?? []) as $categoryName) {
            $categoryName = $this->normaliseCategoryName($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        foreach (($scraped['categories'] ?? []) as $categoryName) {
            $categoryName = $this->normaliseCategoryName($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        if ($names === []) {
            foreach ([
                $scraped['category'] ?? null,
                $scraped['source_category_name'] ?? null,
                $scraped['source_top_category_name'] ?? null,
            ] as $category) {
                $categoryName = $this->normaliseCategoryName($category);

                if ($categoryName !== null) {
                    $names[] = $categoryName;

                    break;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function normaliseCategoryName(mixed $value): ?string
    {
        $name = $this->stringOrNull($value);

        if ($name === null) {
            return null;
        }

        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = trim($name, " \t\n\r\0\x0B>/");
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        if ($name === '') {
            return null;
        }

        $fingerprint = Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        if (in_array($fingerprint, ['jestes tutaj', 'strona glowna'], true)) {
            return null;
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        $sku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->stringOrNull($scraped['canonical_url'] ?? null)
                ?: $this->stringOrNull($scraped['source_url'] ?? null)
                    ?: 'BUTTERFLY-'.$externalId;

        return $this->limitDatabaseString($this->normaliseSku($sku) ?: 'BUTTERFLY-'.$externalId);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @param  array<string, mixed>  $candidate
     */
    private function variantSku(array $scraped, array $candidate, string $externalId, ?string $label, string $sourceExternalId): string
    {
        $sku = $this->stringOrNull($candidate['sku'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'BUTTERFLY-'.$externalId;

        $sku = $this->normaliseSku($sku);

        if ($label !== null) {
            $labelSku = $this->normaliseSku($label);

            if ($labelSku !== '' && ! Str::of($sku)->contains('-'.$labelSku)) {
                $sku .= '-'.$labelSku;
            }
        } elseif ($sourceExternalId !== 'default') {
            $sku .= '-'.$this->normaliseSku($sourceExternalId);
        }

        return $sku ?: 'BUTTERFLY-'.$externalId.'-'.$sourceExternalId;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function seoDescription(array $scraped): ?string
    {
        return $this->plainTextSnippet($scraped['seo_description'] ?? null, 300)
            ?: $this->plainTextSnippet($scraped['short_description'] ?? null, 300)
                ?: $this->plainTextSnippet($scraped['description'] ?? null, 300);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $summary = $this->plainTextSnippet($scraped['short_description'] ?? null, 500)
            ?: $this->firstParagraphText($scraped['description_html'] ?? null, 500)
                ?: $this->plainTextSnippet($scraped['description'] ?? null, 500);

        return $summary === null ? null : '<p>'.e($summary).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped, string $externalId, bool $importDescriptionImages): ?string
    {
        $sections = [];
        $mainHtml = $this->stringOrNull($scraped['description_html'] ?? null);

        if ($mainHtml === null && $this->stringOrNull($scraped['description'] ?? null) !== null) {
            $mainHtml = '<p>'.e((string) $scraped['description']).'</p>';
        }

        if ($mainHtml !== null) {
            $sections[] = $mainHtml;
        }

        $parameterSection = $this->parameterSection($scraped);
        if ($parameterSection !== null) {
            $sections[] = $parameterSection;
        }

        $variantSection = $this->variantSection($scraped);
        if ($variantSection !== null) {
            $sections[] = $variantSection;
        }

        $metaSection = $this->metadataSection($scraped);
        if ($metaSection !== null) {
            $sections[] = $metaSection;
        }

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $sections[] = '<section class="butterfly-medical-notice"><p><strong>To jest wyrób medyczny. Używaj go zgodnie z instrukcją używania lub etykietą.</strong></p></section>';
        }

        if ($sections === []) {
            return null;
        }

        $html = $this->cleanImportedHtml(implode("\n", $sections));

        if ($html === null || ! $importDescriptionImages) {
            return $html;
        }

        return $this->rewriteEmbeddedDescriptionImages($html, $externalId);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parameterSection(array $scraped): ?string
    {
        $rows = [];

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        $tabs = $scraped['tabs'] ?? null;
        $parameters = is_array($tabs) && is_array($tabs['parametry'] ?? null) ? $tabs['parametry'] : [];

        foreach ($parameters as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="butterfly-parameters"><h2>Parametry produktu</h2><table><tbody>'.implode('', array_values(array_unique($rows))).'</tbody></table></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function variantSection(array $scraped): ?string
    {
        $items = [];

        foreach ($this->variantCandidates($scraped) as $candidate) {
            $label = $this->stringOrNull($candidate['label'] ?? null);

            if ($label === null || $label === 'default') {
                continue;
            }

            $items[] = '<li>'.e($label).'</li>';
        }

        if ($items === []) {
            return null;
        }

        return '<section class="butterfly-variants"><h2>Dostępne warianty</h2><ul>'.implode('', array_values(array_unique($items))).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function metadataSection(array $scraped): ?string
    {
        $rows = [];

        foreach ([
            'Producent' => $this->brandName($scraped),
            'SKU' => $this->stringOrNull($scraped['sku'] ?? null),
            'EAN' => $this->stringOrNull($scraped['ean'] ?? null),
        ] as $label => $value) {
            if ($value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="butterfly-product-meta"><h2>Dane produktu</h2><table><tbody>'.implode('', $rows).'</tbody></table></section>';
    }

    private function cleanImportedHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = $this->rewriteSafeYouTubeIframes($html);
        $html = preg_replace('/<iframe\b[^>]*\/\s*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*>.*?<\/embed>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?>/isu', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = $this->unwrapAnchorTags($html);
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    private function unwrapAnchorTags(string $html): string
    {
        $html = preg_replace('/<a\b[^>]*>/isu', '', $html) ?? $html;

        return preg_replace('/<\/a>/isu', '', $html) ?? $html;
    }

    private function rewriteEmbeddedDescriptionImages(string $html, string $externalId): string
    {
        return preg_replace_callback('/<img\b[^>]*>/isu', function (array $matches) use ($externalId): string {
            $tag = $matches[0];
            $sourceUrl = $this->normalizeButterflyImageUrl($this->attributeValue($tag, 'src'));

            if ($sourceUrl === null) {
                return $tag;
            }

            try {
                $imported = $this->remoteImageImporter->import(
                    $sourceUrl,
                    'products/butterfly/'.$externalId.'/description',
                    'public',
                    self::IMAGE_ALLOWED_HOSTS,
                );

                $url = Storage::disk($imported['disk'])->url($imported['path']);
            } catch (Throwable $exception) {
                $this->warnings[] = 'Description image kept as remote URL for Butterfly product '.$externalId.': '.$sourceUrl.' — '.$exception->getMessage();
                $url = $sourceUrl;
            }

            $tag = $this->replaceHtmlAttribute($tag, 'src', $url);

            foreach (['srcset', 'data-src', 'data-original', 'data-lazy', 'data-srcset'] as $attribute) {
                $tag = $this->removeHtmlAttribute($tag, $attribute);
            }

            return $tag;
        }, $html) ?? $html;
    }

    private function normalizeButterflyImageUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));

        if ($url === '' || preg_match('/^(?:data|javascript|cid|mailto):/iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://butterfly-mag.com'.$url;
        } elseif (! filter_var($url, FILTER_VALIDATE_URL)
            && preg_match('#^(?:userdata|environment|public)/#iu', $url) === 1) {
            $url = 'https://butterfly-mag.com/'.$url;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./iu', '', $host) ?? $host;

        if ($host !== 'butterfly-mag.com') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path === '' || preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/iu', $path) !== 1) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return 'https://butterfly-mag.com'.$path.(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    private function replaceHtmlAttribute(string $html, string $attribute, string $value): string
    {
        $escapedValue = e($value);
        $pattern = '/\b'.preg_quote($attribute, '/').'\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu';

        if (preg_match($pattern, $html) === 1) {
            return preg_replace($pattern, $attribute.'="'.$escapedValue.'"', $html, 1) ?? $html;
        }

        return preg_replace('/<img\b/iu', '<img '.$attribute.'="'.$escapedValue.'"', $html, 1) ?? $html;
    }

    private function removeHtmlAttribute(string $html, string $attribute): string
    {
        return preg_replace(
            '/\s+'.preg_quote($attribute, '/').'\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu',
            '',
            $html,
        ) ?? $html;
    }

    private function rewriteSafeYouTubeIframes(string $html): string
    {
        return preg_replace_callback('/<iframe\b[^>]*>.*?<\/iframe>/isu', function (array $matches): string {
            $iframe = $matches[0];
            $embedUrl = $this->safeYouTubeEmbedUrl($this->attributeValue($iframe, 'src'));

            if ($embedUrl === null) {
                return '';
            }

            $title = $this->stringOrNull($this->attributeValue($iframe, 'title')) ?: 'Film produktu';

            return '<div class="product-video-embed"><iframe src="'.e($embedUrl).'" title="'.e($title).'" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
        }, $html) ?? $html;
    }

    private function safeYouTubeEmbedUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./iu', '', $host) ?? $host;
        $path = (string) parse_url($url, PHP_URL_PATH);
        $videoId = null;

        if ($host === 'youtube.com' || $host === 'm.youtube.com' || $host === 'youtube-nocookie.com') {
            if (preg_match('#^/embed/([A-Za-z0-9_-]{6,64})#', $path, $matches) === 1) {
                $videoId = $matches[1];
            } elseif ($path === '/watch') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $candidate = $query['v'] ?? null;

                if (is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{6,64}$/', $candidate) === 1) {
                    $videoId = $candidate;
                }
            }
        } elseif ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{6,64})#', $path, $matches) === 1) {
            $videoId = $matches[1];
        }

        return $videoId === null ? null : 'https://www.youtube-nocookie.com/embed/'.$videoId;
    }

    private function attributeValue(string $html, string $attribute): ?string
    {
        if (! preg_match('/\b'.preg_quote($attribute, '/').'\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu', $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[2] ?? $matches[3] ?? $matches[4] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function vatRateForProduct(array $scraped): VatRate
    {
        return $this->booleanValue($scraped['is_medical_device'] ?? null) === true
            ? VatRate::VAT_8
            : VatRate::VAT_23;
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

        if ($availability === 'preorder') {
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
                ?: (string) ($scraped['name'] ?? 'butterfly-product');

        return substr(sha1($sourceUrl), 0, 32);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function brandName(array $scraped): ?string
    {
        $brand = $scraped['brand'] ?? null;

        if (is_array($brand)) {
            return $this->stringOrNull($brand['name'] ?? null);
        }

        return $this->stringOrNull($brand);
    }

    private function isSafeFilterAttributeValue(string $value): bool
    {
        if (mb_strlen($value) > 120) {
            return false;
        }

        return substr_count($value, ' ') <= 12;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'butterfly-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-butterfly-'.$externalId;
            } else {
                $candidate = $baseSlug.'-butterfly-'.$externalId.'-'.$suffix;
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
        $baseSlug = Str::slug($baseSlug) ?: 'butterfly-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueSku(string $sku, int $productId, string $externalVariantId): string
    {
        $sku = $this->limitDatabaseString($this->normaliseSku($sku) ?: 'BUTTERFLY-'.$externalVariantId);
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
        if (is_int($value)) {
            return $value > 10000 ? $value : $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
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

    private function firstParagraphText(mixed $value, int $limit): ?string
    {
        $html = $this->stringOrNull($value);

        if ($html === null) {
            return null;
        }

        if (preg_match('/<p\b[^>]*>(.*?)<\/p>/isu', $html, $matches) === 1) {
            return $this->plainTextSnippet($matches[1], $limit);
        }

        return $this->plainTextSnippet($html, $limit);
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $string = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $string) ?? $string;
        $string = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $string) ?? $string;
        $string = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $string) ?? $string;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($string)) ?? strip_tags($string));

        if ($text === '') {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return Str::limit($text, $limit, '');
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

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return null;
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
