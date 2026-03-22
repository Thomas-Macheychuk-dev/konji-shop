<?php

namespace App\Services\Eldan;

use App\Enums\AttributeDisplayType;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductAttributeValueImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EldanProductImporter
{
    public function import(array $normalized): Product
    {
        return DB::transaction(function () use ($normalized) {
            $product = Product::updateOrCreate(
                [
                    'external_source' => 'eldan',
                    'external_id' => (string) $normalized['external_id'],
                ],
                [
                    'name' => $normalized['name'],
                    'slug' => $normalized['slug'] ?: Str::slug($normalized['name']),
                    'short_description' => $normalized['short_description_html'] ?? null,
                    'description' => $normalized['description_html'] ?? null,
                    'status' => ProductStatus::DRAFT,
                    'external_parent_sku' => $normalized['external_parent_sku'] ?? null,
                ]
            );

            $this->syncImages($product, $normalized);

            $attributeValueMap = $this->syncAttributes($normalized);

            $this->syncVariants($product, $normalized, $attributeValueMap);

            return $product->fresh([
                'images',
                'variants.attributeValues',
                'attributeValueImages',
            ]);
        });
    }

    private function syncImages(Product $product, array $normalized): void
    {
        $images = array_values(array_unique($normalized['images'] ?? []));

        ProductImage::query()
            ->where('product_id', $product->id)
            ->when(
                $images !== [],
                fn ($query) => $query->whereNotIn('path', $images)
            )
            ->when(
                $images === [],
                fn ($query) => $query
            )
            ->delete();

        foreach ($images as $index => $url) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'path' => $url,
                ],
                [
                    'alt_text' => $product->name,
                    'title' => $product->name,
                    'is_main' => $index === 0,
                    'sort_order' => $index,
                ]
            );
        }
    }

    private function syncAttributes(array $normalized): array
    {
        $map = [];

        foreach (($normalized['attributes'] ?? []) as $attributeData) {
            $displayType = $this->mapDisplayType($attributeData['swatch_type'] ?? null);

            $attribute = Attribute::firstOrCreate(
                [
                    'slug' => Str::slug($attributeData['name']),
                ],
                [
                    'name' => $attributeData['name'],
                    'display_type' => $displayType,
                ]
            );

            foreach (($attributeData['options'] ?? []) as $sortOrder => $optionData) {
                $value = AttributeValue::firstOrCreate(
                    [
                        'attribute_id' => $attribute->id,
                        'slug' => Str::slug($optionData['label']),
                    ],
                    [
                        'value' => $optionData['label'],
                        'sort_order' => $sortOrder,
                    ]
                );

                $map[$attributeData['name']][$optionData['label']] = $value->id;
            }
        }

        return $map;
    }

    private function syncVariants(Product $product, array $normalized, array $attributeValueMap): void
    {
        $incomingExternalVariantIds = [];
        $syncedColourValueIds = [];

        foreach (($normalized['variants'] ?? []) as $index => $variantData) {
            $externalVariantId = (string) $variantData['external_variant_id'];
            $incomingExternalVariantIds[] = $externalVariantId;

            $grossMinor = $variantData['price']['amount_minor'] ?? 0;
            $vatRate = VatRate::VAT_23;
            $netMinor = $vatRate->netFromGross($grossMinor);

            $variant = ProductVariant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'external_variant_id' => $externalVariantId,
                ],
                [
                    'sku' => $variantData['sku']
                        ?: $normalized['external_parent_sku'] . '-' . $variantData['external_variant_id'],
                    'status' => ProductVariantStatus::ACTIVE,
                    'price_net_amount' => $netMinor,
                    'currency' => Currency::PLN,
                    'vat_rate' => $vatRate,
                    'stock_status' => StockStatus::IN_STOCK,
                    'is_default' => $index === 0,
                ]
            );

            $attributeValueIds = [];
            $colourValueId = null;

            foreach (($variantData['attributes'] ?? []) as $resolvedAttribute) {
                $attributeName = $resolvedAttribute['name'] ?? null;
                $attributeValue = $resolvedAttribute['value'] ?? null;

                if ($attributeName === null || $attributeValue === null) {
                    continue;
                }

                $id = $attributeValueMap[$attributeName][$attributeValue] ?? null;

                if ($id) {
                    $attributeValueIds[] = $id;

                    if ($attributeName === 'Kolor') {
                        $colourValueId = $id;
                    }
                }
            }

            $variant->attributeValues()->sync($attributeValueIds);

            if ($colourValueId !== null && ! isset($syncedColourValueIds[$colourValueId])) {
                $this->syncColourImages(
                    $product,
                    $colourValueId,
                    $variantData['images'] ?? [],
                    $product->name
                );

                $syncedColourValueIds[$colourValueId] = true;
            }
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('external_variant_id', $incomingExternalVariantIds)
            ->delete();
    }

    private function mapDisplayType(?string $swatchType): AttributeDisplayType
    {
        return match ($swatchType) {
            'color', 'image' => AttributeDisplayType::COLOR_SWATCH,
            default => AttributeDisplayType::TEXT,
        };
    }

    private function syncColourImages(Product $product, int $attributeValueId, array $images, string $productName): void
    {
        $images = array_values(array_unique(array_filter(
            $images,
            fn ($url) => is_string($url) && $url !== ''
        )));

        ProductAttributeValueImage::query()
            ->where('product_id', $product->id)
            ->where('attribute_value_id', $attributeValueId)
            ->when(
                $images !== [],
                fn ($query) => $query->whereNotIn('path', $images)
            )
            ->delete();

        foreach ($images as $index => $url) {
            ProductAttributeValueImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'attribute_value_id' => $attributeValueId,
                    'path' => $url,
                ],
                [
                    'alt_text' => $productName,
                    'title' => $productName,
                    'is_main' => $index === 0,
                    'sort_order' => $index,
                ]
            );
        }
    }
}
