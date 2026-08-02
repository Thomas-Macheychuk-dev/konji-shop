<?php

declare(strict_types=1);

namespace App\Services\Microlife;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MicrolifeProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    private const MAX_FILTER_VALUE_LENGTH = 190;

    /**
     * @var list<string>
     */
    private const ASSET_ALLOWED_HOSTS = [
        'www.microlife.pl',
        'microlife.pl',
    ];

    private const MAX_DOCUMENT_SIZE_BYTES = 30 * 1024 * 1024;

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
        bool $importDocuments = true,
        int $assetTimeoutSeconds = 30,
        int $assetAttempts = 5,
        int $assetRetryDelayMs = 5000,
        int $assetRequestDelayMs = 250,
        bool $verifyTls = true,
    ): array {
        $this->warnings = array_values(array_filter(
            $scraped['warnings'] ?? [],
            static fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '',
        ));
        $externalId = $this->externalProductId($scraped);
        $documentContext = $this->documentContext(
            scraped: $scraped,
            externalId: $externalId,
            importDocuments: $importDocuments,
            timeoutSeconds: max(1, $assetTimeoutSeconds),
            attempts: max(1, $assetAttempts),
            retryDelayMs: max(0, $assetRetryDelayMs),
            requestDelayMs: max(0, $assetRequestDelayMs),
            verifyTls: $verifyTls,
        );

        $product = DB::transaction(function () use (
            $scraped,
            $externalId,
            $status,
            $documentContext,
            $importImages,
            $imageLimit,
            $assetTimeoutSeconds,
            $assetAttempts,
            $assetRetryDelayMs,
            $assetRequestDelayMs,
        ): Product {
            $product = $this->resolveProduct($scraped, $externalId, $status, $documentContext);

            $this->syncCategories($product, $scraped);
            $this->syncProductAttributes($product, $scraped);
            $this->syncVariants($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages(
                    product: $product,
                    scraped: $scraped,
                    imageLimit: $imageLimit,
                    imageTimeoutSeconds: max(1, $assetTimeoutSeconds),
                    imageAttempts: max(1, $assetAttempts),
                    imageRetryDelayMs: max(0, $assetRetryDelayMs),
                    imageRequestDelayMs: max(0, $assetRequestDelayMs),
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
    private function resolveProduct(
        array $scraped,
        string $externalId,
        ProductStatus $status,
        array $documentContext,
    ): Product {
        $product = Product::withTrashed()
            ->where('external_source', 'microlife')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'microlife-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'microlife-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['product_code'] ?? null)
                ?: 'Produkt Microlife '.substr($externalId, 0, 12);

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped, $documentContext),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($scraped),
            'status' => $status,
            'external_source' => 'microlife',
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
            $baseSlug = 'microlife-category-'.substr(sha1(implode('|', $pathSegments)), 0, 12);
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

            if (
                $label === null
                || $value === null
                || $this->isGeneratedMetaLabel($label)
                || $this->isVariantOptionAttribute($attributeData, $label)
            ) {
                continue;
            }

            if (! $this->isSafeFilterAttributeValue($value)) {
                continue;
            }

            $code = $this->attributeCode($attributeData, $label);
            $dedupeKey = $code.'|'.Str::lower($value);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;

            $attribute = $this->resolveAttribute(
                externalAttributeId: 'microlife-'.$code,
                name: $label,
                displayType: $this->displayTypeForAttribute($code, $label),
            );

            $valueSlug = $this->attributeValueSlug($attributeData, $value);
            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'microlife-'.$code.'-'.$valueSlug,
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
            $externalVariantId = $this->limitDatabaseString('microlife-'.$product->external_id.'-'.$sourceExternalId);

            if (isset($seenExternalIds[$externalVariantId])) {
                continue;
            }

            $seenExternalIds[$externalVariantId] = true;
            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();

            $label = $this->variantLabel($candidate);
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
        $externalVariantId = $this->limitDatabaseString('microlife-'.$product->external_id.'-default');
        $variant = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->where('external_variant_id', $externalVariantId)
            ->first();
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = $this->vatRateForProduct($scraped);
        $baseSku = $this->parentSku($scraped, $product->external_id);

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
        $optionValues = array_values(array_filter(
            $candidate['option_values'] ?? [],
            static fn (mixed $option): bool => is_array($option),
        ));

        if ($optionValues === []) {
            $size = $this->stringOrNull($candidate['size'] ?? null);
            $color = $this->stringOrNull($candidate['color'] ?? null);

            if ($size !== null) {
                $optionValues[] = [
                    'attribute' => 'Rozmiar',
                    'value' => $size,
                ];
            }

            if ($color !== null) {
                $optionValues[] = [
                    'attribute' => 'Kolor',
                    'value' => $color,
                ];
            }
        }

        foreach ($optionValues as $index => $optionData) {
            $label = $this->stringOrNull($optionData['attribute'] ?? null)
                ?: $this->stringOrNull($optionData['label'] ?? null)
                    ?: $this->stringOrNull($optionData['name'] ?? null);
            $value = $this->stringOrNull($optionData['value'] ?? null);

            if ($label === null || $value === null || ! $this->isSafeFilterAttributeValue($value)) {
                continue;
            }

            $code = Str::slug($label);

            if ($code === '') {
                $code = substr(sha1($label), 0, 12);
            }

            $attribute = $this->resolveAttribute(
                externalAttributeId: 'microlife-variant-'.$code,
                name: $label,
                displayType: $this->displayTypeForAttribute($code, $label),
            );
            $valueSlug = Str::slug($value);

            if ($valueSlug === '') {
                $valueSlug = substr(sha1($value), 0, 12);
            }

            $attributeValue = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'microlife-variant-'.$code.'-'.$valueSlug,
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

            $existingImage = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('source_url', $url)
                ->first();
            $imported = null;
            $downloadErrors = [];

            foreach ($this->imageDownloadUrls($url) as $downloadUrl) {
                try {
                    $imported = $this->remoteImageImporter->import(
                        url: $downloadUrl,
                        directory: 'products/microlife/'.$product->external_id.'/gallery',
                        disk: 'public',
                        allowedHosts: self::ASSET_ALLOWED_HOSTS,
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
                if ($existingImage !== null) {
                    $existingImage->update([
                        'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                        'title' => $this->stringOrNull($imageData['title'] ?? null),
                        'sort_order' => $index,
                        'is_main' => $index === 0,
                    ]);
                    $syncedImageIds[] = $existingImage->id;
                    $this->warnings[] = 'Image download failed; existing localized image was preserved: '.$url;
                } else {
                    $this->warnings[] = 'Image skipped: '.$url.' — '.implode(' | ', $downloadErrors);
                }

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
        return $this->assetRequestHeaders($scraped, 'image');
    }

    /**
     * @return list<string>
     */
    private function imageDownloadUrls(string $url): array
    {
        $httpsUrl = (string) preg_replace('~^http://~i', 'https://', $url);

        return array_values(array_unique([$httpsUrl, $url]));
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<list<string>>
     */
    private function categoryPaths(array $scraped): array
    {
        $sourcePaths = [];

        foreach (($scraped['category_paths'] ?? $scraped['source_category_paths'] ?? []) as $path) {
            $normalized = $this->normalizeCategoryPath($path);

            if ($normalized !== []) {
                $sourcePaths[] = $normalized;
            }
        }

        if ($sourcePaths === []) {
            $normalized = $this->normalizeCategoryPath($scraped['source_category_path'] ?? null);

            if ($normalized !== []) {
                $sourcePaths[] = $normalized;
            }
        }

        if ($sourcePaths === []) {
            $normalized = $this->normalizeCategoryPath($scraped['categories'] ?? null);

            if ($normalized !== []) {
                $sourcePaths[] = $normalized;
            }
        }

        if ($sourcePaths === []) {
            $category = $this->stringOrNull($scraped['category'] ?? null);

            if ($category !== null) {
                $sourcePaths[] = [$category];
            }
        }

        $branch = $this->catalogueType($scraped) === 'professional'
            ? 'Produkty profesjonalne'
            : 'Produkty';
        $paths = [];

        foreach ($sourcePaths !== [] ? $sourcePaths : [[]] as $sourcePath) {
            $path = array_values(array_unique(['Microlife', $branch, ...$sourcePath]));
            $paths[implode("\x1F", $path)] = $path;
        }

        return array_values($paths);
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

        $productCode = $this->stringOrNull($scraped['product_code'] ?? null);

        if ($productCode !== null) {
            $payloads[] = [
                'code' => 'kod-produktu',
                'label' => 'Kod produktu',
                'value' => $productCode,
                'slug' => Str::slug($productCode),
            ];
        }

        $payloads[] = [
            'code' => 'producent',
            'label' => 'Producent',
            'value' => 'Microlife',
            'slug' => 'microlife',
        ];
        $payloads[] = [
            'code' => 'typ-katalogu',
            'label' => 'Typ katalogu',
            'value' => $this->catalogueType($scraped) === 'professional'
                ? 'Profesjonalny'
                : 'Konsumencki',
            'slug' => $this->catalogueType($scraped),
        ];
        $payloads[] = [
            'code' => 'wyrob-medyczny',
            'label' => 'Wyrób medyczny',
            'value' => $this->booleanValue($scraped['is_medical_device'] ?? null) === true ? 'Tak' : 'Nie',
            'slug' => $this->booleanValue($scraped['is_medical_device'] ?? null) === true ? 'tak' : 'nie',
        ];

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
            $slug = 'microlife-attribute-'.substr(sha1($name), 0, 12);
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
            ?: $this->stringOrNull($scraped['seo_description'] ?? null)
                ?: $this->stringOrNull($scraped['description'] ?? null);

        if ($summary === null) {
            return null;
        }

        return '<p>'.e(Str::limit(strip_tags($summary), 320, '')).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped, array $documentContext): ?string
    {
        $sections = [];
        $description = $this->cleanImportedHtml($this->stringOrNull($scraped['description_html'] ?? null));

        if ($description === null) {
            $description = $this->listFromStringsHtml($scraped['description_items'] ?? []);
        }

        if ($description === null) {
            $plain = $this->stringOrNull($scraped['description'] ?? null);
            $description = $plain !== null ? '<p>'.e($plain).'</p>' : null;
        }

        if ($description !== null) {
            $sections[] = '<section class="microlife-description"><h2>Opis produktu</h2>'.$description.'</section>';
        }

        $features = $this->featuresHtml($scraped['features'] ?? []);

        if ($features !== null) {
            $sections[] = '<section class="microlife-features"><h2>Najważniejsze cechy</h2>'.$features.'</section>';
        }

        $specifications = $this->listFromStringsHtml($scraped['specification_items'] ?? []);

        if ($specifications !== null) {
            $sections[] = '<section class="microlife-specifications"><h2>Specyfikacja</h2>'.$specifications.'</section>';
        }

        $documents = $this->documentsSection($documentContext);

        if ($documents !== null) {
            $sections[] = $documents;
        }

        $videos = $this->linksSection('Wideo', $scraped['videos'] ?? [], 'title');

        if ($videos !== null) {
            $sections[] = '<section class="microlife-videos">'.$videos.'</section>';
        }

        $related = $this->linksSection('Powiązane produkty', $scraped['related_products'] ?? [], 'name');

        if ($related !== null) {
            $sections[] = '<section class="microlife-related-products">'.$related.'</section>';
        }

        $buyNowUrl = $this->safeExternalUrl($scraped['buy_now_url'] ?? null);

        if ($buyNowUrl !== null) {
            $sections[] = '<section class="microlife-buy-now"><h2>Zakup produktu</h2><p><a href="'.e($buyNowUrl).'" target="_blank" rel="noopener noreferrer">Kup teraz</a></p></section>';
        }

        $rows = $this->productMetaRows($scraped);

        if ($rows !== []) {
            $sections[] = '<section class="microlife-details">'.$this->metaTableHtml($rows).'</section>';
        }

        return $sections !== [] ? implode('
', $sections) : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array{label: string, value: string}>
     */
    private function productMetaRows(array $scraped): array
    {
        $rows = [];
        $seenLabels = [];

        $this->appendMetaRow($rows, $seenLabels, 'Producent', 'Microlife');
        $this->appendMetaRow(
            $rows,
            $seenLabels,
            'Typ katalogu',
            $this->catalogueType($scraped) === 'professional' ? 'Profesjonalny' : 'Konsumencki',
        );
        $this->appendMetaRow(
            $rows,
            $seenLabels,
            'Kod produktu',
            $this->stringOrNull($scraped['product_code'] ?? null),
        );
        $this->appendMetaRow(
            $rows,
            $seenLabels,
            'Wyrób medyczny',
            $this->booleanValue($scraped['is_medical_device'] ?? null) === true ? 'Tak' : 'Nie',
        );

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

    private function listFromStringsHtml(mixed $items): ?string
    {
        if (! is_array($items)) {
            return null;
        }

        $normalized = [];

        foreach ($items as $item) {
            $value = $this->stringOrNull($item);

            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return null;
        }

        return '<ul>'.implode('', array_map(
            static fn (string $item): string => '<li>'.e($item).'</li>',
            array_values(array_unique($normalized)),
        )).'</ul>';
    }

    private function sourceTableHtml(string $title, mixed $table): ?string
    {
        if (! is_array($table)) {
            return null;
        }

        $columns = array_values(array_filter(
            $table['sizes'] ?? $table['models'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $rows = array_values(array_filter(
            $table['rows'] ?? [],
            static fn (mixed $row): bool => is_array($row),
        ));

        if ($columns === []) {
            return null;
        }

        $headerLabel = $this->stringOrNull($table['header_label'] ?? null) ?: 'Opcja';
        $html = '<h2>'.e($title).'</h2><table><thead><tr><th>'.e($headerLabel).'</th>';

        foreach ($columns as $column) {
            $html .= '<th>'.e($column).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $label = $this->stringOrNull($row['label'] ?? null);

            if ($label === null) {
                continue;
            }

            $html .= '<tr><th>'.e($label).'</th>';
            $values = is_array($row['values'] ?? null) ? $row['values'] : [];

            foreach ($columns as $column) {
                $html .= '<td>'.e((string) ($values[$column] ?? '')).'</td>';
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function featuresHtml(mixed $features): ?string
    {
        if (! is_array($features)) {
            return null;
        }

        $items = [];

        foreach ($features as $feature) {
            if (! is_array($feature)) {
                continue;
            }

            $title = $this->stringOrNull($feature['title'] ?? null);
            $description = $this->stringOrNull($feature['description'] ?? null);

            if ($title === null && $description === null) {
                continue;
            }

            $content = $title !== null ? '<strong>'.e($title).'</strong>' : '';

            if ($description !== null) {
                $content .= ($content !== '' ? ': ' : '').e($description);
            }

            $items[] = '<li>'.$content.'</li>';
        }

        return $items !== [] ? '<ul>'.implode('', $items).'</ul>' : null;
    }

    private function linksSection(string $title, mixed $links, string $labelField): ?string
    {
        if (! is_array($links)) {
            return null;
        }

        $items = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = $this->safeExternalUrl($link['url'] ?? null);
            $label = $this->stringOrNull($link[$labelField] ?? null) ?: $url;

            if ($url === null || $label === null) {
                continue;
            }

            $items[] = '<li><a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a></li>';
        }

        return $items !== [] ? '<h2>'.e($title).'</h2><ul>'.implode('', $items).'</ul>' : null;
    }

    /**
     * @param  array{documents: list<array<string, mixed>>}  $documentContext
     */
    private function documentsSection(array $documentContext): ?string
    {
        $items = [];

        foreach ($documentContext['documents'] as $document) {
            $url = $this->safeLinkUrl($document['url'] ?? null);
            $name = $this->stringOrNull($document['name'] ?? null) ?: 'Pobierz dokument';

            if ($url === null) {
                continue;
            }

            $details = array_values(array_filter([
                $this->stringOrNull($document['file_type'] ?? null),
                $this->stringOrNull($document['file_size'] ?? null),
            ]));
            $suffix = $details !== [] ? ' <small>('.e(implode(', ', $details)).')</small>' : '';
            $items[] = '<li><a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($name).'</a>'.$suffix.'</li>';
        }

        return $items !== []
            ? '<section class="microlife-documents"><h2>Dokumenty i materiały do pobrania</h2><ul>'.implode('', $items).'</ul></section>'
            : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array{documents: list<array<string, mixed>>}
     */
    private function documentContext(
        array $scraped,
        string $externalId,
        bool $importDocuments,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
        bool $verifyTls,
    ): array {
        $documents = [];
        $keepPaths = [];
        $downloadIndex = 0;

        foreach (($scraped['downloads'] ?? []) as $download) {
            if (! is_array($download)) {
                continue;
            }

            $sourceUrl = $this->safeExternalUrl($download['url'] ?? null);

            if ($sourceUrl === null) {
                continue;
            }

            $name = $this->stringOrNull($download['name'] ?? null) ?: $this->documentNameFromUrl($sourceUrl);
            $fileType = $this->stringOrNull($download['file_type'] ?? null);
            $fileSize = $this->stringOrNull($download['file_size'] ?? null);
            $document = [
                'name' => $name,
                'url' => $sourceUrl,
                'source_url' => $sourceUrl,
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'localized' => false,
            ];

            if ($importDocuments && $this->isDownloadableMicrolifeDocument($sourceUrl)) {
                if ($downloadIndex > 0 && $requestDelayMs > 0) {
                    usleep($requestDelayMs * 1000);
                }

                $downloadIndex++;

                try {
                    $localized = $this->downloadDocument(
                        scraped: $scraped,
                        sourceUrl: $sourceUrl,
                        name: $name,
                        externalId: $externalId,
                        timeoutSeconds: $timeoutSeconds,
                        attempts: $attempts,
                        retryDelayMs: $retryDelayMs,
                        verifyTls: $verifyTls,
                    );
                    $document = array_replace($document, $localized);
                    $keepPaths[] = $localized['path'];
                } catch (Throwable $exception) {
                    $existingPath = $this->documentStoragePath($sourceUrl, $name, $externalId);

                    if (Storage::disk('public')->exists($existingPath)) {
                        $document['url'] = Storage::disk('public')->url($existingPath);
                        $document['localized'] = true;
                        $document['path'] = $existingPath;
                        $keepPaths[] = $existingPath;
                        $this->warnings[] = 'Document download failed; existing localized document was preserved: '.$sourceUrl;
                    } else {
                        $this->warnings[] = 'Document kept as a source link because localization failed: '.$sourceUrl.' — '.$exception->getMessage();
                    }
                }
            }

            $documents[$sourceUrl] = $document;
        }

        if ($importDocuments) {
            $this->cleanupDocumentDirectory($externalId, $keepPaths);
        }

        return ['documents' => array_values($documents)];
    }

    private function isDownloadableMicrolifeDocument(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $extension = mb_strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $this->isAllowedHost($host, self::ASSET_ALLOWED_HOSTS)
            && in_array($extension, ['pdf', 'zip'], true);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, mixed>
     */
    private function downloadDocument(
        array $scraped,
        string $sourceUrl,
        string $name,
        string $externalId,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        bool $verifyTls,
    ): array {
        $response = Http::withOptions(['verify' => $verifyTls])
            ->timeout($timeoutSeconds)
            ->retry($attempts, $retryDelayMs)
            ->withHeaders($this->assetRequestHeaders($scraped, 'document'))
            ->get($sourceUrl);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }

        $contents = $response->body();

        if ($contents === '') {
            throw new RuntimeException('Downloaded document is empty.');
        }

        if (strlen($contents) > self::MAX_DOCUMENT_SIZE_BYTES) {
            throw new RuntimeException('Downloaded document exceeds the 30 MB limit.');
        }

        $extension = mb_strtolower((string) pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION));

        if ($extension === 'pdf' && ! str_starts_with($contents, '%PDF')) {
            throw new RuntimeException('Downloaded PDF signature is invalid.');
        }

        if ($extension === 'zip' && ! str_starts_with($contents, 'PK')) {
            throw new RuntimeException('Downloaded ZIP signature is invalid.');
        }

        $path = $this->documentStoragePath($sourceUrl, $name, $externalId);
        Storage::disk('public')->put($path, $contents);

        return [
            'url' => Storage::disk('public')->url($path),
            'source_url' => $sourceUrl,
            'path' => $path,
            'file_type' => strtoupper($extension),
            'file_size' => strlen($contents).' B',
            'localized' => true,
        ];
    }

    private function documentStoragePath(string $sourceUrl, string $name, string $externalId): string
    {
        $extension = mb_strtolower((string) pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        $extension = in_array($extension, ['pdf', 'zip'], true) ? $extension : 'bin';
        $filename = Str::slug(pathinfo($name, PATHINFO_FILENAME));

        if ($filename === '') {
            $filename = 'document';
        }

        return 'products/microlife/'.$externalId.'/documents/'.$filename.'-'.substr(hash('sha256', $sourceUrl), 0, 16).'.'.$extension;
    }

    /**
     * @param  list<string>  $keepPaths
     */
    private function cleanupDocumentDirectory(string $externalId, array $keepPaths): void
    {
        $directory = 'products/microlife/'.$externalId.'/documents';
        $keep = array_fill_keys($keepPaths, true);

        foreach (Storage::disk('public')->allFiles($directory) as $path) {
            if (! isset($keep[$path])) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function documentNameFromUrl(string $url): string
    {
        $name = rawurldecode((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
        $name = trim(preg_replace('/[_-]+/u', ' ', $name) ?? $name);

        return $name !== '' ? $name : 'Dokument Microlife';
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, string>
     */
    private function assetRequestHeaders(array $scraped, string $destination): array
    {
        $referer = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: 'https://www.microlife.pl/';

        return [
            'User-Agent' => self::IMAGE_BROWSER_USER_AGENT,
            'Accept' => $destination === 'image'
                ? 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'
                : 'application/pdf,application/zip,application/octet-stream;q=0.9,*/*;q=0.5',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en-US;q=0.7,en;q=0.6',
            'Referer' => $referer,
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Fetch-Dest' => $destination,
            'Sec-Fetch-Mode' => 'no-cors',
            'Sec-Fetch-Site' => 'same-origin',
        ];
    }

    private function safeLinkUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return $this->safeExternalUrl($url);
    }

    private function safeExternalUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function catalogueType(array $scraped): string
    {
        return $this->stringOrNull($scraped['catalogue_type'] ?? null) === 'professional'
            ? 'professional'
            : 'consumer';
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function isAllowedHost(string $host, array $allowedHosts): bool
    {
        $host = mb_strtolower($host);

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = mb_strtolower(ltrim($allowedHost, '.'));

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
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
            ?: $this->stringOrNull($scraped['external_id'] ?? null);

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $source = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null);

        if ($source === null) {
            throw new RuntimeException('Microlife product is missing external_product_id and source URL.');
        }

        return hash('sha256', $source);
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $slug = $segments[array_key_last($segments)] ?? null;

        return is_string($slug) && $slug !== '' ? (Str::slug($slug) ?: null) : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        $source = $this->stringOrNull($scraped['product_code'] ?? null)
            ?: $this->stringOrNull($scraped['name'] ?? null)
                ?: 'MICROLIFE-'.substr($externalId, 0, 16);

        return $this->limitDatabaseString($this->variantSkuPart($source));
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
        $candidateSku = $this->stringOrNull($candidate['sku'] ?? null)
            ?: $this->stringOrNull($candidate['model_code'] ?? null);

        if ($candidateSku !== null) {
            return $this->uniqueVariantSku($this->variantSkuPart($candidateSku), $currentVariantId);
        }

        $baseSku = $this->parentSku($scraped, $externalId);
        $variantPart = $this->variantSkuPart($label ?: $sourceExternalId);

        return $this->uniqueVariantSku($baseSku.'-'.$variantPart, $currentVariantId);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function variantLabel(array $candidate): ?string
    {
        return $this->stringOrNull($candidate['size'] ?? null)
            ?: $this->stringOrNull($candidate['model_code'] ?? null)
                ?: $this->stringOrNull($candidate['color'] ?? null)
                    ?: $this->stringOrNull($candidate['name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $attributeData
     */
    private function isVariantOptionAttribute(array $attributeData, string $label): bool
    {
        $code = Str::of((string) ($attributeData['code'] ?? ''))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->value();
        $normalizedLabel = Str::of($label)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->value();

        return in_array($code, ['rozmiar', 'model', 'kolor'], true)
            || in_array($normalizedLabel, ['rozmiar', 'model', 'kolor'], true);
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
        $baseSlug = Str::slug($baseSlug) ?: 'microlife-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            $candidate = $suffix === 2
                ? $baseSlug.'-microlife-'.$externalId
                : $baseSlug.'-microlife-'.$externalId.'-'.$suffix;
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
        $baseSlug = Str::slug($baseSlug) ?: 'microlife-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeSlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'microlife-attribute';
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
