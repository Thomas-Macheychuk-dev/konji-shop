<?php

declare(strict_types=1);

namespace App\Services\Seni24;

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
use InvalidArgumentException;
use Throwable;

final class Seni24ProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * Approved Seni24 discovery roots. Products may belong to more than one.
     *
     * @var array<string, string>
     */
    private const ROOT_CATEGORY_MAP = [
        'https://www.seni24.pl/nietrzymanie-moczu/' => 'Nietrzymanie moczu',
        'https://www.seni24.pl/higiena-i-pielegnacja-specjalistyczna/' => 'Higiena i pielęgnacja specjalistyczna',
        'https://www.seni24.pl/rehabilitacja-i-likwidacja-barier/' => 'Rehabilitacja i likwidacja barier',
        'https://www.seni24.pl/opatrywanie-i-leczenie-ran/' => 'Opatrywanie i leczenie ran',
        'https://www.seni24.pl/drogeria/' => 'Drogeria',
    ];

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = ['seni24.pl'];

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
        ?VatRate $fallbackVatRate = null,
        bool $importImages = true,
        ?int $imageLimit = 10,
    ): array {
        $this->warnings = [];

        $externalId = $this->externalProductId($scraped);
        $candidates = $this->validatedVariantCandidates(
            $scraped,
            $fallbackVatRate,
            $externalId,
        );

        $product = DB::transaction(function () use (
            $scraped,
            $externalId,
            $candidates,
            $importImages,
            $imageLimit,
        ): Product {
            $product = $this->resolveProduct($scraped, $externalId);
            $status = $product->status;

            $this->syncCategories($product, $scraped);
            $this->syncProductAttributes($product, $scraped);
            $this->syncVariants($product, $scraped, $candidates, $status);

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
    private function resolveProduct(array $scraped, string $externalId): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'seni24')
            ->where('external_id', $externalId)
            ->first();

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: 'Seni24 product '.$externalId;

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'seni24-product-'.$externalId;
        }

        $status = $product?->status ?? ProductStatus::DRAFT;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped),
            'seo_title' => $this->plainTextSnippet($scraped['seo_title'] ?? null, 300) ?: $name,
            'seo_description' => $this->plainTextSnippet($scraped['seo_description'] ?? null, 300),
            'status' => $status,
            'external_source' => 'seni24',
            'external_id' => $externalId,
            'external_parent_sku' => $this->parentSku($externalId),
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
        $rootNames = $this->rootCategoryNames($scraped);

        if ($rootNames === []) {
            throw new InvalidArgumentException(
                'Seni24 product '.$product->external_id.' is missing approved root provenance.'
            );
        }

        $sourcePath = $this->sourceCategoryPath($scraped);

        while ($sourcePath !== [] && $this->isApprovedRootCategoryName($sourcePath[0])) {
            array_shift($sourcePath);
        }

        $resolved = [];
        $primaryCategoryId = null;

        foreach ($rootNames as $rootIndex => $rootName) {
            $parent = $this->resolveCategory($rootName, null, [$rootName]);
            $resolved[$parent->id] = $parent;

            foreach ($sourcePath as $segmentIndex => $segment) {
                if ($this->isGenericBreadcrumbSegment($segment)) {
                    continue;
                }

                $parent = $this->resolveCategory(
                    $segment,
                    $parent,
                    array_merge([$rootName], array_slice($sourcePath, 0, $segmentIndex + 1)),
                );
                $resolved[$parent->id] = $parent;
            }

            if ($rootIndex === 0) {
                $primaryCategoryId = $parent->id;
            }
        }

        $syncPayload = [];

        foreach ($resolved as $category) {
            $syncPayload[$category->id] = [
                'is_primary' => $category->id === $primaryCategoryId,
            ];
        }

        $product->categories()->sync($syncPayload);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function rootCategoryNames(array $scraped): array
    {
        $context = is_array($scraped['raw_context'] ?? null)
            ? $scraped['raw_context']
            : [];
        $roots = is_array($context['listing_roots'] ?? null)
            ? $context['listing_roots']
            : [];

        $names = [];

        foreach ($roots as $rootUrl) {
            if (! is_string($rootUrl)) {
                continue;
            }

            $normalized = rtrim(trim($rootUrl), '/').'/';
            $name = self::ROOT_CATEGORY_MAP[$normalized] ?? null;

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    private function isApprovedRootCategoryName(string $name): bool
    {
        $key = $this->categoryKey($name);

        foreach (self::ROOT_CATEGORY_MAP as $approvedName) {
            if ($key === $this->categoryKey($approvedName)) {
                return true;
            }
        }

        return false;
    }

    private function isGenericBreadcrumbSegment(string $name): bool
    {
        return in_array($this->categoryKey($name), [
            'sklep',
            'produkty',
            'strona glowna',
            'home',
        ], true);
    }

    private function categoryKey(string $name): string
    {
        return Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function sourceCategoryPath(array $scraped): array
    {
        $path = is_array($scraped['source_category_path'] ?? null)
            ? $scraped['source_category_path']
            : (is_array($scraped['categories'] ?? null) ? $scraped['categories'] : []);

        $result = [];

        foreach ($path as $segment) {
            $segment = $this->stringOrNull($segment);

            if ($segment !== null && ! in_array($segment, $result, true)) {
                $result[] = $segment;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $baseSlug = $parent === null
            ? Str::slug($name)
            : Str::slug(implode(' ', $pathSegments));

        if ($baseSlug === '') {
            $baseSlug = 'seni24-category-'.substr(sha1(implode('|', $pathSegments)), 0, 10);
        }

        $category = Category::withTrashed()->where('slug', $baseSlug)->first();

        if ($category !== null && $category->parent_id !== $parent?->id) {
            $category = null;
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

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $values[] = $this->resolveAttributeValue('Wyrób medyczny', 'Tak');

            $medicalClass = $this->stringOrNull($scraped['medical_device_class'] ?? null);

            if ($medicalClass !== null) {
                $values[] = $this->resolveAttributeValue('Klasa wyrobu medycznego', $medicalClass);
            }
        }

        if ($this->booleanValue($scraped['is_refundable'] ?? null) === true) {
            $values[] = $this->resolveAttributeValue('Refundowany', 'Tak');
        }

        $brand = $this->stringOrNull($scraped['brand'] ?? null);

        if ($brand !== null) {
            $values[] = $this->resolveAttributeValue('Marka', $brand);
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
     * @param  list<array{candidate: array<string, mixed>, vat_rate: VatRate, gross_amount: int}>  $candidates
     */
    private function syncVariants(
        Product $product,
        array $scraped,
        array $candidates,
        ProductStatus $productStatus,
    ): void {
        $seenExternalVariantIds = [];
        $syncedVariantIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $resolved) {
            $candidate = $resolved['candidate'];
            $sourceExternalId = (string) $candidate['external_variant_id'];
            $externalVariantId = $this->limitDatabaseString(
                'seni24-'.$product->external_id.'-'.$sourceExternalId
            );

            if (isset($seenExternalVariantIds[$externalVariantId])) {
                throw new InvalidArgumentException(
                    'Seni24 product '.$product->external_id.' contains duplicate variant ID '.$sourceExternalId.'.'
                );
            }

            $seenExternalVariantIds[$externalVariantId] = true;

            $vatRate = $resolved['vat_rate'];
            $grossAmount = $resolved['gross_amount'];
            $attributeValueIds = $this->variantAttributeValueIds($candidate);
            $sku = $this->variantSku($product->external_id, $sourceExternalId);
            $isDefault = ! $defaultAssigned;

            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();

            $attributes = [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
                'sku' => $this->uniqueSku($sku, $product->id, $externalVariantId),
                'status' => $this->variantStatusForProductStatus($productStatus),
                'price_net_amount' => $vatRate->netFromGross($grossAmount),
                'price_gross_amount' => $grossAmount,
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($candidate, $scraped),
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
            throw new InvalidArgumentException(
                'Seni24 product '.$product->external_id.' has no validated variants.'
            );
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedVariantIds)
            ->delete();
    }

    /**
     * Validate the complete variant set before any database writes.
     *
     * @param  array<string, mixed>  $scraped
     * @return list<array{candidate: array<string, mixed>, vat_rate: VatRate, gross_amount: int}>
     */
    private function validatedVariantCandidates(
        array $scraped,
        ?VatRate $fallbackVatRate,
        string $externalId,
    ): array {
        if ($this->booleanValue($scraped['variant_resolution_complete'] ?? null) !== true) {
            throw new InvalidArgumentException(
                'Seni24 product '.$externalId.' does not have a complete resolved variant matrix.'
            );
        }

        $rawCandidates = is_array($scraped['variant_candidates'] ?? null)
            ? $scraped['variant_candidates']
            : [];

        if ($rawCandidates === []) {
            throw new InvalidArgumentException(
                'Seni24 product '.$externalId.' has no resolved variants.'
            );
        }

        $resolved = [];
        $seen = [];

        foreach ($rawCandidates as $candidate) {
            if (! is_array($candidate)) {
                throw new InvalidArgumentException(
                    'Seni24 product '.$externalId.' contains an invalid variant row.'
                );
            }

            $variantId = $this->stringOrNull($candidate['external_variant_id'] ?? null);

            if ($variantId === null) {
                throw new InvalidArgumentException(
                    'Seni24 product '.$externalId.' contains a variant without a stable source ID.'
                );
            }

            if (isset($seen[$variantId])) {
                throw new InvalidArgumentException(
                    'Seni24 product '.$externalId.' contains duplicate source variant ID '.$variantId.'.'
                );
            }

            $seen[$variantId] = true;
            $grossAmount = $this->moneyToMinorUnits($candidate['price_gross_amount'] ?? null);

            if ($grossAmount === null || $grossAmount < 0) {
                throw new InvalidArgumentException(
                    'Seni24 product '.$externalId.' variant '.$variantId.' has no valid gross price.'
                );
            }

            $vatRate = $this->resolveCandidateVatRate(
                $candidate,
                $scraped,
                $fallbackVatRate,
            );

            $resolved[] = [
                'candidate' => $candidate,
                'vat_rate' => $vatRate,
                'gross_amount' => $grossAmount,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $scraped
     */
    private function resolveCandidateVatRate(
        array $candidate,
        array $scraped,
        ?VatRate $fallback,
    ): VatRate {
        foreach ([$candidate['vat_rate'] ?? null, $scraped['vat_rate'] ?? null] as $value) {
            $rate = $this->vatRateValue($value);

            if ($rate !== null) {
                return $rate;
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new InvalidArgumentException(
            'Seni24 variant VAT rate is not explicit. Resolve VAT from Seni24 data or provide a reviewed override.'
        );
    }

    private function vatRateValue(mixed $value): ?VatRate
    {
        if (is_string($value) && trim($value) !== '' && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        return is_int($value) ? VatRate::tryFrom($value) : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(Product $product, array $scraped, ?int $imageLimit): void
    {
        $sourceImages = is_array($scraped['images'] ?? null) ? $scraped['images'] : [];

        if ($sourceImages === []) {
            return;
        }

        $imageRows = [];
        $seenUrls = [];
        $maxImages = $imageLimit !== null && $imageLimit > 0 ? $imageLimit : null;

        foreach ($sourceImages as $imageData) {
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
                    'products/seni24/'.$product->external_id.'/gallery',
                    'public',
                    self::IMAGE_ALLOWED_HOSTS,
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for Seni24 product '
                    .$product->external_id.': '.$url.' — '.$exception->getMessage();

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

        if ($imageRows === []) {
            $this->warnings[] = 'No Seni24 images were imported for product '.$product->external_id
                .'; existing images were preserved.';

            return;
        }

        $paths = array_column($imageRows, 'path');

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('path', $paths)
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

        $attributeSlug = Str::slug($attributeName)
            ?: 'seni24-attribute-'.substr(sha1($attributeName), 0, 10);
        $valueSlug = Str::slug(str_replace(['/', '\\', ',', '.'], '-', $value))
            ?: 'seni24-value-'.substr(sha1($value), 0, 10);

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
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $scraped
     */
    private function stockStatus(array $candidate, array $scraped): StockStatus
    {
        $availability = $this->normalizedAvailability(
            $candidate['availability'] ?? $scraped['availability'] ?? null,
        );

        return match ($availability) {
            'in_stock', 'available' => StockStatus::IN_STOCK,
            'preorder', 'on_order' => StockStatus::PREORDER,
            default => StockStatus::OUT_OF_STOCK,
        };
    }

    private function normalizedAvailability(mixed $value): string
    {
        return Str::of(is_scalar($value) ? (string) $value : '')
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    private function variantStatusForProductStatus(ProductStatus $status): ProductVariantStatus
    {
        return match ($status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
    }

    private function parentSku(string $externalId): string
    {
        return $this->limitDatabaseString($this->normaliseSku('S24-'.$externalId));
    }

    private function variantSku(string $externalId, string $sourceExternalId): string
    {
        return $this->normaliseSku('S24-'.$externalId.'-'.$sourceExternalId);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $summary = $this->plainTextSnippet($scraped['seo_description'] ?? null, 500)
            ?: $this->firstParagraphText($scraped['description_html'] ?? null, 500);

        return $summary === null ? null : '<p>'.e($summary).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped): ?string
    {
        $mainHtml = $this->cleanImportedHtml(
            $this->stringOrNull($scraped['description_html'] ?? null)
        );

        return $mainHtml;
    }

    private function cleanImportedHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?\s*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+(?:style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/<a\b[^>]*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<\/a>/isu', '', $html) ?? $html;
        $html = strip_tags(
            $html,
            '<p><br><strong><b><em><i><ul><ol><li><h2><h3><table><thead><tbody><tr><th><td>'
        );
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null);

        if ($externalId === null || ! ctype_digit($externalId)) {
            throw new InvalidArgumentException(
                'Seni24 product is missing its stable numeric source product ID.'
            );
        }

        return $this->limitDatabaseString($externalId);
    }

    private function isSafeFilterAttributeValue(string $value): bool
    {
        return mb_strlen($value) <= 120 && substr_count($value, ' ') <= 12;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'seni24-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists()) {
            $candidate = $suffix === 2
                ? $baseSlug.'-seni24-'.$externalId
                : $baseSlug.'-seni24-'.$externalId.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueCategorySlug(string $baseSlug): string
    {
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
        $sku = $this->limitDatabaseString($this->normaliseSku($sku) ?: 'S24-'.$externalVariantId);
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
            return $value * 100;
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

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($string)) ?? strip_tags($string));

        if ($text === '') {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

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

        $base = basename(rtrim($path, '/'));
        $base = preg_replace('/_[0-9]+-[0-9]+$/', '', $base) ?? $base;

        return $base !== '' ? Str::slug($base) : null;
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
