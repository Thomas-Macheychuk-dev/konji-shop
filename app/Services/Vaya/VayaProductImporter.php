<?php

declare(strict_types=1);

namespace App\Services\Vaya;

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

final class VayaProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    private const MAX_FILTER_VALUE_LENGTH = 190;

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = [
        'www.vaya.com.pl',
        'vaya.com.pl',
    ];

    private const IMAGE_BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';

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
        ?int $imageLimit = 50,
        int $imageTimeoutSeconds = 30,
        int $imageAttempts = 5,
        int $imageRetryDelayMs = 5000,
        int $imageRequestDelayMs = 250,
    ): array {
        $this->warnings = [];
        $externalId = $this->externalProductId($scraped);

        $product = DB::transaction(function () use (
            $scraped,
            $externalId,
            $status,
            $importImages,
            $imageLimit,
            $imageTimeoutSeconds,
            $imageAttempts,
            $imageRetryDelayMs,
            $imageRequestDelayMs,
        ): Product {
            $product = $this->resolveProduct($scraped, $externalId, $status);

            $this->syncCategories($product, $scraped);
            $this->syncProductAttributes($product, $scraped);
            $this->syncVariants($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages(
                    product: $product,
                    scraped: $scraped,
                    imageLimit: $imageLimit,
                    imageTimeoutSeconds: max(1, $imageTimeoutSeconds),
                    imageAttempts: max(1, $imageAttempts),
                    imageRetryDelayMs: max(0, $imageRetryDelayMs),
                    imageRequestDelayMs: max(0, $imageRequestDelayMs),
                );
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
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function resolveProduct(array $scraped, string $externalId, ProductStatus $status): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'vaya')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'vaya-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'vaya-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'Vaya product '.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($scraped),
            'status' => $status,
            'external_source' => 'vaya',
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
        $paths = $this->categoryPaths($scraped);

        if ($paths === []) {
            return;
        }

        $syncPayload = [];
        $primaryLeafId = null;

        foreach ($paths as $pathIndex => $path) {
            $parent = null;
            $resolved = [];

            foreach ($path as $segmentIndex => $categoryName) {
                $segments = array_slice($path, 0, $segmentIndex + 1);
                $parent = $this->resolveCategory($categoryName, $parent, $segments);
                $resolved[] = $parent;
            }

            $leaf = end($resolved);

            if ($pathIndex === 0 && $leaf instanceof Category) {
                $primaryLeafId = $leaf->id;
            }

            foreach ($resolved as $category) {
                $syncPayload[$category->id] = [
                    'is_primary' => false,
                ];
            }
        }

        if ($primaryLeafId !== null && isset($syncPayload[$primaryLeafId])) {
            $syncPayload[$primaryLeafId]['is_primary'] = true;
        }

        $product->categories()->sync($syncPayload);
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $category = Category::withTrashed()
            ->where('name', $name)
            ->where('parent_id', $parent?->id)
            ->first();

        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'vaya-category-'.substr(sha1(implode('|', $pathSegments)), 0, 12);
        }

        if ($category === null) {
            $slugMatch = Category::withTrashed()->where('slug', $baseSlug)->first();

            if ($slugMatch !== null && $slugMatch->parent_id === $parent?->id) {
                $category = $slugMatch;
            } elseif ($slugMatch !== null) {
                $pathSlug = Str::slug(implode(' ', $pathSegments));
                $baseSlug = $pathSlug !== '' ? $pathSlug : $baseSlug;
                $category = Category::withTrashed()->where('slug', $baseSlug)->first();
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
        $valueIds = [];
        $seen = [];

        foreach ($this->productAttributePayloads($scraped) as $index => $attributeData) {
            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null || $this->isGeneratedMetaLabel($label)) {
                continue;
            }

            if (! $this->isSafeFilterAttributeValue($value)) {
                $this->warnings[] = 'Product attribute skipped because it is not suitable for filtering: '.$label;

                continue;
            }

            $code = $this->attributeCode($attributeData, $label);
            $dedupeKey = $code.'|'.Str::lower($value);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;

            $attribute = $this->resolveAttribute(
                externalAttributeId: 'vaya-'.$code,
                name: $label,
                displayType: $this->displayTypeForAttribute($code, $label),
            );

            $valueSlug = $this->attributeValueSlug($attributeData, $value);
            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'vaya-'.$code.'-'.$valueSlug,
                value: $value,
                sortOrder: $index,
            );

            $valueIds[] = $attributeValue->id;
        }

        $product->attributeValues()->sync(array_values(array_unique($valueIds)));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncVariants(Product $product, array $scraped, ProductStatus $status): void
    {
        $candidates = $this->variantCandidates($scraped);
        $syncedVariantIds = [];
        $seenExternalIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $index => $candidate) {
            $sourceExternalId = $this->stringOrNull($candidate['external_variant_id'] ?? null)
                ?: (string) ($index + 1);
            $externalVariantId = $this->limitDatabaseString('vaya-'.$product->external_id.'-'.$sourceExternalId);

            if (isset($seenExternalIds[$externalVariantId])) {
                continue;
            }

            $seenExternalIds[$externalVariantId] = true;
            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();

            $label = $this->stringOrNull($candidate['label'] ?? null);
            $grossAmount = $this->moneyToMinorUnits(
                $candidate['price_gross_amount'] ?? $scraped['price_gross_amount'] ?? null,
            );
            $vatRate = $this->vatRateForProduct($scraped);
            $attributes = [
                'sku' => $this->variantSku(
                    scraped: $scraped,
                    candidate: $candidate,
                    externalId: $product->external_id,
                    label: $label,
                    sourceExternalId: $sourceExternalId,
                    currentVariantId: $variant?->id,
                ),
                'status' => $this->variantStatusForProductStatus($status),
                'price_net_amount' => $grossAmount !== null ? $vatRate->netFromGross($grossAmount) : null,
                'price_gross_amount' => $grossAmount,
                'currency' => $this->currency($candidate['currency'] ?? $scraped['currency'] ?? null),
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($candidate['availability'] ?? $scraped['availability'] ?? null),
                'is_default' => ! $defaultAssigned,
            ];

            if ($variant !== null) {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            } else {
                $variant = $product->variants()->create($attributes + [
                    'external_variant_id' => $externalVariantId,
                ]);
            }

            $variant->attributeValues()->sync($this->variantAttributeValueIds($candidate));
            $syncedVariantIds[] = $variant->id;
            $defaultAssigned = true;
        }

        if ($syncedVariantIds === []) {
            $variant = $this->syncDefaultVariant($product, $scraped, $status);
            $syncedVariantIds[] = $variant->id;
        }

        $product->variants()
            ->whereNotIn('id', $syncedVariantIds)
            ->update([
                'status' => ProductVariantStatus::ARCHIVED,
                'is_default' => false,
            ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncDefaultVariant(Product $product, array $scraped, ProductStatus $status): ProductVariant
    {
        $externalVariantId = $this->limitDatabaseString('vaya-'.$product->external_id.'-default');
        $variant = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->where('external_variant_id', $externalVariantId)
            ->first();
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = $this->vatRateForProduct($scraped);
        $baseSku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->stringOrNull($scraped['ean'] ?? null)
                ?: 'VAYA-'.$product->external_id;

        $attributes = [
            'sku' => $this->uniqueVariantSku($baseSku, $variant?->id),
            'status' => $this->variantStatusForProductStatus($status),
            'price_net_amount' => $grossAmount !== null ? $vatRate->netFromGross($grossAmount) : null,
            'price_gross_amount' => $grossAmount,
            'currency' => $this->currency($scraped['currency'] ?? null),
            'vat_rate' => $vatRate,
            'stock_status' => $this->stockStatus($scraped['availability'] ?? null),
            'is_default' => true,
        ];

        if ($variant !== null) {
            if ($variant->trashed()) {
                $variant->restore();
            }

            $variant->update($attributes);
            $variant->attributeValues()->sync([]);

            return $variant;
        }

        return $product->variants()->create($attributes + [
            'external_variant_id' => $externalVariantId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<int>
     */
    private function variantAttributeValueIds(array $candidate): array
    {
        $ids = [];

        foreach (($candidate['attributes'] ?? []) as $index => $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null)
                ?: $this->stringOrNull($candidate['label'] ?? null);

            if ($label === null || $value === null || ! $this->isSafeFilterAttributeValue($value)) {
                continue;
            }

            $code = $this->attributeCode($attributeData, $label);
            $attribute = $this->resolveAttribute(
                externalAttributeId: 'vaya-'.$code,
                name: $label,
                displayType: $this->displayTypeForAttribute($code, $label),
            );
            $valueSlug = $this->attributeValueSlug($attributeData, $value);
            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'vaya-'.$code.'-'.$valueSlug,
                value: $value,
                sortOrder: $index,
            );

            $ids[] = $attributeValue->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(
        Product $product,
        array $scraped,
        ?int $imageLimit,
        int $imageTimeoutSeconds,
        int $imageAttempts,
        int $imageRetryDelayMs,
        int $imageRequestDelayMs,
    ): void {
        $images = array_values(array_filter(
            $scraped['images'] ?? [],
            static fn (mixed $image): bool => is_array($image) && is_string($image['url'] ?? null),
        ));

        if ($imageLimit !== null) {
            $images = array_slice($images, 0, max(0, $imageLimit));
        }

        if ($images === []) {
            $this->warnings[] = 'No product images were supplied; existing images were preserved.';

            return;
        }

        $syncedImageIds = [];

        foreach ($images as $index => $imageData) {
            if ($index > 0 && $imageRequestDelayMs > 0) {
                usleep($imageRequestDelayMs * 1000);
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null) {
                continue;
            }

            $imported = null;
            $downloadErrors = [];

            foreach ($this->imageDownloadUrls($url) as $downloadUrl) {
                try {
                    $imported = $this->remoteImageImporter->import(
                        url: $downloadUrl,
                        directory: 'products/vaya/'.$product->external_id.'/gallery',
                        disk: 'public',
                        allowedHosts: self::IMAGE_ALLOWED_HOSTS,
                        requestOptions: [
                            'timeout_seconds' => $imageTimeoutSeconds,
                            'retry_attempts' => $imageAttempts,
                            'retry_delay_ms' => $imageRetryDelayMs,
                            'headers' => $this->imageRequestHeaders($scraped),
                        ],
                    );

                    break;
                } catch (Throwable $exception) {
                    $downloadErrors[] = $downloadUrl.' — '.$exception->getMessage();
                }
            }

            if ($imported === null) {
                $this->warnings[] = 'Image skipped: '.$url.' — '.implode(' | ', $downloadErrors);

                continue;
            }

            $image = ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'source_url' => $url,
                ],
                [
                    'disk' => $imported['disk'],
                    'path' => $imported['path'],
                    'mime_type' => $imported['mime_type'],
                    'file_size' => $imported['file_size'],
                    'sha256' => $imported['sha256'],
                    'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                    'title' => $this->stringOrNull($imageData['title'] ?? null),
                    'sort_order' => $index,
                    'is_main' => $index === 0,
                ],
            );

            $syncedImageIds[] = $image->id;
        }

        if ($syncedImageIds === []) {
            $this->warnings[] = 'All product image downloads failed; existing images were preserved.';

            return;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedImageIds)
            ->get()
            ->each(function (ProductImage $image): void {
                if ($image->path !== '' && Storage::disk($image->disk)->exists($image->path)) {
                    Storage::disk($image->disk)->delete($image->path);
                }

                $image->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, string>
     */
    private function imageRequestHeaders(array $scraped): array
    {
        $referer = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: 'https://vaya.com.pl/';
        $referer = $this->preferBareVayaHost($referer);

        return [
            'User-Agent' => self::IMAGE_BROWSER_USER_AGENT,
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en-US;q=0.7,en;q=0.6',
            'Referer' => $referer,
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Fetch-Dest' => 'image',
            'Sec-Fetch-Mode' => 'no-cors',
            'Sec-Fetch-Site' => 'same-origin',
        ];
    }

    /**
     * @return list<string>
     */
    private function imageDownloadUrls(string $url): array
    {
        $preferred = $this->preferBareVayaHost($url);

        return array_values(array_unique([$preferred, $url]));
    }

    private function preferBareVayaHost(string $url): string
    {
        return (string) preg_replace(
            '~^(https?://)www\.vaya\.com\.pl(?=/|$)~i',
            '$1vaya.com.pl',
            $url,
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<list<string>>
     */
    private function categoryPaths(array $scraped): array
    {
        $paths = [];

        foreach (($scraped['source_category_paths'] ?? []) as $path) {
            $normalized = $this->normalizeCategoryPath($path);

            if ($normalized !== []) {
                $paths[] = $normalized;
            }
        }

        if ($paths === []) {
            $normalized = $this->normalizeCategoryPath($scraped['source_category_path'] ?? null);

            if ($normalized !== []) {
                $paths[] = $normalized;
            }
        }

        if ($paths === []) {
            $normalized = $this->normalizeCategoryPath($scraped['categories'] ?? null);

            if ($normalized !== []) {
                $paths[] = $normalized;
            }
        }

        if ($paths === []) {
            $category = $this->stringOrNull($scraped['category'] ?? null);

            if ($category !== null) {
                $paths[] = [$category];
            }
        }

        $deduplicated = [];

        foreach ($paths as $path) {
            $deduplicated[implode('\x1F', $path)] = $path;
        }

        return array_values($deduplicated);
    }

    /**
     * @return list<string>
     */
    private function normalizeCategoryPath(mixed $path): array
    {
        if (! is_array($path)) {
            return [];
        }

        $segments = [];

        foreach ($path as $segment) {
            $name = $this->stringOrNull($segment);

            if ($name !== null) {
                $segments[] = $name;
            }
        }

        return array_values(array_unique($segments));
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

        $brand = $this->brandName($scraped);

        if ($brand !== null) {
            $payloads[] = [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => $brand,
                'slug' => Str::slug($brand),
            ];
        }

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $payloads[] = [
                'code' => 'wyrob-medyczny',
                'label' => 'Wyrób medyczny',
                'value' => 'Tak',
                'slug' => 'tak',
            ];
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function variantCandidates(array $scraped): array
    {
        return array_values(array_filter(
            $scraped['variant_candidates'] ?? [],
            static fn (mixed $candidate): bool => is_array($candidate),
        ));
    }

    private function resolveAttribute(string $externalAttributeId, string $name, AttributeDisplayType $displayType): Attribute
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'vaya-attribute-'.substr(sha1($name), 0, 12);
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
            'slug' => $this->uniqueAttributeSlug($slug),
            'display_type' => $displayType,
        ]);
    }

    private function resolveAttributeValue(
        Attribute $attribute,
        string $externalOptionId,
        string $value,
        int $sortOrder,
    ): AttributeValue {
        $value = $this->limitDatabaseString($value);
        $externalOptionId = $this->limitDatabaseString($externalOptionId);
        $slug = Str::slug($value);

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
            'slug' => $this->uniqueAttributeValueSlug($attribute, $slug),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function attributeCode(array $attributeData, string $label): string
    {
        $code = $this->stringOrNull($attributeData['code'] ?? null)
            ?: Str::slug($label);

        return $code !== '' ? $code : substr(sha1($label), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function attributeValueSlug(array $attributeData, string $value): string
    {
        $slug = $this->stringOrNull($attributeData['slug'] ?? null)
            ?: Str::slug($value);

        return $slug !== '' ? $slug : substr(sha1($value), 0, 12);
    }

    private function displayTypeForAttribute(string $code, string $label): AttributeDisplayType
    {
        $normalized = Str::lower($code.' '.$label);

        return str_contains($normalized, 'kolor')
            ? AttributeDisplayType::COLOR_SWATCH
            : AttributeDisplayType::SELECT;
    }

    private function isGeneratedMetaLabel(string $label): bool
    {
        $normalized = Str::of($label)
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();

        return in_array($normalized, [
            'dostepnosc',
            'wysylka',
            'czas wysylki',
            'shipping',
            'availability',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $summary = $this->stringOrNull($scraped['short_description'] ?? null)
            ?: $this->stringOrNull($scraped['seo_description'] ?? null);

        if ($summary === null) {
            return null;
        }

        return '<p>'.e(Str::limit(strip_tags($summary), 320, '')).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped): ?string
    {
        $sections = [];
        $description = $this->cleanImportedHtml($this->stringOrNull($scraped['description_html'] ?? null));

        if ($description === null) {
            $plain = $this->stringOrNull($scraped['description'] ?? null);
            $description = $plain !== null ? '<p>'.e($plain).'</p>' : null;
        }

        if ($description !== null) {
            $sections[] = '<section class="vaya-description"><h2>Opis produktu</h2>'.$description.'</section>';
        }

        $rows = $this->productMetaRows($scraped);

        if ($rows !== []) {
            $sections[] = '<section class="vaya-details">'.$this->metaTableHtml($rows).'</section>';
        }

        $safety = $this->cleanImportedHtml($this->stringOrNull($scraped['safety_html'] ?? null));

        if ($safety !== null) {
            $sections[] = '<section class="vaya-safety"><h2>Bezpieczeństwo i zgodność</h2>'.$safety.'</section>';
        }

        return $sections !== [] ? implode("\n", $sections) : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array{label: string, value: string}>
     */
    private function productMetaRows(array $scraped): array
    {
        $rows = [];
        $seenLabels = [];

        $this->appendMetaRow($rows, $seenLabels, 'Producent', $this->brandName($scraped));
        $this->appendMetaRow($rows, $seenLabels, 'Kod produktu', $this->stringOrNull($scraped['sku'] ?? null));
        $this->appendMetaRow($rows, $seenLabels, 'EAN', $this->stringOrNull($scraped['ean'] ?? null));
        $this->appendMetaRow($rows, $seenLabels, 'Dostępność', $this->stringOrNull($scraped['availability_label'] ?? null));
        $this->appendMetaRow($rows, $seenLabels, 'Wysyłka w', $this->stringOrNull($scraped['shipping_time'] ?? null));

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $this->appendMetaRow($rows, $seenLabels, 'Wyrób medyczny', 'Tak');
        }

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label !== null && $value !== null) {
                $this->appendMetaRow($rows, $seenLabels, $label, $value);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     * @param  array<string, true>  $seenLabels
     */
    private function appendMetaRow(array &$rows, array &$seenLabels, string $label, ?string $value): void
    {
        if ($value === null || $this->isPlaceholderValue($value)) {
            return;
        }

        $normalizedLabel = Str::of($label)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();

        if ($normalizedLabel === '' || isset($seenLabels[$normalizedLabel])) {
            return;
        }

        $seenLabels[$normalizedLabel] = true;
        $rows[] = [
            'label' => $label,
            'value' => $value,
        ];
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
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return trim(strip_tags($html)) === '' ? null : $html;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function seoDescription(array $scraped): ?string
    {
        $seo = $this->stringOrNull($scraped['seo_description'] ?? null)
            ?: $this->stringOrNull($scraped['short_description'] ?? null);

        return $seo !== null ? Str::limit(strip_tags($seo), 320, '') : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null)
            ?: $this->productIdFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->productIdFromUrl($this->stringOrNull($scraped['source_url'] ?? null));

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $source = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: (string) ($scraped['name'] ?? 'vaya-product');

        return substr(sha1($source), 0, 32);
    }

    private function productIdFromUrl(?string $url): ?string
    {
        if ($url !== null && preg_match('#/pl/p/[^/]+/(?P<id>[0-9]+)$#u', (string) parse_url($url, PHP_URL_PATH), $matches) === 1) {
            return (string) $matches['id'];
        }

        return null;
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url !== null && preg_match('#/pl/p/(?P<slug>[^/]+)/[0-9]+$#u', (string) parse_url($url, PHP_URL_PATH), $matches) === 1) {
            return Str::slug(rawurldecode((string) $matches['slug'])) ?: null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        return $this->limitDatabaseString(
            $this->stringOrNull($scraped['sku'] ?? null)
                ?: $this->stringOrNull($scraped['ean'] ?? null)
                    ?: 'VAYA-'.$externalId,
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @param  array<string, mixed>  $candidate
     */
    private function variantSku(
        array $scraped,
        array $candidate,
        string $externalId,
        ?string $label,
        string $sourceExternalId,
        ?int $currentVariantId,
    ): string {
        $candidateSku = $this->stringOrNull($candidate['sku'] ?? null);

        if ($candidateSku !== null && $candidateSku !== $this->stringOrNull($scraped['sku'] ?? null)) {
            return $this->uniqueVariantSku($candidateSku, $currentVariantId);
        }

        $baseSku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->stringOrNull($scraped['ean'] ?? null)
                ?: 'VAYA-'.$externalId;
        $variantPart = $this->variantSkuPart($label ?: $sourceExternalId);
        $qualifiedSku = $baseSku.'-'.$variantPart;

        return $this->uniqueVariantSku($qualifiedSku, $currentVariantId);
    }

    private function variantSkuPart(string $value): string
    {
        $part = Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->value();

        return $part !== '' ? $part : substr(sha1($value), 0, 10);
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

    private function stockStatus(mixed $availability): StockStatus
    {
        $normalized = Str::of((string) $availability)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        if (in_array($normalized, ['out_of_stock', 'unavailable', 'sold_out', 'not_available'], true)
            || Str::of($normalized)->contains(['brak', 'niedostepn', 'wyprzedan'])) {
            return StockStatus::OUT_OF_STOCK;
        }

        if (Str::of($normalized)->contains(['preorder', 'pre_order', 'na_zamowienie'])) {
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

    private function currency(mixed $value): Currency
    {
        $currency = $this->stringOrNull($value);

        return $currency !== null ? (Currency::tryFrom(Str::upper($currency)) ?? Currency::PLN) : Currency::PLN;
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round(((float) $value) * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9,.\-]/', '', $value) ?? $value;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (int) round(((float) $normalized) * 100) : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function brandName(array $scraped): ?string
    {
        $brand = $scraped['brand'] ?? null;
        $name = is_array($brand)
            ? $this->stringOrNull($brand['name'] ?? null)
            : $this->stringOrNull($brand);

        return $name !== null && ! $this->isPlaceholderValue($name) ? $name : null;
    }

    private function isPlaceholderValue(string $value): bool
    {
        $normalized = Str::of($value)->lower()->ascii()->trim()->value();

        return in_array($normalized, ['-', '--', '—', 'brak', 'brak danych', 'n/a', 'nie dotyczy'], true);
    }

    private function isSafeFilterAttributeValue(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_FILTER_VALUE_LENGTH
            && ! str_contains($value, '<')
            && ! str_contains($value, '>')
            && substr_count($value, ' ') <= 30;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'vaya-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            $candidate = $suffix === 2
                ? $baseSlug.'-vaya-'.$externalId
                : $baseSlug.'-vaya-'.$externalId.'-'.$suffix;
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
        $baseSlug = Str::slug($baseSlug) ?: 'vaya-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeSlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'vaya-attribute';
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

        while (AttributeValue::query()->where('attribute_id', $attribute->id)->where('slug', $candidate)->exists()) {
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

    private function limitDatabaseString(string $value): string
    {
        return Str::limit(trim($value), self::MAX_DATABASE_STRING_LENGTH, '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (! is_string($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
