<?php

declare(strict_types=1);

namespace App\Services\Footwave;

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
use RuntimeException;

final class FootwaveProductImporter
{
    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * @param  array<string, mixed>  $scraped
     */
    public function import(
        array $scraped,
        VatRate $vatRate,
        bool $importImages = true,
        int $imageLimit = 10,
    ): Product {
        $this->assertImportable($scraped);

        return DB::transaction(function () use (
            $scraped,
            $vatRate,
            $importImages,
            $imageLimit,
        ): Product {
            $externalId = (string) $scraped['external_product_id'];

            $product = Product::withTrashed()
                ->where('external_source', 'footwave')
                ->where('external_id', $externalId)
                ->first();

            if ($product !== null && $product->trashed()) {
                $product->restore();
            }

            $isNew = $product === null;

            if ($product === null) {
                $product = new Product;
                $product->external_source = 'footwave';
                $product->external_id = $externalId;
                $product->status = ProductStatus::DRAFT;
            }

            $product->name = $this->requiredString(
                $scraped['name'] ?? null,
                'FootWave product name',
            );

            $product->slug = $this->uniqueProductSlug(
                $this->stringOrNull($scraped['slug'] ?? null)
                    ?? Str::slug($product->name),
                $product,
                $externalId,
            );

            $product->short_description =
                $this->stringOrNull(
                    $scraped['short_description_html'] ?? null
                );

            $product->description =
                $this->stringOrNull(
                    $scraped['description_html'] ?? null
                );

            $product->external_parent_sku =
                $this->stringOrNull(
                    $scraped['external_parent_sku'] ?? null
                );

            /*
             * Existing editorial/product lifecycle status is authoritative.
             * Only brand-new imports are forced to draft.
             */
            if ($isNew) {
                $product->status = ProductStatus::DRAFT;
            }

            $product->save();

            $this->syncCategories($product, $scraped);

            if ($importImages) {
                $this->syncImages(
                    $product,
                    $scraped,
                    max(0, $imageLimit),
                );
            }

            $attributeValueMap =
                $this->syncAttributes($scraped);

            $this->syncVariants(
                $product,
                $scraped,
                $attributeValueMap,
                $vatRate,
            );

            return $product->fresh([
                'categories',
                'images',
                'variants.attributeValues.attribute',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function assertImportable(array $scraped): void
    {
        if (($scraped['source'] ?? null) !== 'footwave') {
            throw new RuntimeException(
                'Refusing to import non-FootWave source payload.'
            );
        }

        if (($scraped['eligible'] ?? false) !== true) {
            throw new RuntimeException(
                'Refusing to import ineligible FootWave product ['.
                ((string) ($scraped['external_product_id'] ?? 'unknown')).
                '].'
            );
        }

        if (($scraped['variants_unresolved'] ?? false) === true) {
            throw new RuntimeException(
                'Refusing to import FootWave product with unresolved variants.'
            );
        }

        $externalId = $this->stringOrNull(
            $scraped['external_product_id'] ?? null
        );

        if (
            $externalId === null
            || preg_match('/^\d+$/', $externalId) !== 1
        ) {
            throw new RuntimeException(
                'FootWave product has no valid numeric external product ID.'
            );
        }

        $variants = $scraped['variants'] ?? null;

        if (! is_array($variants) || $variants === []) {
            throw new RuntimeException(
                'FootWave product has no variants to import.'
            );
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                throw new RuntimeException(
                    'FootWave variant payload is invalid.'
                );
            }

            $variantId = $this->stringOrNull(
                $variant['external_variant_id'] ?? null
            );

            if (
                $variantId === null
                || preg_match('/^\d+$/', $variantId) !== 1
            ) {
                throw new RuntimeException(
                    'FootWave variant has no valid numeric external ID.'
                );
            }

            $currency = strtoupper(
                $this->stringOrNull(
                    $variant['currency'] ?? null
                ) ?? 'PLN'
            );

            if ($currency !== 'PLN') {
                throw new RuntimeException(
                    'Unsupported FootWave currency ['.$currency.'].'
                );
            }

            $gross = $variant['price_gross_amount'] ?? null;

            if (
                ($variant['is_purchasable'] ?? false) === true
                && (! is_int($gross) || $gross <= 0)
            ) {
                throw new RuntimeException(
                    'Purchasable FootWave variant '.$variantId.
                    ' has no positive authoritative gross price.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncCategories(
        Product $product,
        array $scraped,
    ): void {
        $categoryIds = [];

        foreach (($scraped['categories'] ?? []) as $categoryData) {
            if (! is_array($categoryData)) {
                continue;
            }

            $name = $this->stringOrNull(
                $categoryData['name'] ?? null
            );

            $slug = $this->stringOrNull(
                $categoryData['slug'] ?? null
            );

            if ($name === null || $slug === null) {
                continue;
            }

            $category = Category::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'status' => CategoryStatus::ACTIVE,
                ]
            );

            $categoryIds[$category->id] = [
                'is_primary' => $categoryIds === [],
            ];
        }

        if ($categoryIds !== []) {
            /*
             * Do not remove manually assigned categories.
             */
            $product->categories()->syncWithoutDetaching(
                $categoryIds
            );
        }
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(
        Product $product,
        array $scraped,
        int $limit,
    ): void {
        if ($limit === 0) {
            return;
        }

        $urls = array_slice(
            array_values(
                array_unique(
                    array_filter(
                        $scraped['images'] ?? [],
                        'is_string',
                    )
                )
            ),
            0,
            $limit,
        );

        if ($urls === []) {
            return;
        }

        $imageRows = [];

        foreach ($urls as $index => $url) {
            if (trim($url) === '') {
                continue;
            }

            $imported = $this->remoteImageImporter->import(
                $url,
                'products/footwave/'.$product->external_id.'/gallery',
                'public',
                ['footwave.pl', 'www.footwave.pl'],
            );

            $imageRows[] = [
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'sort_order' => $index,
                'is_main' => $index === 0,
            ];
        }

        if ($imageRows === []) {
            return;
        }

        $incomingSourceUrls = array_column(
            $imageRows,
            'source_url'
        );

        /*
         * Remove only stale images previously sourced from FootWave.
         * Never delete manually uploaded/non-FootWave product media.
         */
        ProductImage::query()
            ->where('product_id', $product->id)
            ->where(function ($query): void {
                $query
                    ->where(
                        'source_url',
                        'like',
                        'https://footwave.pl/%'
                    )
                    ->orWhere(
                        'source_url',
                        'like',
                        'https://www.footwave.pl/%'
                    );
            })
            ->whereNotIn(
                'source_url',
                $incomingSourceUrls
            )
            ->delete();

        foreach ($imageRows as $row) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'source_url' => $row['source_url'],
                ],
                [
                    'disk' => $row['disk'],
                    'path' => $row['path'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $product->name,
                    'title' => $product->name,
                    'is_main' => $row['is_main'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array<string, int>
     */
    private function syncAttributes(array $scraped): array
    {
        $map = [];

        foreach (($scraped['variants'] ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            foreach (($variant['attributes'] ?? []) as $attributeData) {
                if (! is_array($attributeData)) {
                    continue;
                }

                $externalAttributeId = $this->requiredString(
                    $attributeData['external_attribute_id'] ?? null,
                    'FootWave external attribute ID',
                );

                $externalOptionId = $this->requiredString(
                    $attributeData['external_option_id'] ?? null,
                    'FootWave external option ID',
                );

                $key =
                    $externalAttributeId.'|'.$externalOptionId;

                if (isset($map[$key])) {
                    continue;
                }

                $name = $this->requiredString(
                    $attributeData['name'] ?? null,
                    'FootWave attribute name',
                );

                $valueText = $this->requiredString(
                    $attributeData['value'] ?? null,
                    'FootWave attribute value',
                );

                $attribute = $this->resolveAttribute(
                    $externalAttributeId,
                    $name,
                );

                $value = $this->resolveAttributeValue(
                    $attribute,
                    $externalOptionId,
                    $valueText,
                    (int) (
                        $attributeData['sort_order']
                        ?? 0
                    ),
                );

                $map[$key] = $value->id;
            }
        }

        return $map;
    }

    private function resolveAttribute(
        string $externalAttributeId,
        string $name,
    ): Attribute {
        $slug = Str::slug($name);

        $attribute = Attribute::query()
            ->where(
                'external_attribute_id',
                $externalAttributeId
            )
            ->first();

        /*
         * Reuse canonical shared attributes such as "Rozmiar"
         * rather than creating source-specific duplicate UI dimensions.
         */
        if ($attribute === null) {
            $attribute = Attribute::query()
                ->where('slug', $slug)
                ->first();
        }

        if ($attribute !== null) {
            $updates = [
                'name' => $name,
                'display_type' => AttributeDisplayType::SELECT,
            ];

            if (! filled($attribute->external_attribute_id)) {
                $updates['external_attribute_id'] =
                    $externalAttributeId;
            }

            $attribute->update($updates);

            return $attribute;
        }

        return Attribute::query()->create([
            'external_attribute_id' => $externalAttributeId,
            'name' => $name,
            'slug' => $slug,
            'display_type' => AttributeDisplayType::SELECT,
        ]);
    }

    private function resolveAttributeValue(
        Attribute $attribute,
        string $externalOptionId,
        string $value,
        int $sortOrder,
    ): AttributeValue {
        $slug = Str::slug($value);

        $attributeValue = AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->where(
                'external_option_id',
                $externalOptionId
            )
            ->first();

        if ($attributeValue === null) {
            $attributeValue = AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where('slug', $slug)
                ->first();
        }

        if ($attributeValue !== null) {
            $updates = [
                'value' => $value,
                'sort_order' => $sortOrder,
            ];

            if (
                ! filled(
                    $attributeValue->external_option_id
                )
            ) {
                $updates['external_option_id'] =
                    $externalOptionId;
            }

            $attributeValue->update($updates);

            return $attributeValue;
        }

        return AttributeValue::query()->create([
            'attribute_id' => $attribute->id,
            'external_option_id' => $externalOptionId,
            'value' => $value,
            'slug' => $slug,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @param  array<string, int>  $attributeValueMap
     */
    private function syncVariants(
        Product $product,
        array $scraped,
        array $attributeValueMap,
        VatRate $vatRate,
    ): void {
        $incomingIds = [];

        $variants = array_values(
            array_filter(
                $scraped['variants'] ?? [],
                'is_array',
            )
        );

        $defaultIndex = $this->defaultVariantIndex(
            $variants
        );

        foreach ($variants as $index => $variantData) {
            $sourceVariantId = $this->requiredString(
                $variantData['external_variant_id'] ?? null,
                'FootWave variant ID',
            );

            $externalVariantId =
                'footwave-'.$product->external_id.'-'.
                $sourceVariantId;

            $incomingIds[] = $externalVariantId;

            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where(
                    'external_variant_id',
                    $externalVariantId
                )
                ->first();

            if ($variant !== null && $variant->trashed()) {
                $variant->restore();
            }

            $isNew = $variant === null;

            if ($variant === null) {
                $variant = new ProductVariant;
                $variant->product_id = $product->id;
                $variant->external_variant_id =
                    $externalVariantId;
                $variant->status =
                    ProductVariantStatus::DRAFT;
            }

            $grossAmount =
                is_int(
                    $variantData['price_gross_amount']
                    ?? null
                )
                    ? $variantData['price_gross_amount']
                    : null;

            $variant->sku = $this->uniqueSku(
                $this->stringOrNull(
                    $variantData['sku'] ?? null
                )
                    ?? 'FOOTWAVE-'.$sourceVariantId,
                $product->id,
                $externalVariantId,
            );

            if ($isNew) {
                $variant->status =
                    ProductVariantStatus::DRAFT;
            }

            $variant->price_gross_amount =
                $grossAmount;

            $variant->price_net_amount =
                $grossAmount !== null
                    ? $vatRate->netFromGross(
                        $grossAmount
                    )
                    : null;

            $variant->currency = Currency::PLN;
            $variant->vat_rate = $vatRate;

            // Defensive check even for manually supplied scraped payloads.
            $variant->stock_status =
                ($variantData['stock_status'] ?? null) === 'in_stock'
                && ($variantData['is_purchasable'] ?? false) === true
                && $grossAmount !== null
                && $grossAmount > 0
                    ? StockStatus::IN_STOCK
                    : StockStatus::OUT_OF_STOCK;

            $variant->is_default =
                $index === $defaultIndex;

            $variant->save();

            $valueIds = [];

            foreach (
                ($variantData['attributes'] ?? []) as $attributeData
            ) {
                if (! is_array($attributeData)) {
                    continue;
                }

                $key =
                    ((string) (
                        $attributeData[
                            'external_attribute_id'
                        ] ?? ''
                    ))
                    .'|'.
                    ((string) (
                        $attributeData[
                            'external_option_id'
                        ] ?? ''
                    ));

                $id = $attributeValueMap[$key] ?? null;

                if ($id !== null) {
                    $valueIds[] = $id;
                }
            }

            $variant->attributeValues()->sync(
                array_values(
                    array_unique($valueIds)
                )
            );
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->when(
                $incomingIds !== [],
                fn ($query) => $query->whereNotIn(
                    'external_variant_id',
                    $incomingIds
                ),
                fn ($query) => $query,
            )
            ->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function defaultVariantIndex(
        array $variants,
    ): int {
        foreach ($variants as $index => $variant) {
            if (
                ($variant['is_purchasable'] ?? false)
                    === true
                && ($variant['stock_status'] ?? null)
                    === 'in_stock'
                && is_int(
                    $variant['price_gross_amount']
                    ?? null
                )
                && $variant['price_gross_amount'] > 0
            ) {
                return $index;
            }
        }

        return 0;
    }

    private function uniqueProductSlug(
        string $sourceSlug,
        Product $product,
        string $externalId,
    ): string {
        $base = Str::slug($sourceSlug);

        if ($base === '') {
            $base = 'footwave-'.$externalId;
        }

        $query = Product::query()
            ->where('slug', $base);

        if ($product->exists) {
            $query->whereKeyNot($product->id);
        }

        if (! $query->exists()) {
            return $base;
        }

        return $base.'-footwave-'.$externalId;
    }

    private function uniqueSku(
        string $sku,
        int $productId,
        string $externalVariantId,
    ): string {
        $sku = trim($sku);

        $exists = ProductVariant::withTrashed()
            ->where('sku', $sku)
            ->where(function ($query) use (
                $productId,
                $externalVariantId,
            ): void {
                $query
                    ->where(
                        'product_id',
                        '!=',
                        $productId
                    )
                    ->orWhere(
                        'external_variant_id',
                        '!=',
                        $externalVariantId
                    );
            })
            ->exists();

        if (! $exists) {
            return $sku;
        }

        return 'FOOTWAVE-'.$sku;
    }

    private function requiredString(
        mixed $value,
        string $label,
    ): string {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            throw new RuntimeException(
                $label.' is required.'
            );
        }

        return $value;
    }

    private function stringOrNull(
        mixed $value,
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
