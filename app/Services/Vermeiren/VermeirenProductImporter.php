<?php

declare(strict_types=1);

namespace App\Services\Vermeiren;

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
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class VermeirenProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    private const MAX_FILTER_VALUE_LENGTH = 190;

    private const IMAGE_METADATA_MAX_LENGTH = 255;

    private const DOCUMENT_DISK = 'public';

    private const DOCUMENT_MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @var list<string>
     */
    private const REMOTE_ASSET_ALLOWED_HOSTS = [
        'vermeiren.pl',
        'www.vermeiren.pl',
        'vermeiren.be',
        'domino03.vermeiren.be',
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
        bool $importDocuments = true,
        bool $importColorImages = true,
        int $assetTimeoutSeconds = 30,
        int $assetAttempts = 5,
        int $assetRetryDelayMs = 5000,
        int $assetRequestDelayMs = 250,
        bool $verifyTls = true,
    ): array {
        $this->warnings = [];
        $externalId = $this->externalProductId($scraped);
        $assetTimeoutSeconds = max(1, $assetTimeoutSeconds);
        $assetAttempts = max(1, $assetAttempts);
        $assetRetryDelayMs = max(0, $assetRetryDelayMs);
        $assetRequestDelayMs = max(0, $assetRequestDelayMs);
        $documentContext = $this->documentContext(
            scraped: $scraped,
            externalId: $externalId,
            importDocuments: $importDocuments,
            timeoutSeconds: $assetTimeoutSeconds,
            attempts: $assetAttempts,
            retryDelayMs: $assetRetryDelayMs,
            requestDelayMs: $assetRequestDelayMs,
            verifyTls: $verifyTls,
        );

        $product = DB::transaction(function () use (
            $scraped,
            $externalId,
            $status,
            $importImages,
            $imageLimit,
            $importColorImages,
            $assetTimeoutSeconds,
            $assetAttempts,
            $assetRetryDelayMs,
            $assetRequestDelayMs,
            $verifyTls,
            $documentContext,
        ): Product {
            $product = $this->resolveProduct($scraped, $externalId, $status, $documentContext);
            $this->syncCategories($product, $scraped);
            $colorBindings = $this->syncProductAttributes($product, $scraped);
            $this->syncDefaultVariant($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages(
                    product: $product,
                    scraped: $scraped,
                    imageLimit: $imageLimit,
                    timeoutSeconds: $assetTimeoutSeconds,
                    attempts: $assetAttempts,
                    retryDelayMs: $assetRetryDelayMs,
                    requestDelayMs: $assetRequestDelayMs,
                    verifyTls: $verifyTls,
                );
            }

            if ($importImages && $importColorImages) {
                $this->syncColorImages(
                    product: $product,
                    colorBindings: $colorBindings,
                    scraped: $scraped,
                    timeoutSeconds: $assetTimeoutSeconds,
                    attempts: $assetAttempts,
                    retryDelayMs: $assetRetryDelayMs,
                    requestDelayMs: $assetRequestDelayMs,
                    verifyTls: $verifyTls,
                );
            }

            return $product->fresh([
                'categories.parent',
                'attributeValues.attribute',
                'images',
                'attributeValueImages.attributeValue.attribute',
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
     * @param  array{documents: list<array<string, mixed>>}  $documentContext
     */
    private function resolveProduct(
        array $scraped,
        string $externalId,
        ProductStatus $status,
        array $documentContext,
    ): Product {
        $product = Product::withTrashed()
            ->where('external_source', 'vermeiren')
            ->where('external_id', $externalId)
            ->first();

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['selected_name'] ?? null)
                ?: $this->stringOrNull($scraped['sku'] ?? null)
                    ?: 'Vermeiren product '.substr($externalId, 0, 12);
        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'vermeiren-product-'.substr($externalId, 0, 12);
        }

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped, $documentContext),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($scraped),
            'status' => $status,
            'external_source' => 'vermeiren',
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
                $syncPayload[$category->id] = ['is_primary' => false];
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
            $baseSlug = 'vermeiren-category-'.substr(sha1(implode('|', $pathSegments)), 0, 12);
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
     * @return list<array{attribute_value: AttributeValue, color: array<string, mixed>}>
     */
    private function syncProductAttributes(Product $product, array $scraped): array
    {
        $valueIds = [];
        $seen = [];
        $colorBindings = [];

        $brand = $this->brandName($scraped);

        if ($brand !== null) {
            $attribute = $this->resolveAttribute('vermeiren-brand', 'Producent', AttributeDisplayType::SELECT);
            $value = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'vermeiren-brand-'.substr(sha1(Str::lower($brand)), 0, 16),
                value: $brand,
                sortOrder: 0,
            );
            $valueIds[] = $value->id;
            $seen[$attribute->id.'|'.$value->id] = true;
        }

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $attribute = $this->resolveAttribute('vermeiren-medical-device', 'Wyrób medyczny', AttributeDisplayType::SELECT);
            $value = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'vermeiren-medical-device-yes',
                value: 'Tak',
                sortOrder: 0,
            );
            $valueIds[] = $value->id;
            $seen[$attribute->id.'|'.$value->id] = true;
        }

        foreach ($this->technicalSpecifications($scraped) as $index => $specification) {
            $label = $this->stringOrNull($specification['label'] ?? null);
            $valueText = $this->stringOrNull($specification['value'] ?? null);

            if ($label === null || $valueText === null) {
                continue;
            }

            if (! $this->isSafeFilterAttributeValue($valueText)) {
                $this->warnings[] = 'Technical specification skipped as a filter because its value is too long: '.$label;

                continue;
            }

            $key = $this->stringOrNull($specification['key'] ?? null) ?: Str::slug($label);
            $key = $key !== '' ? $key : substr(sha1($label), 0, 16);
            $attribute = $this->resolveAttribute(
                externalAttributeId: 'vermeiren-spec-'.$this->limitDatabaseString($key),
                name: $label,
                displayType: AttributeDisplayType::SELECT,
            );
            $value = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'vermeiren-spec-'.substr(sha1($key.'|'.$valueText), 0, 24),
                value: $valueText,
                sortOrder: $index,
            );
            $dedupeKey = $attribute->id.'|'.$value->id;

            if (! isset($seen[$dedupeKey])) {
                $valueIds[] = $value->id;
                $seen[$dedupeKey] = true;
            }
        }

        foreach ($this->colors($scraped) as $index => $color) {
            $name = $this->stringOrNull($color['name'] ?? null);

            if ($name === null || ! $this->isSafeFilterAttributeValue($name)) {
                continue;
            }

            $type = $this->colorType($color);
            $attribute = $this->resolveAttribute(
                externalAttributeId: 'vermeiren-color-'.$type,
                name: $this->colorAttributeName($type),
                displayType: AttributeDisplayType::COLOR_SWATCH,
            );
            $value = $this->resolveAttributeValue(
                attribute: $attribute,
                externalOptionId: 'vermeiren-color-'.$type.'-'.substr(sha1(Str::lower($name)), 0, 20),
                value: $name,
                sortOrder: $index,
            );
            $dedupeKey = $attribute->id.'|'.$value->id;

            if (! isset($seen[$dedupeKey])) {
                $valueIds[] = $value->id;
                $seen[$dedupeKey] = true;
            }

            $colorBindings[] = [
                'attribute_value' => $value,
                'color' => $color,
            ];
        }

        $product->attributeValues()->sync(array_values(array_unique($valueIds)));

        return $colorBindings;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncDefaultVariant(Product $product, array $scraped, ProductStatus $status): void
    {
        $externalVariantId = $this->limitDatabaseString('vermeiren-'.$product->external_id.'-default');
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = $this->vatRateForProduct($scraped);
        $variant = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->where('external_variant_id', $externalVariantId)
            ->first();
        $attributes = [
            'product_id' => $product->id,
            'external_variant_id' => $externalVariantId,
            'sku' => $this->uniqueNullableSku(
                $this->stringOrNull($scraped['sku'] ?? null),
                $product->id,
                $externalVariantId,
                $variant?->id,
            ),
            'status' => $this->variantStatusForProductStatus($status),
            'price_net_amount' => $grossAmount === null ? null : $vatRate->netFromGross($grossAmount),
            'price_gross_amount' => $grossAmount,
            'currency' => Currency::PLN,
            'vat_rate' => $vatRate,
            'stock_status' => $this->stockStatus($scraped),
            'is_default' => true,
        ];

        if ($variant !== null) {
            if ($variant->trashed()) {
                $variant->restore();
            }

            $variant->update($attributes);
        } else {
            $variant = ProductVariant::query()->create($attributes);
        }

        $variant->attributeValues()->sync([]);

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('id', '!=', $variant->id)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(
        Product $product,
        array $scraped,
        ?int $imageLimit,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
        bool $verifyTls,
    ): void {
        $images = [];
        $seen = [];

        foreach (($scraped['images'] ?? []) as $imageData) {
            if (! is_array($imageData)) {
                continue;
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null || ! $this->isUsableRemoteImageUrl($url) || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $images[] = $imageData;
        }

        if ($imageLimit !== null) {
            $images = array_slice($images, 0, max(0, $imageLimit));
        }

        if ($images === []) {
            $this->warnings[] = 'No Vermeiren product images were supplied; existing images were preserved.';

            return;
        }

        $syncedImageIds = [];
        $mainAssigned = false;

        foreach ($images as $index => $imageData) {
            if ($index > 0 && $requestDelayMs > 0) {
                usleep($requestDelayMs * 1000);
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null) {
                continue;
            }

            try {
                $imported = $this->remoteImageImporter->import(
                    url: $url,
                    directory: 'products/vermeiren/'.$product->external_id.'/gallery',
                    disk: 'public',
                    allowedHosts: self::REMOTE_ASSET_ALLOWED_HOSTS,
                    requestOptions: [
                        'timeout_seconds' => $timeoutSeconds,
                        'retry_attempts' => $attempts,
                        'retry_delay_ms' => $retryDelayMs,
                        'verify_tls' => $verifyTls,
                        'headers' => $this->assetRequestHeaders($scraped, 'image'),
                    ],
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Product image skipped for Vermeiren product '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $isMain = ! $mainAssigned;

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
                    'alt_text' => $this->imageMetadataText($imageData['alt'] ?? null, $product->name),
                    'title' => $this->imageMetadataText($imageData['title'] ?? null),
                    'sort_order' => $index,
                    'is_main' => $isMain,
                ],
            );
            $syncedImageIds[] = $image->id;
            $mainAssigned = true;
        }

        if ($syncedImageIds === []) {
            $this->warnings[] = 'All Vermeiren product image downloads failed; existing images were preserved.';

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
     * @param  list<array{attribute_value: AttributeValue, color: array<string, mixed>}>  $colorBindings
     * @param  array<string, mixed>  $scraped
     */
    private function syncColorImages(
        Product $product,
        array $colorBindings,
        array $scraped,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
        bool $verifyTls,
    ): void {
        $pathPrefix = 'products/vermeiren/'.$product->external_id.'/colors/';
        $candidates = [];
        $seen = [];

        foreach ($colorBindings as $binding) {
            $url = $this->stringOrNull($binding['color']['image_url'] ?? null);

            if ($url === null || isset($seen[$binding['attribute_value']->id.'|'.$url])) {
                continue;
            }

            $seen[$binding['attribute_value']->id.'|'.$url] = true;
            $candidates[] = $binding;
        }

        if ($candidates === []) {
            ProductAttributeValueImage::query()
                ->where('product_id', $product->id)
                ->where('path', 'like', $pathPrefix.'%')
                ->get()
                ->each(function (ProductAttributeValueImage $image): void {
                    if ($image->path !== '' && Storage::disk($image->disk)->exists($image->path)) {
                        Storage::disk($image->disk)->delete($image->path);
                    }

                    $image->delete();
                });

            return;
        }

        $syncedIds = [];

        foreach ($candidates as $index => $binding) {
            if ($index > 0 && $requestDelayMs > 0) {
                usleep($requestDelayMs * 1000);
            }

            $value = $binding['attribute_value'];
            $color = $binding['color'];
            $url = $this->stringOrNull($color['image_url'] ?? null);

            if ($url === null) {
                continue;
            }

            try {
                $imported = $this->remoteImageImporter->import(
                    url: $url,
                    directory: $pathPrefix.$this->colorType($color),
                    disk: 'public',
                    allowedHosts: self::REMOTE_ASSET_ALLOWED_HOSTS,
                    requestOptions: [
                        'timeout_seconds' => $timeoutSeconds,
                        'retry_attempts' => $attempts,
                        'retry_delay_ms' => $retryDelayMs,
                        'verify_tls' => $verifyTls,
                        'headers' => $this->assetRequestHeaders($scraped, 'image'),
                    ],
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Color swatch skipped for Vermeiren product '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $image = ProductAttributeValueImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'attribute_value_id' => $value->id,
                    'source_url' => $url,
                ],
                [
                    'disk' => $imported['disk'],
                    'path' => $imported['path'],
                    'mime_type' => $imported['mime_type'],
                    'file_size' => $imported['file_size'],
                    'sha256' => $imported['sha256'],
                    'alt_text' => $value->value,
                    'title' => $value->value,
                    'sort_order' => $index,
                    'is_main' => false,
                ],
            );
            $syncedIds[] = $image->id;
        }

        if ($syncedIds === []) {
            $this->warnings[] = 'All Vermeiren color swatch downloads failed; existing color images were preserved.';

            return;
        }

        ProductAttributeValueImage::query()
            ->where('product_id', $product->id)
            ->where('path', 'like', $pathPrefix.'%')
            ->whereNotIn('id', $syncedIds)
            ->get()
            ->each(function (ProductAttributeValueImage $image): void {
                if ($image->path !== '' && Storage::disk($image->disk)->exists($image->path)) {
                    Storage::disk($image->disk)->delete($image->path);
                }

                $image->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<list<string>>
     */
    private function categoryPaths(array $scraped): array
    {
        $paths = [];

        foreach (($scraped['category_paths'] ?? []) as $path) {
            $normalized = $this->normalizeCategoryPath($path);

            if ($normalized !== [] && ! in_array($normalized, $paths, true)) {
                $paths[] = $normalized;
            }
        }

        if ($paths !== []) {
            return $paths;
        }

        $fallback = $this->normalizeCategoryPath([
            $scraped['product_group'] ?? null,
            $scraped['sub_group'] ?? null,
            $scraped['sub_sub_group'] ?? null,
            $scraped['category'] ?? null,
        ]);

        return $fallback === [] ? [] : [$fallback];
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
            $value = $this->stringOrNull($segment);

            if ($value !== null && ($segments === [] || end($segments) !== $value)) {
                $segments[] = $value;
            }
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function technicalSpecifications(array $scraped): array
    {
        $specifications = [];
        $seen = [];

        foreach (($scraped['technical_specifications'] ?? []) as $specification) {
            if (! is_array($specification)) {
                continue;
            }

            $label = $this->stringOrNull($specification['label'] ?? null);
            $value = $this->stringOrNull($specification['value'] ?? null);

            if ($label === null || $value === null) {
                continue;
            }

            $key = Str::lower($label).'|'.Str::lower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $specifications[] = $specification;
        }

        if ($specifications !== []) {
            return $specifications;
        }

        foreach (($scraped['attributes'] ?? []) as $label => $value) {
            if (! is_string($label)) {
                continue;
            }

            $value = $this->stringOrNull($value);

            if ($value !== null) {
                $specifications[] = [
                    'key' => Str::slug($label),
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        return $specifications;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function colors(array $scraped): array
    {
        return array_values(array_filter(
            $scraped['colors'] ?? [],
            static fn (mixed $color): bool => is_array($color),
        ));
    }

    /**
     * @param  array<string, mixed>  $color
     */
    private function colorType(array $color): string
    {
        $type = Str::of((string) ($color['type'] ?? 'color'))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return in_array($type, ['upholstery', 'frame'], true) ? $type : 'color';
    }

    private function colorAttributeName(string $type): string
    {
        return match ($type) {
            'upholstery' => 'Kolor tapicerki',
            'frame' => 'Kolor ramy',
            default => 'Kolor',
        };
    }

    private function resolveAttribute(
        string $externalAttributeId,
        string $name,
        AttributeDisplayType $displayType,
    ): Attribute {
        $externalAttributeId = $this->limitDatabaseString($externalAttributeId);
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'vermeiren-attribute-'.substr(sha1($name), 0, 12);
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
        $externalOptionId = $this->limitDatabaseString($externalOptionId);
        $value = $this->limitDatabaseString($value);
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
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $summary = $this->plainTextSnippet($scraped['short_description'] ?? null, 500)
            ?: $this->plainTextSnippet($scraped['description'] ?? null, 500);

        return $summary === null ? null : '<p>'.e($summary).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @param  array{documents: list<array<string, mixed>>}  $documentContext
     */
    private function productDescriptionHtml(array $scraped, array $documentContext): ?string
    {
        $sections = [];
        $descriptionHtml = $this->stringOrNull($scraped['description_html'] ?? null);

        if ($descriptionHtml === null) {
            $description = $this->stringOrNull($scraped['description'] ?? null);

            if ($description !== null) {
                $descriptionHtml = '<p>'.e($description).'</p>';
            }
        }

        if ($descriptionHtml !== null) {
            $sections[] = $descriptionHtml;
        }

        $specificationSection = $this->specificationSection($scraped);

        if ($specificationSection !== null) {
            $sections[] = $specificationSection;
        }

        $colorSection = $this->colorSection($scraped);

        if ($colorSection !== null) {
            $sections[] = $colorSection;
        }

        $optionsSection = $this->optionsSection($scraped);

        if ($optionsSection !== null) {
            $sections[] = $optionsSection;
        }

        $documentsSection = $this->documentsSection($documentContext);

        if ($documentsSection !== null) {
            $sections[] = $documentsSection;
        }

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $sections[] = '<section class="vermeiren-medical-notice"><p><strong>To jest wyrób medyczny. Używaj go zgodnie z instrukcją używania lub etykietą.</strong></p></section>';
        }

        if ($sections === []) {
            return null;
        }

        return $this->cleanImportedHtml(implode("\n", $sections));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function specificationSection(array $scraped): ?string
    {
        $rows = [];

        foreach ($this->technicalSpecifications($scraped) as $specification) {
            $label = $this->stringOrNull($specification['label'] ?? null);
            $value = $this->stringOrNull($specification['value'] ?? null);

            if ($label !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="vermeiren-specifications"><h2>Parametry techniczne</h2><table><tbody>'.implode('', array_values(array_unique($rows))).'</tbody></table></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function colorSection(array $scraped): ?string
    {
        $groups = [];

        foreach ($this->colors($scraped) as $color) {
            $name = $this->stringOrNull($color['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $groups[$this->colorAttributeName($this->colorType($color))][] = $name;
        }

        if ($groups === []) {
            return null;
        }

        $sections = [];

        foreach ($groups as $label => $names) {
            $items = array_map(
                static fn (string $name): string => '<li>'.e($name).'</li>',
                array_values(array_unique($names)),
            );
            $sections[] = '<h3>'.e($label).'</h3><ul>'.implode('', $items).'</ul>';
        }

        return '<section class="vermeiren-colors"><h2>Dostępne kolory</h2>'.implode('', $sections).'</section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function optionsSection(array $scraped): ?string
    {
        $items = [];
        $seen = [];

        foreach (($scraped['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = $this->stringOrNull($option['name'] ?? null);

            if ($name === null || isset($seen[Str::lower($name)])) {
                continue;
            }

            $seen[Str::lower($name)] = true;
            $items[] = '<li>'.e($name).'</li>';
        }

        if ($items === []) {
            return null;
        }

        return '<section class="vermeiren-options"><h2>Opcje dodatkowe</h2><ul>'.implode('', $items).'</ul></section>';
    }

    /**
     * @param  array{documents: list<array<string, mixed>>}  $documentContext
     */
    private function documentsSection(array $documentContext): ?string
    {
        $groups = [];

        foreach ($documentContext['documents'] as $document) {
            $label = $this->stringOrNull($document['label'] ?? null) ?: 'Dokument';
            $url = $this->stringOrNull($document['local_url'] ?? null)
                ?: $this->stringOrNull($document['source_url'] ?? null);

            if ($url === null) {
                continue;
            }

            $type = $this->stringOrNull($document['type'] ?? null) ?: 'document';
            $groups[$this->documentTypeLabel($type)][] = '<li><a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a></li>';
        }

        if ($groups === []) {
            return null;
        }

        $sections = [];

        foreach ($groups as $label => $items) {
            $sections[] = '<h3>'.e($label).'</h3><ul>'.implode('', $items).'</ul>';
        }

        return '<section class="vermeiren-documents"><h2>Dokumenty i materiały</h2>'.implode('', $sections).'</section>';
    }

    private function documentTypeLabel(string $type): string
    {
        return match ($type) {
            'brochure' => 'Katalogi',
            'order_form' => 'Formularze zamówienia',
            'manual' => 'Instrukcje i materiały wideo',
            'certificate' => 'Certyfikaty',
            'spare_part' => 'Części zamienne',
            default => 'Pozostałe dokumenty',
        };
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
        $seen = [];
        $downloadedPaths = [];
        $downloadIndex = 0;

        foreach (($scraped['documents'] ?? []) as $document) {
            if (! is_array($document)) {
                continue;
            }

            $url = $this->stringOrNull($document['url'] ?? null);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $type = $this->stringOrNull($document['type'] ?? null) ?: 'document';
            $label = $this->stringOrNull($document['name'] ?? null) ?: $this->documentNameFromUrl($url);
            $localized = [
                'type' => $type,
                'label' => $label,
                'source_url' => $url,
                'local_url' => $url,
            ];

            if ($importDocuments && $this->isDownloadableVermeirenDocument($url)) {
                if ($downloadIndex > 0 && $requestDelayMs > 0) {
                    usleep($requestDelayMs * 1000);
                }

                $downloadIndex++;

                try {
                    $download = $this->downloadDocument(
                        url: $url,
                        externalId: $externalId,
                        type: $type,
                        label: $label,
                        timeoutSeconds: $timeoutSeconds,
                        attempts: $attempts,
                        retryDelayMs: $retryDelayMs,
                        verifyTls: $verifyTls,
                        referer: $this->stringOrNull($scraped['canonical_url'] ?? null)
                            ?: $this->stringOrNull($scraped['source_url'] ?? null),
                    );
                    $localized['local_url'] = $download['url'];
                    $downloadedPaths[] = $download['path'];
                } catch (Throwable $exception) {
                    $this->warnings[] = 'Document kept as a source link for Vermeiren product '.$externalId.': '.$url.' — '.$exception->getMessage();
                }
            }

            $documents[] = $localized;
        }

        if ($importDocuments) {
            $this->cleanupDocumentDirectory($externalId, $downloadedPaths);
        }

        return ['documents' => $documents];
    }

    private function isDownloadableVermeirenDocument(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $this->isAllowedHost($host, self::REMOTE_ASSET_ALLOWED_HOSTS);
    }

    /**
     * @return array{url: string, path: string}
     */
    private function downloadDocument(
        string $url,
        string $externalId,
        string $type,
        string $label,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        bool $verifyTls,
        ?string $referer,
    ): array {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid document URL.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! $this->isAllowedHost($host, self::REMOTE_ASSET_ALLOWED_HOSTS)) {
            throw new RuntimeException('Disallowed document host ['.(string) $host.'].');
        }

        $request = Http::connectTimeout(min(10, $timeoutSeconds))
            ->timeout($timeoutSeconds)
            ->retry($attempts, $retryDelayMs)
            ->withHeaders(array_filter([
                'User-Agent' => self::IMAGE_BROWSER_USER_AGENT,
                'Accept' => 'application/pdf,application/octet-stream,*/*',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
                'Referer' => $referer,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        if (! $verifyTls) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to download document. HTTP '.$response->status());
        }

        $contents = $response->body();

        if ($contents === '') {
            throw new RuntimeException('Downloaded document is empty.');
        }

        if (strlen($contents) > self::DOCUMENT_MAX_BYTES) {
            throw new RuntimeException('Document is larger than '.self::DOCUMENT_MAX_BYTES.' bytes.');
        }

        $mimeType = $this->documentMimeType($response->header('Content-Type'));
        $extension = $this->documentExtension($url, $mimeType);
        $filename = Str::slug($label) ?: 'document';
        $hash = substr(sha1($url), 0, 10);
        $typeDirectory = Str::slug($type) ?: 'document';
        $path = 'products/vermeiren/'.$externalId.'/documents/'.$typeDirectory.'/'.$filename.'-'.$hash.'.'.$extension;

        Storage::disk(self::DOCUMENT_DISK)->put($path, $contents);

        return [
            'url' => Storage::disk(self::DOCUMENT_DISK)->url($path),
            'path' => $path,
        ];
    }

    /**
     * @param  list<string>  $keepPaths
     */
    private function cleanupDocumentDirectory(string $externalId, array $keepPaths): void
    {
        $directory = 'products/vermeiren/'.$externalId.'/documents';

        foreach (Storage::disk(self::DOCUMENT_DISK)->allFiles($directory) as $path) {
            if (! in_array($path, $keepPaths, true)) {
                Storage::disk(self::DOCUMENT_DISK)->delete($path);
            }
        }
    }

    private function documentMimeType(?string $contentType): string
    {
        if ($contentType === null) {
            return 'application/octet-stream';
        }

        return trim(explode(';', $contentType)[0]) ?: 'application/octet-stream';
    }

    private function documentExtension(string $url, string $mimeType): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
            return $extension;
        }

        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }

    private function documentNameFromUrl(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $filename = trim(preg_replace('/\s+/u', ' ', str_replace(['_', '-'], ' ', $filename)) ?? $filename);

        return $filename === '' ? 'Dokument' : Str::headline($filename);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, string>
     */
    private function assetRequestHeaders(array $scraped, string $destination): array
    {
        $referer = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: 'https://www.vermeiren.pl/';

        return [
            'User-Agent' => self::IMAGE_BROWSER_USER_AGENT,
            'Accept' => $destination === 'image'
                ? 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'
                : '*/*',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en-US;q=0.7,en;q=0.6',
            'Referer' => $referer,
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Fetch-Dest' => $destination,
            'Sec-Fetch-Mode' => 'no-cors',
            'Sec-Fetch-Site' => 'same-site',
        ];
    }

    private function cleanImportedHtml(string $html): ?string
    {
        $html = preg_replace('#<(script|style|iframe|form)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<img\b[^>]*>#i', '', $html) ?? $html;
        $html = strip_tags(
            $html,
            '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><table><thead><tbody><tr><th><td><a><section><div><span>',
        );
        $html = preg_replace('/\s(?:on\w+|style|src|srcset|data-[\w-]+)=("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace_callback('/<a\b([^>]*)>/i', function (array $matches): string {
            $attributes = $matches[1] ?? '';
            $href = null;

            if (preg_match('/\shref=("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $hrefMatch) === 1) {
                $href = html_entity_decode($hrefMatch[2] ?? $hrefMatch[3] ?? $hrefMatch[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            if ($href === null || $href === '' || str_starts_with(Str::lower($href), 'javascript:')) {
                return '<a>';
            }

            return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
        }, $html) ?? $html;
        $html = trim(preg_replace('/\s{2,}/u', ' ', $html) ?? $html);

        return $html !== '' ? $html : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function seoDescription(array $scraped): ?string
    {
        return $this->plainTextSnippet($scraped['seo_description'] ?? null, 500)
            ?: $this->plainTextSnippet($scraped['short_description'] ?? null, 500)
                ?: $this->plainTextSnippet($scraped['description'] ?? null, 500);
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

        $url = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null);

        if ($url !== null) {
            return hash('sha256', rawurldecode($url));
        }

        throw new RuntimeException('Vermeiren product is missing external_product_id and source URL.');
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        return $this->limitDatabaseString(
            $this->stringOrNull($scraped['sku'] ?? null)
                ?: $this->stringOrNull($scraped['selected_name'] ?? null)
                    ?: 'VERMEIREN-'.substr($externalId, 0, 16),
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function brandName(array $scraped): ?string
    {
        if (is_array($scraped['brand'] ?? null)) {
            return $this->stringOrNull($scraped['brand']['name'] ?? null);
        }

        return $this->stringOrNull($scraped['brand'] ?? null) ?: 'Vermeiren';
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

        if (Str::of($availability)->contains(['preorder', 'pre_order', 'na_zamowienie'])) {
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

    private function isSafeFilterAttributeValue(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_FILTER_VALUE_LENGTH
            && substr_count($value, "\n") <= 5;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $slug = $baseSlug;
        $suffix = substr(sha1($externalId), 0, 8);
        $counter = 1;

        while ($this->productSlugExists($slug, $currentProductId)) {
            $slug = $baseSlug.'-'.$suffix.($counter > 1 ? '-'.$counter : '');
            $counter++;
        }

        return $this->limitDatabaseString($slug);
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
        $slug = $baseSlug;
        $counter = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $this->limitDatabaseString($slug);
    }

    private function uniqueAttributeSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Attribute::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $this->limitDatabaseString($slug);
    }

    private function uniqueAttributeValueSlug(Attribute $attribute, string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while ($attribute->values()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $this->limitDatabaseString($slug);
    }

    private function uniqueNullableSku(
        ?string $sku,
        int $productId,
        string $externalVariantId,
        ?int $currentVariantId = null,
    ): ?string {
        if ($sku === null) {
            return null;
        }

        $baseSku = $this->normaliseSku($sku);

        if ($baseSku === '') {
            return null;
        }

        $candidate = $baseSku;
        $counter = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->when($currentVariantId !== null, fn ($query) => $query->whereKeyNot($currentVariantId))
            ->where(function ($query) use ($productId, $externalVariantId): void {
                $query->where('product_id', '!=', $productId)
                    ->orWhere('external_variant_id', '!=', $externalVariantId);
            })
            ->exists()) {
            $candidate = $this->limitDatabaseString($baseSku.'-'.$counter);
            $counter++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        $sku = trim(preg_replace('/\s+/u', '-', $sku) ?? $sku);

        return $this->limitDatabaseString($sku);
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));

        return is_numeric($normalized) ? (int) round(((float) $normalized) * 100) : null;
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $limit, '');
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

    private function imageMetadataText(mixed $value, ?string $fallback = null): ?string
    {
        $text = $this->stringOrNull($value) ?? $this->stringOrNull($fallback);

        return $text === null
            ? null
            : mb_substr($text, 0, self::IMAGE_METADATA_MAX_LENGTH);
    }

    private function isUsableRemoteImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return preg_match('#/picture\.nsf/(?:O/)?\$file/?$#i', $path) !== 1;
    }

    private function limitDatabaseString(string $value): string
    {
        return mb_substr(trim($value), 0, self::MAX_DATABASE_STRING_LENGTH);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
}
