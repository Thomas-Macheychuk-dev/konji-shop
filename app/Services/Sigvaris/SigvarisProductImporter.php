<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

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
use App\Support\Storage\PublicFilesystemUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class SigvarisProductImporter
{
    private const SOURCE = 'sigvaris';

    private const MAX_DATABASE_STRING_LENGTH = 190;

    /** @var list<string> */
    private const IMAGE_ALLOWED_HOSTS = [
        'sklep-sigvaris.com',
        'www.sklep-sigvaris.com',
    ];

    private const IMAGE_BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
        private readonly SigvarisGpsrDocumentLocalizer $gpsrDocumentLocalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $mapped
     * @return array{product: Product, action: string, warnings: list<string>, stats: array<string, int>}
     */
    public function import(
        array $mapped,
        bool $importImages = true,
        ?int $imageLimit = 50,
        bool $refreshImages = false,
        int $imageTimeoutSeconds = 30,
        int $imageAttempts = 5,
        int $imageRetryDelayMs = 3000,
        int $imageRequestDelayMs = 250,
        bool $importDocuments = false,
    ): array {
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
        $documents = $this->gpsrDocumentLocalizer->localize(
            downloads: is_array($mapped['downloads'] ?? null) ? array_values($mapped['downloads']) : [],
            externalId: $externalId,
            existingDescription: $existing?->description,
            downloadMissing: $importDocuments,
            timeoutSeconds: max(1, $imageTimeoutSeconds),
            attempts: max(1, $imageAttempts),
            retryDelayMs: max(0, $imageRetryDelayMs),
            requestDelayMs: max(0, $imageRequestDelayMs),
        );
        $this->stats['documents_created'] += $documents['created'];
        $this->stats['documents_reused'] += $documents['reused'];

        /** @var Product $product */
        $product = DB::transaction(function () use ($mapped, $externalId, $documents): Product {
            $product = $this->resolveProduct($mapped, $externalId, $documents['resources']);
            $this->syncCategories($product, $mapped);
            $this->syncProductAttributes($product, $mapped);
            $this->syncVariants($product, $mapped);

            return $product;
        });

        if ($importImages) {
            $this->syncImages(
                product: $product,
                mapped: $mapped,
                imageLimit: $imageLimit,
                refreshImages: $refreshImages,
                imageTimeoutSeconds: max(1, $imageTimeoutSeconds),
                imageAttempts: max(1, $imageAttempts),
                imageRetryDelayMs: max(0, $imageRetryDelayMs),
                imageRequestDelayMs: max(0, $imageRequestDelayMs),
            );
        }

        $product = $product->fresh([
            'categories.parent',
            'attributeValues.attribute',
            'images',
            'variants.attributeValues.attribute',
        ]);

        if (! $product instanceof Product) {
            throw new InvalidArgumentException('Unable to reload imported Sigvaris product.');
        }

        return [
            'product' => $product,
            'action' => $action,
            'warnings' => array_values(array_unique($this->warnings)),
            'stats' => $this->stats,
        ];
    }

    /** @param array<string, mixed> $mapped */
    private function assertMappedProductIsImportable(array $mapped): void
    {
        $errors = array_values(array_filter(
            $mapped['errors'] ?? [],
            static fn (mixed $error): bool => is_string($error) && trim($error) !== '',
        ));

        if ($errors !== []) {
            throw new InvalidArgumentException('Mapped Sigvaris product has hard errors: '.implode(' | ', $errors));
        }

        $source = $this->stringOrNull($mapped['source'] ?? null);

        if ($source !== self::SOURCE) {
            throw new InvalidArgumentException('Mapped product source must be sigvaris.');
        }

        $product = $this->productData($mapped);

        if (($product['external_source'] ?? null) !== self::SOURCE) {
            throw new InvalidArgumentException('Mapped product external_source must be sigvaris.');
        }

        if (($product['status'] ?? null) !== ProductStatus::DRAFT->value) {
            throw new InvalidArgumentException('Sigvaris local imports must remain draft products.');
        }

        if (! is_array($mapped['variants'] ?? null) || $mapped['variants'] === []) {
            throw new InvalidArgumentException('Mapped Sigvaris product has no planned variants.');
        }
    }

    /** @param array<string, mixed> $mapped */
    /** @param list<array{source_url:string,label:string,href:string,path:string}> $localizedDownloads */
    private function resolveProduct(array $mapped, string $externalId, array $localizedDownloads): Product
    {
        $productData = $this->productData($mapped);
        $product = Product::withTrashed()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        $name = $this->requiredString($productData['name'] ?? null, 'product name');
        $baseSlug = $this->stringOrNull($productData['slug'] ?? null)
            ?: Str::slug($name)
                ?: 'sigvaris-product-'.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($productData),
            'description' => $this->productDescriptionHtml($mapped, $product?->description, $localizedDownloads),
            'seo_title' => $this->stringOrNull($productData['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($productData),
            'status' => ProductStatus::DRAFT,
            'published_at' => null,
            'external_source' => self::SOURCE,
            'external_id' => $externalId,
            'external_parent_sku' => $this->limitDatabaseString(
                $this->stringOrNull($productData['external_parent_sku'] ?? null) ?: 'SIGVARIS-'.$externalId,
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

    /** @param array<string, mixed> $mapped */
    private function syncCategories(Product $product, array $mapped): void
    {
        $categoryEntries = array_values(array_filter(
            $mapped['categories'] ?? [],
            static fn (mixed $category): bool => is_array($category),
        ));

        if ($categoryEntries === []) {
            throw new InvalidArgumentException('Mapped Sigvaris product has no categories.');
        }

        $syncPayload = [];
        $primaryLeafId = null;
        $fallbackLeafId = null;

        foreach ($categoryEntries as $entry) {
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
            throw new InvalidArgumentException('Mapped Sigvaris category paths could not be resolved.');
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
            $baseSlug = 'sigvaris-category-'.substr(sha1(implode('|', $pathSegments)), 0, 12);
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

        foreach (($mapped['attributes'] ?? []) as $attributeIndex => $attributeData) {
            if (! is_array($attributeData) || ($attributeData['source'] ?? null) !== 'source_manufacturer') {
                continue;
            }

            $code = $this->attributeCode($attributeData);
            $label = $this->stringOrNull($attributeData['label'] ?? null) ?: Str::headline($code);
            $attribute = $this->resolveAttribute($code, $label);

            foreach (($attributeData['values'] ?? []) as $valueIndex => $valueData) {
                if (! is_array($valueData)) {
                    continue;
                }

                $valueCode = $this->stringOrNull($valueData['value'] ?? null);
                $valueLabel = $this->stringOrNull($valueData['value_label'] ?? null) ?: $valueCode;

                if ($valueCode === null || $valueLabel === null) {
                    continue;
                }

                $attributeValue = $this->resolveAttributeValue(
                    attribute: $attribute,
                    code: $code,
                    sourceValue: $valueCode,
                    displayValue: $valueLabel,
                    sortOrder: ((int) $attributeIndex * 100) + (int) $valueIndex,
                );
                $valueIds[] = $attributeValue->id;
            }
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
            throw new InvalidArgumentException('Mapped Sigvaris product has no variants.');
        }

        $syncedIds = [];
        $seenExternalIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $candidate) {
            $externalVariantId = $this->requiredString(
                $candidate['external_variant_id'] ?? null,
                'variant external ID',
            );

            if (isset($seenExternalIds[$externalVariantId])) {
                throw new InvalidArgumentException('Duplicate mapped Sigvaris variant ID '.$externalVariantId.'.');
            }

            $seenExternalIds[$externalVariantId] = true;
            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();
            $isNew = $variant === null;
            $grossMinor = $this->intOrNull($candidate['price_gross_minor'] ?? null);
            $netMinor = $this->intOrNull($candidate['price_net_minor'] ?? null);
            $vatRate = $this->vatRate($candidate['vat_rate'] ?? null);
            $sourceAvailable = ($candidate['source_active'] ?? true) === true
                && ($candidate['source_visible'] ?? true) === true
                && ($candidate['source_purchasable'] ?? true) === true;
            $status = $sourceAvailable ? ProductVariantStatus::DRAFT : ProductVariantStatus::ARCHIVED;
            $requestedDefault = ($candidate['is_default'] ?? false) === true && $sourceAvailable && ! $defaultAssigned;

            $attributes = [
                'sku' => $this->uniqueVariantSku(
                    $this->requiredString($candidate['sku'] ?? null, 'variant SKU'),
                    $variant?->id,
                ),
                'status' => $status,
                'stock_status' => $this->stockStatus($candidate['stock_status'] ?? null),
                'is_default' => $requestedDefault,
            ];

            $mappedPricing = [
                'price_net_amount' => $netMinor ?? ($grossMinor !== null ? $vatRate->netFromGross($grossMinor) : null),
                'price_gross_amount' => $grossMinor,
                'currency' => $this->currency($candidate['currency'] ?? null),
                'vat_rate' => $vatRate,
            ];

            // The official Sigvaris price-list workflow is the source of truth for
            // existing selling prices. A normal source re-import must not restore
            // scraped shop prices or VAT over an already priced local variant.
            // New variants (or legacy rows with no price at all) still receive the
            // mapped source price until an official price plan is applied.
            if ($variant === null || ($variant->price_net_amount === null && $variant->price_gross_amount === null)) {
                $attributes += $mappedPricing;
            }

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

            if ($isNew && ! $sourceAvailable) {
                $this->warnings[] = 'Imported source-inactive variant as archived: '.$externalVariantId;
            }
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
            $this->warnings[] = 'No source-active Sigvaris variant could be assigned as default.';
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
        $externalId = $this->limitDatabaseString('sigvaris-'.$code);
        $slug = Str::slug($label) ?: 'sigvaris-attribute-'.substr(sha1($label), 0, 12);
        $attribute = Attribute::query()->where('external_attribute_id', $externalId)->first();

        if ($attribute === null) {
            $attribute = Attribute::query()->where('slug', $slug)->first();
        }

        $displayType = str_contains(Str::lower($code.' '.$label), 'kolor')
            ? AttributeDisplayType::COLOR_SWATCH
            : AttributeDisplayType::SELECT;

        if ($attribute !== null) {
            $updates = [
                'name' => $label,
                'display_type' => $displayType,
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
            'display_type' => $displayType,
        ]);
    }

    private function resolveAttributeValue(
        Attribute $attribute,
        string $code,
        string $sourceValue,
        string $displayValue,
        int $sortOrder,
    ): AttributeValue {
        $externalOptionId = $this->limitDatabaseString('sigvaris-'.$code.'-'.$sourceValue);
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

    /** @param array<string, mixed> $mapped */
    private function syncImages(
        Product $product,
        array $mapped,
        ?int $imageLimit,
        bool $refreshImages,
        int $imageTimeoutSeconds,
        int $imageAttempts,
        int $imageRetryDelayMs,
        int $imageRequestDelayMs,
    ): void {
        $allImages = array_values(array_filter(
            $mapped['images'] ?? [],
            static fn (mixed $image): bool => is_array($image) && is_string($image['source_url'] ?? null),
        ));
        $images = $imageLimit === null ? $allImages : array_slice($allImages, 0, max(0, $imageLimit));
        $fullImageSet = $imageLimit === null || $imageLimit >= count($allImages);

        if ($images === []) {
            $this->warnings[] = 'No mapped Sigvaris images were selected; existing product images were preserved.';

            return;
        }

        $syncedImageIds = [];
        $mainAssigned = false;
        $successfulDownloadsOrReuses = 0;

        foreach ($images as $index => $imageData) {
            if ($index > 0 && $imageRequestDelayMs > 0) {
                usleep($imageRequestDelayMs * 1000);
            }

            $url = $this->stringOrNull($imageData['source_url'] ?? null);

            if ($url === null || ! $this->isSigvarisUrl($url)) {
                $this->warnings[] = 'Image skipped because its host is not Sigvaris: '.($url ?? '[missing URL]');

                continue;
            }

            $existing = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('source_url', $url)
                ->first();
            $canReuse = ! $refreshImages
                && $existing !== null
                && $existing->path !== ''
                && Storage::disk($existing->disk)->exists($existing->path);

            if ($canReuse) {
                $existing->update([
                    'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                    'title' => $this->stringOrNull($imageData['title'] ?? null),
                    'sort_order' => $index,
                    'is_main' => ! $mainAssigned,
                ]);

                if (! $mainAssigned) {
                    ProductImage::query()
                        ->where('product_id', $product->id)
                        ->whereKeyNot($existing->id)
                        ->where('is_main', true)
                        ->update(['is_main' => false]);
                }

                $syncedImageIds[] = $existing->id;
                $mainAssigned = true;
                $successfulDownloadsOrReuses++;
                $this->stats['images_reused']++;

                continue;
            }

            $oldDisk = $existing?->disk;
            $oldPath = $existing?->path;

            try {
                $imported = $this->remoteImageImporter->import(
                    url: $url,
                    directory: 'products/sigvaris/'.$product->external_id.'/gallery',
                    disk: 'public',
                    allowedHosts: self::IMAGE_ALLOWED_HOSTS,
                    requestOptions: [
                        'timeout_seconds' => $imageTimeoutSeconds,
                        'retry_attempts' => $imageAttempts,
                        'retry_delay_ms' => $imageRetryDelayMs,
                        'headers' => $this->imageRequestHeaders($mapped),
                    ],
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped: '.$url.' — '.$exception->getMessage();
                $this->stats['images_failed']++;

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
                    'is_main' => ! $mainAssigned,
                ],
            );

            if (
                $existing !== null
                && is_string($oldDisk)
                && is_string($oldPath)
                && $oldPath !== ''
                && ($oldDisk !== $image->disk || $oldPath !== $image->path)
            ) {
                $this->deleteStoredImageIfUnreferenced($oldDisk, $oldPath, $existing->id);
            }

            if (! $mainAssigned) {
                ProductImage::query()
                    ->where('product_id', $product->id)
                    ->whereKeyNot($image->id)
                    ->where('is_main', true)
                    ->update(['is_main' => false]);
            }

            $syncedImageIds[] = $image->id;
            $mainAssigned = true;
            $successfulDownloadsOrReuses++;

            if ($existing === null) {
                $this->stats['images_created']++;
            } else {
                $this->stats['images_updated']++;
            }
        }

        if ($successfulDownloadsOrReuses === 0) {
            $this->warnings[] = 'All selected Sigvaris image downloads failed; existing images were preserved.';

            return;
        }

        if (! $fullImageSet || count($syncedImageIds) !== count($images)) {
            return;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedImageIds)
            ->get()
            ->filter(fn (ProductImage $image): bool => $this->isSigvarisUrl($image->source_url))
            ->each(function (ProductImage $image): void {
                if ($image->path !== '') {
                    $this->deleteStoredImageIfUnreferenced($image->disk, $image->path, $image->id);
                }

                $image->delete();
                $this->stats['images_deleted']++;
            });
    }

    private function deleteStoredImageIfUnreferenced(string $disk, string $path, int $exceptImageId): void
    {
        $isReferenced = ProductImage::query()
            ->where('disk', $disk)
            ->where('path', $path)
            ->whereKeyNot($exceptImageId)
            ->exists();

        if (! $isReferenced && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /** @param array<string, mixed> $mapped @return array<string, string> */
    private function imageRequestHeaders(array $mapped): array
    {
        $referer = $this->stringOrNull($mapped['canonical_url'] ?? null)
            ?: $this->stringOrNull($mapped['source_url'] ?? null)
                ?: 'https://www.sklep-sigvaris.com/';

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

    /**
     * @param  array<string, mixed>  $mapped
     * @param  list<array{source_url:string,label:string,href:string,path:string}>  $localizedDownloads
     */
    private function productDescriptionHtml(array $mapped, ?string $existingDescription = null, array $localizedDownloads = []): ?string
    {
        $productData = $this->productData($mapped);
        $sections = [];
        $description = $this->cleanImportedHtml($this->stringOrNull($productData['description_html'] ?? null));

        if ($description !== null) {
            $sections[] = '<section class="sigvaris-description">'.$description.'</section>';
        }

        $resources = [];

        foreach ($localizedDownloads as $download) {
            $href = $this->stringOrNull($download['href'] ?? null);
            $label = $this->stringOrNull($download['label'] ?? null) ?: 'Instrukcja / dokument PDF';

            $storagePath = $href !== null ? PublicFilesystemUrl::path($href) : null;

            if ($href === null || $storagePath === null || ! str_starts_with($storagePath, 'products/sigvaris/')) {
                continue;
            }

            $resources[$href] = '<a data-sigvaris-gpsr="1" href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>';
        }

        foreach (($mapped['videos'] ?? []) as $video) {
            if (! is_array($video)) {
                continue;
            }

            $url = $this->safeHttpUrl($video['source_url'] ?? null);

            if ($url === null) {
                continue;
            }

            $label = $this->stringOrNull($video['label'] ?? null) ?: 'Film produktu';
            $resources[$url] = '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>';
        }

        if ($resources !== []) {
            $items = array_map(static fn (string $link): string => '<li>'.$link.'</li>', array_values($resources));
            $sections[] = '<section class="sigvaris-resources"><h2>Materiały produktu</h2><ul>'.implode('', $items).'</ul></section>';
        }

        $html = $sections !== [] ? implode("\n", $sections) : null;
        $chartAnchor = $this->mappedSizeChartAnchor($mapped) ?: $this->preservedSizeChartAnchor($existingDescription);

        return $chartAnchor !== null ? $this->injectSizeChartAnchor($html, $chartAnchor) : $html;
    }

    /** @param array<string, mixed> $mapped */
    private function mappedSizeChartAnchor(array $mapped): ?string
    {
        $sizeChart = is_array($mapped['size_chart'] ?? null) ? $mapped['size_chart'] : null;
        $url = $this->safeHttpUrl($sizeChart['source_url'] ?? null);

        if ($url === null || ! $this->isSigvarisUrl($url)) {
            return null;
        }

        return '<a data-sigvaris-size-chart="1" href="'.e($url).'" target="_blank" rel="noopener noreferrer">TABELA ROZMIARÓW</a>';
    }

    private function preservedSizeChartAnchor(?string $existingDescription): ?string
    {
        if ($existingDescription === null || preg_match(
            '#<a\b(?=[^>]*data-sigvaris-size-chart=["\']1["\'])[^>]*href=["\']([^"\']+)["\'][^>]*>.*?</a>#isu',
            $existingDescription,
            $matches,
        ) !== 1) {
            return null;
        }

        $href = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $storagePath = PublicFilesystemUrl::path($href);

        if ($storagePath === null || ! str_starts_with($storagePath, 'products/sigvaris/')) {
            return null;
        }

        return '<a data-sigvaris-size-chart="1" href="'.e($href).'" target="_blank" rel="noopener noreferrer">TABELA ROZMIARÓW</a>';
    }

    private function injectSizeChartAnchor(?string $html, string $anchor): string
    {
        $html ??= '';

        if (preg_match('/tabela\s+rozmiarów/ui', strip_tags($html)) === 1) {
            $replaced = preg_replace('/TABELA\s+ROZMIARÓW/ui', $anchor, $html, 1);

            if (is_string($replaced)) {
                return $replaced;
            }
        }

        $section = '<section class="sigvaris-size-chart"><p>'.$anchor.'</p></section>';

        return trim($html) !== '' ? $html."\n".$section : $section;
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
            'images_created' => 0,
            'images_updated' => 0,
            'images_reused' => 0,
            'images_deleted' => 0,
            'images_failed' => 0,
            'documents_created' => 0,
            'documents_reused' => 0,
        ];
    }

    private function vatRate(mixed $value): VatRate
    {
        $int = $this->intOrNull($value);
        $vatRate = $int !== null ? VatRate::tryFrom($int) : null;

        if ($vatRate === null) {
            throw new InvalidArgumentException('Unsupported mapped Sigvaris VAT rate.');
        }

        return $vatRate;
    }

    private function currency(mixed $value): Currency
    {
        $currency = Currency::tryFrom(mb_strtoupper($this->stringOrNull($value) ?? 'PLN'));

        if ($currency === null) {
            throw new InvalidArgumentException('Unsupported mapped Sigvaris currency.');
        }

        return $currency;
    }

    private function stockStatus(mixed $value): StockStatus
    {
        return StockStatus::tryFrom($this->stringOrNull($value) ?? '') ?? StockStatus::IN_STOCK;
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
        $baseSlug = Str::slug($baseSlug) ?: 'sigvaris-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists()) {
            $candidate = $suffix === 2
                ? $baseSlug.'-sigvaris-'.$externalId
                : $baseSlug.'-sigvaris-'.$externalId.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueCategorySlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'sigvaris-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeSlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'sigvaris-attribute';
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

    private function isSigvarisUrl(?string $url): bool
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(mb_strtolower((string) parse_url($url, PHP_URL_HOST)), self::IMAGE_ALLOWED_HOSTS, true);
    }

    private function safeHttpUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
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

    private function requiredString(mixed $value, string $label): string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            throw new InvalidArgumentException('Mapped Sigvaris '.$label.' is missing.');
        }

        return $this->limitDatabaseString($string);
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

        return $value !== '' ? $value : null;
    }
}
