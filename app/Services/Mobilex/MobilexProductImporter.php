<?php

declare(strict_types=1);

namespace App\Services\Mobilex;

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

final class MobilexProductImporter
{
    private const MAX_SHORT_ATTRIBUTE_VALUE_LENGTH = 190;
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
     * @param  array<string, mixed>  $normalized
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(array $normalized, bool $importImages = true): array
    {
        $this->warnings = [];

        $product = DB::transaction(function () use ($normalized, $importImages): Product {
            $externalId = $this->externalProductId($normalized);
            $product = $this->resolveProduct($normalized, $externalId);

            $this->syncCategory($product, $normalized);

            $productAttributeValueIds = $this->syncProductAttributes($normalized);
            $product->attributeValues()->sync($productAttributeValueIds);

            $this->syncVariants($product, $normalized);

            if ($importImages) {
                $this->syncImages($product, $normalized);
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
     * @param  array<string, mixed>  $normalized
     */
    private function resolveProduct(array $normalized, string $externalId): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'mobilex')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($normalized['slug'] ?? null)
            ?: Str::slug((string) ($normalized['name'] ?? 'mobilex-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'mobilex-product-'.$externalId;
        }

        $attributes = [
            'name' => $this->productNameWithProducer($normalized, $externalId),
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->cleanHtml($this->stringOrNull($normalized['short_description'] ?? null)),
            'description' => $this->cleanHtml($this->stringOrNull($normalized['description_html'] ?? null)),
            'seo_title' => $this->stringOrNull($normalized['seo_title'] ?? null),
            'seo_description' => $this->stringOrNull($normalized['seo_description'] ?? null),
            'status' => ProductStatus::ACTIVE,
            'external_source' => 'mobilex',
            'external_id' => $externalId,
            'external_parent_sku' => $this->stringOrNull($normalized['slug'] ?? null),
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
     * @param  array<string, mixed>  $normalized
     */
    private function productNameWithProducer(array $normalized, string $externalId): string
    {
        $name = $this->stringOrNull($normalized['name'] ?? null) ?: 'Mobilex product '.$externalId;
        $producer = $this->producerName($normalized);

        if ($producer === null || $this->isMobilexProducer($producer)) {
            return $name;
        }

        return $this->appendNameSuffix($name, $producer);
    }


    private function isMobilexProducer(string $producer): bool
    {
        return Str::of($producer)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value() === 'mobilex';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function producerName(array $normalized): ?string
    {
        $attributeProducer = $this->producerNameFromAttributes($normalized);

        if ($attributeProducer !== null) {
            return $attributeProducer;
        }

        $category = is_array($normalized['category'] ?? null) ? $normalized['category'] : [];
        $topCategoryName = $this->stringOrNull($category['top_name'] ?? null);

        if ($topCategoryName !== null && Str::of($topCategoryName)->lower()->ascii()->contains('scholl')) {
            return 'Scholl';
        }

        $brand = is_array($normalized['brand'] ?? null) ? $normalized['brand'] : [];

        return $this->stringOrNull($brand['name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function producerNameFromAttributes(array $normalized): ?string
    {
        foreach (($normalized['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = Str::of((string) ($attributeData['label'] ?? $attributeData['name'] ?? ''))->lower()->ascii()->value();
            $code = Str::of((string) ($attributeData['code'] ?? ''))->lower()->ascii()->value();

            if (! in_array($code, ['producent', 'producer', 'manufacturer'], true)
                && ! in_array($label, ['producent', 'producer', 'manufacturer'], true)) {
                continue;
            }

            $producer = $this->stringOrNull($attributeData['value'] ?? null);

            if ($producer !== null) {
                return $producer;
            }
        }

        return null;
    }

    /**
     * Mobilex normalized attributes contain mixed data. Many values are copied from
     * descriptive content and are not suitable for Konji selectable attributes.
     * Variant options are imported from variant_candidates, which are parsed from
     * the Mobilex Specyfikacja table. Keep only the producer as product metadata.
     *
     * @param  array<string, mixed>  $attributeData
     */
    private function isProducerAttribute(array $attributeData, string $label): bool
    {
        $normalizedLabel = Str::of($label)->lower()->ascii()->value();
        $code = Str::of((string) ($attributeData['code'] ?? ''))->lower()->ascii()->value();

        return in_array($code, ['producent', 'producer', 'manufacturer'], true)
            || in_array($normalizedLabel, ['producent', 'producer', 'manufacturer'], true);
    }


    private function appendNameSuffix(string $name, string $suffix): string
    {
        $suffix = trim($suffix);

        if ($suffix === '') {
            return $name;
        }

        $normalizedName = Str::of($name)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();
        $normalizedSuffix = Str::of($suffix)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();

        if ($normalizedSuffix === ''
            || $normalizedName === $normalizedSuffix
            || str_ends_with($normalizedName, ' '.$normalizedSuffix)
            || str_ends_with($normalizedName, ' - '.$normalizedSuffix)
            || str_ends_with($normalizedName, ' – '.$normalizedSuffix)) {
            return $name;
        }

        return $name.' '.$suffix;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'mobilex-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-mobilex-'.$externalId;
            } else {
                $candidate = $baseSlug.'-mobilex-'.$externalId.'-'.$suffix;
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

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncCategory(Product $product, array $normalized): void
    {
        $categoryData = is_array($normalized['category'] ?? null) ? $normalized['category'] : [];

        $topName = $this->stringOrNull($categoryData['top_name'] ?? null);
        $topUrl = $this->stringOrNull($categoryData['top_url'] ?? null);
        $name = $this->stringOrNull($categoryData['name'] ?? null);
        $url = $this->stringOrNull($categoryData['url'] ?? null);

        if ($topName === null && $name === null) {
            return;
        }

        $topCategory = null;

        if ($topName !== null) {
            $topCategory = $this->resolveCategory(
                name: $topName,
                url: $topUrl,
                parent: null,
            );
        }

        $primaryCategory = $topCategory;

        if ($name !== null && $name !== $topName) {
            $primaryCategory = $this->resolveCategory(
                name: $name,
                url: $url,
                parent: $topCategory,
            );
        }

        if ($primaryCategory === null) {
            return;
        }

        $product->categories()->syncWithoutDetaching([
            $primaryCategory->id => ['is_primary' => true],
        ]);
    }

    private function resolveCategory(string $name, ?string $url, ?Category $parent): Category
    {
        $slug = $this->slugFromUrl($url) ?: Str::slug($name);

        if ($slug === '') {
            $slug = 'mobilex-category-'.md5($name.($url ?? ''));
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
     * @param  array<string, mixed>  $normalized
     * @return list<int>
     */
    private function syncProductAttributes(array $normalized): array
    {
        $attributeValueIds = [];
        $seen = [];

        foreach (($normalized['attributes'] ?? []) as $index => $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null) {
                continue;
            }

            if (! $this->isProducerAttribute($attributeData, $label)) {
                continue;
            }

            if ($this->isLongProductAttributeValue($value)) {
                $this->warnings[] = sprintf(
                    'Product attribute skipped because value is too long for selectable attributes: %s',
                    $label,
                );

                continue;
            }

            $code = $this->attributeCode($attributeData, $label);
            $dedupeKey = $code.'|'.Str::lower($value);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;

            $attribute = $this->resolveAttribute(
                externalAttributeId: 'mobilex-'.$code,
                name: $label,
                displayType: $this->displayTypeForAttribute($code, $label),
            );

            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'mobilex-'.$code.'-'.$this->attributeValueSlug($attributeData, $value),
                value: $value,
                sortOrder: (int) $index,
            );

            $attributeValueIds[] = $attributeValue->id;
        }

        return array_values(array_unique($attributeValueIds));
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncVariants(Product $product, array $normalized): void
    {
        $candidates = array_values(array_filter(
            $normalized['variant_candidates'] ?? [],
            fn ($candidate): bool => is_array($candidate)
        ));

        if ($candidates === []) {
            $this->syncDefaultVariant($product, $normalized);

            return;
        }

        $variantAttribute = $this->resolveAttribute(
            externalAttributeId: 'mobilex-variant',
            name: 'Wariant',
            displayType: AttributeDisplayType::SELECT,
        );

        $incomingExternalVariantIds = [];
        $vatRate = $this->vatRateForProduct($normalized);

        foreach ($candidates as $index => $candidate) {
            /** @var array<string, mixed> $candidate */
            $externalVariantId = $this->stringOrNull($candidate['external_variant_id'] ?? null)
                ?: $this->stringOrNull($candidate['sku'] ?? null)
                    ?: 'variant-'.($index + 1);
            $incomingExternalVariantIds[] = $externalVariantId;

            $label = $this->stringOrNull($candidate['label'] ?? null)
                ?: $this->stringOrNull($candidate['sku'] ?? null)
                    ?: 'Wariant '.($index + 1);

            $variantValue = $this->resolveAttributeValue(
                attribute: $variantAttribute,
                externalOptionId: 'mobilex-variant-'.$this->slugWithFallback($label, $externalVariantId),
                value: $label,
                sortOrder: $index,
            );

            $variant = ProductVariant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'external_variant_id' => $externalVariantId,
                ],
                [
                    'sku' => $this->skuForVariant($product, $candidate, $externalVariantId),
                    'status' => ProductVariantStatus::ACTIVE,
                    'price_net_amount' => $this->variantPriceNetAmount($candidate, $vatRate),
                    'price_gross_amount' => $this->variantPriceGrossAmount($candidate),
                    'currency' => Currency::PLN,
                    'vat_rate' => $vatRate,
                    'stock_status' => StockStatus::IN_STOCK,
                    'is_default' => $index === 0,
                ]
            );

            $variant->attributeValues()->sync([$variantValue->id]);
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('external_variant_id', $incomingExternalVariantIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncDefaultVariant(Product $product, array $normalized): void
    {
        $externalVariantId = 'mobilex-'.$product->external_id.'-default';
        $vatRate = $this->vatRateForProduct($normalized);

        $variant = ProductVariant::updateOrCreate(
            [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
            ],
            [
                'sku' => $this->skuForDefaultVariant($product, $normalized),
                'status' => ProductVariantStatus::ACTIVE,
                'price_net_amount' => $this->defaultVariantPriceNetAmount($normalized, $vatRate),
                'price_gross_amount' => $this->defaultVariantPriceGrossAmount($normalized),
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => StockStatus::IN_STOCK,
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
     * @param  array<string, mixed>  $candidate
     */
    private function variantPriceNetAmount(array $candidate, VatRate $vatRate): ?int
    {
        $grossAmount = $this->integerOrNull($candidate['price_gross_amount'] ?? null);

        if ($grossAmount === null) {
            return null;
        }

        return $vatRate->netFromGross($grossAmount);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function variantPriceGrossAmount(array $candidate): ?int
    {
        return $this->integerOrNull($candidate['price_gross_amount'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function defaultVariantPriceNetAmount(array $normalized, VatRate $vatRate): ?int
    {
        $grossAmount = $this->integerOrNull($normalized['price_gross_amount'] ?? null);

        if ($grossAmount === null) {
            return null;
        }

        return $vatRate->netFromGross($grossAmount);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function defaultVariantPriceGrossAmount(array $normalized): ?int
    {
        return $this->integerOrNull($normalized['price_gross_amount'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function vatRateForProduct(array $normalized): VatRate
    {
        return $this->isShoeProduct($normalized)
            ? VatRate::VAT_23
            : VatRate::VAT_8;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function isShoeProduct(array $normalized): bool
    {
        $haystack = [
            $this->stringOrNull($normalized['name'] ?? null),
        ];

        $brand = is_array($normalized['brand'] ?? null) ? $normalized['brand'] : [];
        $haystack[] = $this->stringOrNull($brand['name'] ?? null);

        $category = is_array($normalized['category'] ?? null) ? $normalized['category'] : [];
        foreach (['top_name', 'name', 'top_url', 'url'] as $key) {
            $haystack[] = $this->stringOrNull($category[$key] ?? null);
        }

        $producer = $this->producerName($normalized);
        if ($producer !== null) {
            $haystack[] = $producer;
        }

        $text = Str::of(implode(' ', array_filter($haystack)))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        return Str::of($text)->contains(['obuwie', 'buty', 'shoe', 'shoes', 'scholl']);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncImages(Product $product, array $normalized): void
    {
        $imageRows = [];
        $seen = [];

        foreach (($normalized['images'] ?? []) as $index => $imageData) {
            if (! is_array($imageData)) {
                continue;
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/mobilex/'.$product->external_id.'/gallery',
                    'public',
                    ['mobilex.pl', 'galeriazdrowia.pl']
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $imageRows[] = [
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                'title' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
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

    private function resolveAttribute(string $externalAttributeId, string $name, AttributeDisplayType $displayType): Attribute
    {
        $slug = Str::slug($name);

        $attribute = Attribute::query()
            ->where('external_attribute_id', $externalAttributeId)
            ->first();

        if (! $attribute) {
            $attribute = Attribute::query()
                ->where('slug', $slug)
                ->first();
        }

        if ($attribute) {
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
            $slug = md5($value);
        }

        $attributeValue = AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->where('external_option_id', $externalOptionId)
            ->first();

        if (! $attributeValue) {
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

        if ($attributeValue) {
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

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function skuForVariant(Product $product, array $candidate, string $externalVariantId): string
    {
        $sku = $this->stringOrNull($candidate['sku'] ?? null) ?: 'MOBILEX-'.$product->external_id.'-'.$externalVariantId;

        return $this->uniqueSku($sku, $product->id, $externalVariantId);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function skuForDefaultVariant(Product $product, array $normalized): string
    {
        $slug = $this->stringOrNull($normalized['slug'] ?? null) ?: $product->slug;

        return $this->uniqueSku('MOBILEX-'.$product->external_id.'-'.$slug, $product->id, 'mobilex-'.$product->external_id.'-default');
    }

    private function uniqueSku(string $sku, int $productId, string $externalVariantId): string
    {
        $sku = Str::upper(Str::slug($sku, '-')) ?: 'MOBILEX-'.$externalVariantId;
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

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function externalProductId(array $normalized): string
    {
        $externalId = $this->stringOrNull($normalized['external_product_id'] ?? null)
            ?: $this->stringOrNull($normalized['external_id'] ?? null)
                ?: $this->stringOrNull($normalized['slug'] ?? null);

        if ($externalId !== null) {
            return $externalId;
        }

        $sourceUrl = $this->stringOrNull($normalized['source_url'] ?? null)
            ?: $this->stringOrNull($normalized['canonical_url'] ?? null)
                ?: (string) ($normalized['name'] ?? 'mobilex-product');

        return md5($sourceUrl);
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function attributeCode(array $attributeData, string $label): string
    {
        $code = $this->stringOrNull($attributeData['code'] ?? null) ?: Str::slug($label, '_');

        return $code !== '' ? $code : md5($label);
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function attributeValueSlug(array $attributeData, string $value): string
    {
        return $this->slugWithFallback(
            $this->stringOrNull($attributeData['slug'] ?? null) ?: $value,
            md5($value),
        );
    }

    private function slugWithFallback(string $value, string $fallback): string
    {
        return Str::slug($value) ?: Str::slug($fallback) ?: md5($value.$fallback);
    }

    private function displayTypeForAttribute(string $code, string $label): AttributeDisplayType
    {
        $normalized = Str::of($code.' '.$label)->lower()->ascii()->value();

        return str_contains($normalized, 'kolor') || str_contains($normalized, 'color')
            ? AttributeDisplayType::COLOR_SWATCH
            : AttributeDisplayType::TEXT;
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

        $html = $this->removeIframes($html);
        $html = $this->removeParagraphsContainingMobilexServiceLink($html);
        $html = $this->removeServiceSections($html);
        $html = $this->removeLinks($html);
        $html = preg_replace('/<!--\s*wp:themify-builder\/canvas\s*\/-->/i', '', $html) ?? $html;
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    private function removeIframes(string $html): string
    {
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $html) ?? $html;

        return preg_replace('/<iframe\b[^>]*\/?>/isu', '', $html) ?? $html;
    }

    private function removeParagraphsContainingMobilexServiceLink(string $html): string
    {
        return preg_replace(
            '/<p\b[^>]*>(?:(?!<\/p>).)*?https?:\/\/mobilex\.pl\/serwis\/?[^<\s"]*(?:(?!<\/p>).)*?<\/p>/isu',
            '',
            $html
        ) ?? $html;
    }

    private function removeLinks(string $html): string
    {
        $html = preg_replace_callback(
            '/<a\b[^>]*>(.*?)<\/a>/isu',
            fn (array $matches): string => $matches[1],
            $html
        ) ?? $html;

        return preg_replace('/(?<![\"\'=])https?:\/\/[^\s<>"]+/iu', '', $html) ?? $html;
    }

    private function removeServiceSections(string $html): string
    {
        return preg_replace_callback(
            '/(<h([1-3])\b[^>]*>.*?<\/h\2>)(\s*<p\b[^>]*>.*?<\/p>)?/isu',
            function (array $matches): string {
                $headingText = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (Str::of($headingText)->lower()->ascii()->contains('serwis')) {
                    return '';
                }

                return $matches[0];
            },
            $html
        ) ?? $html;
    }

    private function isLongProductAttributeValue(string $value): bool
    {
        return mb_strlen($value) > self::MAX_SHORT_ATTRIBUTE_VALUE_LENGTH;
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
}
