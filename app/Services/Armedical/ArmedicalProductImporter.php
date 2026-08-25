<?php

declare(strict_types=1);

namespace App\Services\Armedical;

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
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

final class ArmedicalProductImporter
{
    private const SOURCE = 'armedical';

    private const MAX_DATABASE_STRING_LENGTH = 190;

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $stats = [];

    /**
     * Import one fully priced ARmedical mapping row into the local catalogue.
     *
     * Media is intentionally not downloaded or synchronized in this stage.
     *
     * @param  array<string, mixed>  $mapped
     * @return array{product: Product, action: string, warnings: list<string>, stats: array<string, int>}
     */
    public function import(array $mapped): array
    {
        $this->warnings = [];
        $this->stats = $this->emptyStats();
        $this->assertMappedProductIsImportable($mapped);

        $productData = $this->productData($mapped);
        $externalId = $this->requiredString($productData['external_id'] ?? null, 'product external ID');
        $existing = Product::withTrashed()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();
        $action = $existing === null ? 'created' : 'updated';

        /** @var Product $product */
        $product = DB::transaction(function () use ($mapped, $externalId): Product {
            $product = $this->resolveProduct($mapped, $externalId);
            $this->syncCategories($product, $mapped);
            $this->syncProductAttributes($product, $mapped);
            $this->syncVariants($product, $mapped);

            return $product;
        });

        $product = $product->fresh([
            'categories.parent',
            'attributeValues.attribute',
            'images',
            'variants.attributeValues.attribute',
        ]);

        if (! $product instanceof Product) {
            throw new InvalidArgumentException('Unable to reload imported ARmedical product.');
        }

        return [
            'product' => $product,
            'action' => $action,
            'warnings' => array_values(array_unique($this->warnings)),
            'stats' => $this->stats,
        ];
    }

    /** @param array<string, mixed> $mapped */
    public function isFullyPriced(array $mapped): bool
    {
        if (($mapped['source'] ?? null) !== self::SOURCE) {
            return false;
        }

        if ($this->stringList($mapped['errors'] ?? null) !== []) {
            return false;
        }

        if ($this->stringList($mapped['blocking_review_items'] ?? null) !== []) {
            return false;
        }

        $productData = $this->productData($mapped);

        if (($productData['status'] ?? null) !== ProductStatus::DRAFT->value) {
            return false;
        }

        $variants = array_values(array_filter(
            $mapped['variants'] ?? [],
            static fn (mixed $variant): bool => is_array($variant),
        ));

        if ($variants === []) {
            return false;
        }

        foreach ($variants as $variant) {
            if (($variant['pricing_resolution']['status'] ?? null) !== 'matched') {
                return false;
            }

            $net = $this->intOrNull($variant['price_net_minor'] ?? null);
            $gross = $this->intOrNull($variant['price_gross_minor'] ?? null);
            $vat = $this->intOrNull($variant['vat_rate'] ?? null);
            $sku = $this->stringOrNull($variant['sku'] ?? null);
            $externalVariantId = $this->stringOrNull($variant['external_variant_id'] ?? null);

            if ($net === null || $net <= 0 || $gross === null || $gross <= 0) {
                return false;
            }

            if (! in_array($vat, [8, 23], true)) {
                return false;
            }

            if ($sku === null || $externalVariantId === null) {
                return false;
            }

            if (mb_strtoupper($this->stringOrNull($variant['currency'] ?? null) ?? '') !== Currency::PLN->value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $mapped */
    private function assertMappedProductIsImportable(array $mapped): void
    {
        $errors = $this->stringList($mapped['errors'] ?? null);

        if ($errors !== []) {
            throw new InvalidArgumentException('Mapped ARmedical product has hard errors: '.implode(' | ', $errors));
        }

        $blocking = $this->stringList($mapped['blocking_review_items'] ?? null);

        if ($blocking !== []) {
            throw new InvalidArgumentException('Mapped ARmedical product has blocking review items: '.implode(' | ', $blocking));
        }

        if (! $this->isFullyPriced($mapped)) {
            throw new InvalidArgumentException('Mapped ARmedical product is not fully resolved with deterministic supplier price/VAT for every variant.');
        }

        $product = $this->productData($mapped);

        if (($product['external_source'] ?? null) !== self::SOURCE) {
            throw new InvalidArgumentException('Mapped product external_source must be armedical.');
        }

        if (($product['status'] ?? null) !== ProductStatus::DRAFT->value) {
            throw new InvalidArgumentException('ARmedical local imports must remain draft products.');
        }
    }

    /** @param array<string, mixed> $mapped */
    private function resolveProduct(array $mapped, string $externalId): Product
    {
        $productData = $this->productData($mapped);
        $product = Product::withTrashed()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        $name = $this->requiredString($productData['name'] ?? null, 'product name');
        $baseSlug = $this->stringOrNull($productData['slug'] ?? null)
            ?: Str::slug($name)
                ?: 'armedical-product-'.substr(hash('sha256', $externalId), 0, 16);
        $externalParentSku = $this->stringOrNull($productData['external_parent_sku'] ?? null)
            ?: $this->stringOrNull($productData['catalogue_number'] ?? null)
                ?: 'ARMEDICAL-'.substr(hash('sha256', $externalId), 0, 16);

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($productData),
            'description' => $this->productDescriptionHtml($mapped),
            'seo_title' => $this->stringOrNull($productData['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($productData),
            'status' => ProductStatus::DRAFT,
            'published_at' => null,
            'external_source' => self::SOURCE,
            'external_id' => $externalId,
            'external_parent_sku' => $this->limitDatabaseString($externalParentSku),
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

    /** @param array<string, mixed> $mapped */
    private function syncCategories(Product $product, array $mapped): void
    {
        $entries = array_values(array_filter(
            $mapped['categories'] ?? [],
            static fn (mixed $category): bool => is_array($category),
        ));

        if ($entries === []) {
            throw new InvalidArgumentException('Mapped ARmedical product has no categories.');
        }

        $syncPayload = [];
        $primaryLeafId = null;
        $fallbackLeafId = null;

        foreach ($entries as $entry) {
            $path = $this->stringList($entry['path'] ?? null);

            if ($path === []) {
                continue;
            }

            $parent = null;
            $resolved = [];

            foreach ($path as $index => $name) {
                $parent = $this->resolveCategory($name, $parent, array_slice($path, 0, $index + 1));
                $resolved[] = $parent;
            }

            $leaf = end($resolved);

            if ($leaf instanceof Category) {
                $fallbackLeafId ??= $leaf->id;

                if (($entry['is_primary'] ?? false) === true) {
                    $primaryLeafId = $leaf->id;
                }
            }

            foreach ($resolved as $category) {
                $syncPayload[$category->id] = ['is_primary' => false];
            }
        }

        $primaryLeafId ??= $fallbackLeafId;

        if ($primaryLeafId !== null && isset($syncPayload[$primaryLeafId])) {
            $syncPayload[$primaryLeafId]['is_primary'] = true;
        }

        if ($syncPayload === []) {
            throw new InvalidArgumentException('Mapped ARmedical category paths could not be resolved.');
        }

        $product->categories()->sync($syncPayload);
    }

    /** @param list<string> $pathSegments */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $category = Category::withTrashed()
            ->where('name', $name)
            ->where('parent_id', $parent?->id)
            ->first();
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'armedical-category-'.substr(sha1(implode('|', $pathSegments)), 0, 12);
        }

        if ($category === null) {
            $slugMatch = Category::withTrashed()->where('slug', $baseSlug)->first();

            if ($slugMatch !== null && $slugMatch->parent_id === $parent?->id) {
                $category = $slugMatch;
            } elseif ($slugMatch !== null) {
                $pathSlug = Str::slug(implode(' ', $pathSegments));
                $baseSlug = $pathSlug !== '' ? $pathSlug : $baseSlug;
                $category = Category::withTrashed()
                    ->where('slug', $baseSlug)
                    ->where('parent_id', $parent?->id)
                    ->first();
            }
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
            $this->stats['categories_reused']++;

            return $category;
        }

        $this->stats['categories_created']++;

        return Category::query()->create($attributes + [
            'slug' => $this->uniqueCategorySlug($baseSlug),
        ]);
    }

    /** @param array<string, mixed> $mapped */
    private function syncProductAttributes(Product $product, array $mapped): void
    {
        $valueIds = [];
        $productData = $this->productData($mapped);
        $fixed = [
            ['code' => 'producent', 'label' => 'Producent', 'value' => $this->stringOrNull($productData['manufacturer'] ?? null)],
            ['code' => 'marka', 'label' => 'Marka', 'value' => $this->stringOrNull($productData['brand'] ?? null)],
        ];

        foreach ($fixed as $index => $entry) {
            if ($entry['value'] === null) {
                continue;
            }

            $attribute = $this->resolveAttribute($entry['code'], $entry['label']);
            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                code: $entry['code'],
                sourceValue: (string) $entry['value'],
                displayValue: (string) $entry['value'],
                sortOrder: $index,
            );
            $valueIds[] = $attributeValue->id;
        }

        $product->attributeValues()->sync(array_values(array_unique($valueIds)));
    }

    /** @param array<string, mixed> $mapped */
    private function syncVariants(Product $product, array $mapped): void
    {
        $candidates = array_values(array_filter(
            $mapped['variants'] ?? [],
            static fn (mixed $variant): bool => is_array($variant),
        ));

        if ($candidates === []) {
            throw new InvalidArgumentException('Mapped ARmedical product has no variants.');
        }

        $syncedIds = [];
        $seenExternalIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $candidate) {
            $externalVariantId = $this->requiredString($candidate['external_variant_id'] ?? null, 'variant external ID');

            if (isset($seenExternalIds[$externalVariantId])) {
                throw new InvalidArgumentException('Duplicate mapped ARmedical variant ID '.$externalVariantId.'.');
            }

            if (($candidate['pricing_resolution']['status'] ?? null) !== 'matched') {
                throw new InvalidArgumentException('ARmedical variant '.$externalVariantId.' does not have a deterministic supplier price match.');
            }

            $seenExternalIds[$externalVariantId] = true;
            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();
            $grossMinor = $this->requiredPositiveInt($candidate['price_gross_minor'] ?? null, 'variant gross price');
            $netMinor = $this->requiredPositiveInt($candidate['price_net_minor'] ?? null, 'variant net price');
            $vatRate = $this->vatRate($candidate['vat_rate'] ?? null);
            $requestedDefault = ($candidate['is_default'] ?? false) === true && ! $defaultAssigned;
            $attributes = [
                'sku' => $this->uniqueVariantSku(
                    $this->requiredString($candidate['sku'] ?? null, 'variant SKU'),
                    $variant?->id,
                ),
                'status' => ProductVariantStatus::DRAFT,
                'price_net_amount' => $netMinor,
                'price_gross_amount' => $grossMinor,
                'currency' => $this->currency($candidate['currency'] ?? null),
                'vat_rate' => $vatRate,
                'stock_status' => StockStatus::OUT_OF_STOCK,
                'is_default' => $requestedDefault,
            ];

            if ($variant !== null) {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
                $this->stats['variants_updated']++;
            } else {
                $variant = $product->variants()->create($attributes + [
                    'external_variant_id' => $externalVariantId,
                ]);
                $this->stats['variants_created']++;
            }

            $variant->attributeValues()->sync($this->variantAttributeValueIds($candidate));
            $syncedIds[] = $variant->id;
            $defaultAssigned = $defaultAssigned || $requestedDefault;
        }

        if (! $defaultAssigned) {
            $default = $product->variants()
                ->whereIn('id', $syncedIds)
                ->where('status', ProductVariantStatus::DRAFT)
                ->orderBy('id')
                ->first();

            if ($default !== null) {
                $default->update(['is_default' => true]);
                $defaultAssigned = true;
            }
        }

        $staleVariants = $product->variants()
            ->whereNotIn('id', $syncedIds)
            ->where(function ($query): void {
                $query->where('status', '!=', ProductVariantStatus::ARCHIVED)
                    ->orWhere('is_default', true);
            })
            ->get();

        foreach ($staleVariants as $staleVariant) {
            $staleVariant->update([
                'status' => ProductVariantStatus::ARCHIVED,
                'is_default' => false,
            ]);
            $this->stats['variants_archived']++;
        }

        if (! $defaultAssigned) {
            throw new InvalidArgumentException('No ARmedical variant could be assigned as default.');
        }
    }

    /** @param array<string, mixed> $candidate @return list<int> */
    private function variantAttributeValueIds(array $candidate): array
    {
        $ids = [];

        foreach (($candidate['attributes'] ?? []) as $index => $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $code = $this->attributeCode($attributeData);
            $label = $this->stringOrNull($attributeData['label'] ?? null) ?: Str::headline($code);
            $sourceValue = $this->stringOrNull($attributeData['value'] ?? null);
            $displayValue = $this->stringOrNull($attributeData['value_label'] ?? null) ?: $sourceValue;

            if ($sourceValue === null || $displayValue === null) {
                continue;
            }

            $attribute = $this->resolveAttribute($code, $label);
            $value = $this->resolveAttributeValue(
                attribute: $attribute,
                code: $code,
                sourceValue: $sourceValue,
                displayValue: $displayValue,
                sortOrder: (int) $index,
            );
            $ids[] = $value->id;
        }

        return array_values(array_unique($ids));
    }

    private function resolveAttribute(string $code, string $label): Attribute
    {
        $externalId = $this->limitDatabaseString('armedical-'.$code);
        $slug = Str::slug($label) ?: 'armedical-attribute-'.substr(sha1($label), 0, 12);
        $attribute = Attribute::query()->where('external_attribute_id', $externalId)->first();

        if ($attribute === null) {
            $attribute = Attribute::query()->where('slug', $slug)->first();
        }

        if ($attribute !== null) {
            $updates = [
                'name' => $label,
                'display_type' => AttributeDisplayType::SELECT,
            ];

            if (! filled($attribute->external_attribute_id)) {
                $updates['external_attribute_id'] = $externalId;
            }

            $attribute->update($updates);

            return $attribute;
        }

        return Attribute::query()->create([
            'external_attribute_id' => $externalId,
            'name' => $label,
            'slug' => $this->uniqueAttributeSlug($slug),
            'display_type' => AttributeDisplayType::SELECT,
        ]);
    }

    private function resolveAttributeValue(
        Attribute $attribute,
        string $code,
        string $sourceValue,
        string $displayValue,
        int $sortOrder,
    ): AttributeValue {
        $externalOptionId = $this->limitDatabaseString('armedical-'.$code.'-'.substr(hash('sha256', $sourceValue), 0, 20));
        $displayValue = $this->limitDatabaseString($displayValue);
        $slug = Str::slug($displayValue) ?: substr(sha1($displayValue), 0, 12);
        $value = AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->where('external_option_id', $externalOptionId)
            ->first();

        if ($value === null) {
            $value = AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where('slug', $slug)
                ->first();
        }

        $attributes = [
            'value' => $displayValue,
            'sort_order' => $sortOrder,
            'swatch_type' => null,
            'swatch_value' => null,
        ];

        if ($value !== null) {
            if (! filled($value->external_option_id)) {
                $attributes['external_option_id'] = $externalOptionId;
            }

            $value->update($attributes);

            return $value;
        }

        return AttributeValue::query()->create($attributes + [
            'attribute_id' => $attribute->id,
            'external_option_id' => $externalOptionId,
            'slug' => $this->uniqueAttributeValueSlug($attribute, $slug),
        ]);
    }

    /** @param array<string, mixed> $productData */
    private function shortDescriptionHtml(array $productData): ?string
    {
        $html = $this->cleanImportedHtml($this->stringOrNull($productData['short_description_html'] ?? null));

        if ($html !== null) {
            return '<p>'.e(Str::limit(strip_tags($html), 320, '')).'</p>';
        }

        $seo = $this->stringOrNull($productData['seo_description'] ?? null);

        return $seo !== null ? '<p>'.e(Str::limit(strip_tags($seo), 320, '')).'</p>' : null;
    }

    /** @param array<string, mixed> $mapped */
    private function productDescriptionHtml(array $mapped): ?string
    {
        $productData = $this->productData($mapped);
        $sections = [];
        $description = $this->relevantDescriptionHtml($this->stringOrNull($productData['description_html'] ?? null));

        if ($description !== null) {
            $sections[] = '<section class="armedical-description">'.$description.'</section>';
        }

        $resources = [];

        foreach (($mapped['documents'] ?? []) as $document) {
            if (! is_array($document)) {
                continue;
            }

            $url = $this->safeArmedicalUrl($document['source_url'] ?? null);

            if ($url === null) {
                continue;
            }

            $label = $this->stringOrNull($document['label'] ?? null) ?: 'Dokument producenta';
            $resources[$url] = '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>';
        }

        if ($resources !== []) {
            $items = array_map(static fn (string $link): string => '<li>'.$link.'</li>', array_values($resources));
            $sections[] = '<section class="armedical-resources"><h2>Materiały producenta</h2><ul>'.implode('', $items).'</ul></section>';
        }

        return $sections !== [] ? implode("\n", $sections) : null;
    }

    private function relevantDescriptionHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $parts = [];

        try {
            $crawler = new Crawler($html);

            foreach (['.post-content', '.additional-informations'] as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$parts): void {
                    $inner = $node->html();

                    if (is_string($inner) && trim(strip_tags($inner)) !== '') {
                        $parts[] = $inner;
                    }
                });
            }
        } catch (\Throwable) {
            $parts = [];
        }

        return $this->cleanImportedHtml($parts !== [] ? implode("\n", $parts) : $html);
    }

    private function cleanImportedHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('#<(script|style|nav|header|footer|form)\b[^>]*>.*?</\1>#isu', '', $html) ?? $html;
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#isu', '', $html) ?? $html;
        $html = preg_replace('#<iframe\b[^>]*/?>#isu', '', $html) ?? $html;
        $html = preg_replace('#<img\b[^>]*/?>#isu', '', $html) ?? $html;
        $html = preg_replace_callback(
            '#<a\b[^>]*>(.*?)</a>#isu',
            static fn (array $matches): string => $matches[1],
            $html,
        ) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/iu', '', $html) ?? $html;
        $html = trim($html);

        return trim(strip_tags($html)) === '' ? null : $html;
    }

    /** @param array<string, mixed> $productData */
    private function seoDescription(array $productData): ?string
    {
        $value = $this->stringOrNull($productData['seo_description'] ?? null)
            ?: $this->stringOrNull($productData['short_description_html'] ?? null);

        return $value !== null ? Str::limit(strip_tags($value), 320, '') : null;
    }

    private function safeArmedicalUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true)
            && in_array($host, ['armedical.pl', 'www.armedical.pl'], true)
                ? $url
                : null;
    }

    /** @param array<string, mixed> $mapped @return array<string, mixed> */
    private function productData(array $mapped): array
    {
        return is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
    }

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'categories_created' => 0,
            'categories_reused' => 0,
            'variants_created' => 0,
            'variants_updated' => 0,
            'variants_archived' => 0,
        ];
    }

    private function vatRate(mixed $value): VatRate
    {
        $int = $this->intOrNull($value);
        $vatRate = $int !== null ? VatRate::tryFrom($int) : null;

        if ($vatRate === null || ! in_array($vatRate, [VatRate::VAT_8, VatRate::VAT_23], true)) {
            throw new InvalidArgumentException('Unsupported mapped ARmedical VAT rate.');
        }

        return $vatRate;
    }

    private function currency(mixed $value): Currency
    {
        $currency = Currency::tryFrom(mb_strtoupper($this->stringOrNull($value) ?? 'PLN'));

        if ($currency === null) {
            throw new InvalidArgumentException('Unsupported mapped ARmedical currency.');
        }

        return $currency;
    }

    /** @param array<string, mixed> $attribute */
    private function attributeCode(array $attribute): string
    {
        $candidate = $this->stringOrNull($attribute['code'] ?? null)
            ?: $this->stringOrNull($attribute['label'] ?? null)
                ?: 'attribute';
        $code = Str::of($candidate)->lower()->ascii()->slug('-')->value();

        return $code !== '' ? $code : 'attribute';
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'armedical-product-'.substr(hash('sha256', $externalId), 0, 16);
        $candidate = $baseSlug;
        $suffix = 2;
        $externalSuffix = substr(hash('sha256', $externalId), 0, 10);

        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists()) {
            $candidate = $suffix === 2
                ? $baseSlug.'-armedical-'.$externalSuffix
                : $baseSlug.'-armedical-'.$externalSuffix.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueCategorySlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'armedical-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeSlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'armedical-attribute';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Attribute::query()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeValueSlug(Attribute $attribute, string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'value';
        $candidate = $baseSlug;
        $suffix = 2;

        while (AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueVariantSku(string $sku, ?int $currentVariantId): string
    {
        $baseSku = $this->limitDatabaseString($sku);
        $candidate = $baseSku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->when($currentVariantId !== null, fn ($query) => $query->whereKeyNot($currentVariantId))
            ->exists()) {
            $candidate = $this->limitDatabaseString($baseSku.'-'.$suffix++);
        }

        return $candidate;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            $string = $this->stringOrNull($item);

            if ($string !== null) {
                $result[] = $string;
            }
        }

        return $result;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function requiredPositiveInt(mixed $value, string $label): int
    {
        $int = $this->intOrNull($value);

        if ($int === null || $int <= 0) {
            throw new InvalidArgumentException('Mapped ARmedical '.$label.' must be a positive integer amount in minor units.');
        }

        return $int;
    }

    private function requiredString(mixed $value, string $label): string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            throw new InvalidArgumentException('Mapped ARmedical '.$label.' is missing.');
        }

        return $string;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limitDatabaseString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Str::limit($value, self::MAX_DATABASE_STRING_LENGTH, '');
    }
}
