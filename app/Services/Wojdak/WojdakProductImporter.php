<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

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

final class WojdakProductImporter
{
    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function import(array $normalized): Product
    {
        return DB::transaction(function () use ($normalized): Product {
            $product = Product::updateOrCreate(
                [
                    'external_source' => 'wojdak',
                    'external_id' => (string) $normalized['external_id'],
                ],
                [
                    'name' => $normalized['name'],
                    'slug' => $normalized['slug'] ?: Str::slug((string) $normalized['name']),
                    'short_description' => $normalized['short_description_html'] ?? null,
                    'description' => $normalized['description_html'] ?? null,
                    'status' => ProductStatus::DRAFT,
                    'external_parent_sku' => $normalized['external_parent_sku'] ?? null,
                ]
            );

            $this->syncCategory($product, $normalized);
            $this->syncImages($product, $normalized);
            $attributeValueMap = $this->syncAttributes($normalized);
            $this->syncVariants($product, $normalized, $attributeValueMap);

            return $product->fresh([
                'categories',
                'images',
                'variants.attributeValues.attribute',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncCategory(Product $product, array $normalized): void
    {
        $categorySlug = $this->stringOrNull($normalized['category_slug'] ?? null);

        if ($categorySlug === null) {
            return;
        }

        $category = Category::firstOrCreate(
            ['slug' => $categorySlug],
            [
                'name' => Str::headline(str_replace('-', ' ', $categorySlug)),
                'status' => CategoryStatus::ACTIVE,
            ]
        );

        $product->categories()->syncWithoutDetaching([
            $category->id => ['is_primary' => true],
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncImages(Product $product, array $normalized): void
    {
        $imageRows = [];

        foreach (array_values(array_unique($normalized['images'] ?? [])) as $index => $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $imported = $this->remoteImageImporter->import(
                $url,
                'products/wojdak/'.$product->external_id.'/gallery',
                'public',
                ['wojdak.pl', 'sklep.wojdak.pl']
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
                    'alt_text' => $product->name,
                    'title' => $product->name,
                    'is_main' => $row['is_main'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, int>
     */
    private function syncAttributes(array $normalized): array
    {
        $map = [];
        $seenOptions = [];

        foreach (($normalized['variants'] ?? []) as $variantData) {
            foreach (($variantData['attributes'] ?? []) as $attributeData) {
                $externalAttributeId = (string) ($attributeData['external_attribute_id'] ?? 'wojdak-'.$attributeData['code']);
                $externalOptionId = (string) ($attributeData['external_option_id'] ?? $externalAttributeId.'-'.Str::slug((string) $attributeData['value']));
                $key = $externalAttributeId.'|'.$externalOptionId;

                if (isset($seenOptions[$key])) {
                    continue;
                }

                $seenOptions[$key] = true;

                $attribute = $this->resolveAttribute(
                    externalAttributeId: $externalAttributeId,
                    name: (string) $attributeData['name'],
                );

                $value = $this->resolveAttributeValue(
                    attribute: $attribute,
                    externalOptionId: $externalOptionId,
                    value: (string) $attributeData['value'],
                    sortOrder: (int) ($attributeData['sort_order'] ?? 0),
                );

                $map[$key] = $value->id;
            }
        }

        return $map;
    }

    private function resolveAttribute(string $externalAttributeId, string $name): Attribute
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
                'display_type' => AttributeDisplayType::SELECT,
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
            ->where('external_option_id', $externalOptionId)
            ->first();

        if (! $attributeValue) {
            $attributeValue = AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where('slug', $slug)
                ->first();
        }

        if ($attributeValue) {
            $updates = [
                'value' => $value,
                'sort_order' => $sortOrder,
            ];

            if (! filled($attributeValue->external_option_id)) {
                $updates['external_option_id'] = $externalOptionId;
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
     * @param  array<string, mixed>  $normalized
     * @param  array<string, int>  $attributeValueMap
     */
    private function syncVariants(Product $product, array $normalized, array $attributeValueMap): void
    {
        $incomingExternalVariantIds = [];

        foreach (($normalized['variants'] ?? []) as $index => $variantData) {
            $externalVariantId = (string) $variantData['external_variant_id'];
            $incomingExternalVariantIds[] = $externalVariantId;

            $vatRate = $this->vatRate($variantData['vat_rate'] ?? null);
            $priceNetAmount = $this->priceNetAmount($variantData, $vatRate);

            $variant = ProductVariant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'external_variant_id' => $externalVariantId,
                ],
                [
                    'sku' => $this->stringOrNull($variantData['sku'] ?? null),
                    'status' => $this->variantStatus($variantData['status'] ?? null),
                    'price_net_amount' => $priceNetAmount,
                    'currency' => $this->currency($variantData['currency'] ?? null),
                    'vat_rate' => $vatRate,
                    'stock_status' => $this->stockStatus($variantData['stock_status'] ?? null),
                    'is_default' => $index === 0,
                    'package_weight_grams' => $this->integerOrNull($variantData['package_weight_grams'] ?? null),
                ]
            );

            $attributeValueIds = [];

            foreach (($variantData['attributes'] ?? []) as $attributeData) {
                $externalAttributeId = (string) ($attributeData['external_attribute_id'] ?? '');
                $externalOptionId = (string) ($attributeData['external_option_id'] ?? '');
                $key = $externalAttributeId.'|'.$externalOptionId;
                $id = $attributeValueMap[$key] ?? null;

                if ($id !== null) {
                    $attributeValueIds[] = $id;
                }
            }

            $variant->attributeValues()->sync($attributeValueIds);
        }

        $query = ProductVariant::query()->where('product_id', $product->id);

        if ($incomingExternalVariantIds === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn('external_variant_id', $incomingExternalVariantIds)->delete();
    }


    private function variantStatus(mixed $value): ProductVariantStatus
    {
        if ($value instanceof ProductVariantStatus) {
            return $value;
        }

        return is_string($value) ? ProductVariantStatus::tryFrom($value) ?? ProductVariantStatus::DRAFT : ProductVariantStatus::DRAFT;
    }

    private function stockStatus(mixed $value): StockStatus
    {
        if ($value instanceof StockStatus) {
            return $value;
        }

        return is_string($value) ? StockStatus::tryFrom($value) ?? StockStatus::OUT_OF_STOCK : StockStatus::OUT_OF_STOCK;
    }

    private function currency(mixed $value): Currency
    {
        if ($value instanceof Currency) {
            return $value;
        }

        return is_string($value) ? Currency::tryFrom($value) ?? Currency::PLN : Currency::PLN;
    }

    private function vatRate(mixed $value): VatRate
    {
        if ($value instanceof VatRate) {
            return $value;
        }

        if (is_numeric($value)) {
            return VatRate::tryFrom((int) $value) ?? VatRate::VAT_23;
        }

        return VatRate::VAT_23;
    }

    /**
     * @param  array<string, mixed>  $variantData
     */
    private function priceNetAmount(array $variantData, VatRate $vatRate): ?int
    {
        $net = $this->integerOrNull($variantData['price_net_amount'] ?? null);

        if ($net !== null) {
            return $net;
        }

        $gross = $this->integerOrNull($variantData['price_gross_amount'] ?? null);

        return $gross === null ? null : $vatRate->netFromGross($gross);
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
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
