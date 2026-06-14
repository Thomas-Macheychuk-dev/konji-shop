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
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EldanProductImporter
{
    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {
    }

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
                    'status' => ProductStatus::ACTIVE,
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
        $imageRows = [];

        foreach (array_values(array_unique($normalized['images'] ?? [])) as $index => $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $imported = $this->remoteImageImporter->import(
                $url,
                'products/eldan/'.$product->external_id.'/gallery'
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

    private function syncAttributes(array $normalized): array
    {
        $map = [];

        foreach (($normalized['attributes'] ?? []) as $attributeData) {
            $displayType = $this->mapDisplayType($attributeData['swatch_type'] ?? null);
            $externalAttributeId = $this->stringOrNull($attributeData['external_attribute_id'] ?? null);
            $attributeName = (string) ($attributeData['name'] ?? '');

            if ($attributeName === '') {
                continue;
            }

            $attribute = $this->resolveAttribute(
                externalAttributeId: $externalAttributeId,
                name: $attributeName,
                displayType: $displayType,
            );

            foreach (($attributeData['options'] ?? []) as $sortOrder => $optionData) {
                $externalOptionId = $this->stringOrNull($optionData['external_option_id'] ?? null);
                $optionLabel = (string) ($optionData['label'] ?? '');

                if ($optionLabel === '') {
                    continue;
                }

                $declaredSwatchType = $attributeData['swatch_type'] ?? null;
                $rawSwatchValue = $optionData['swatch_value'] ?? null;

                $swatchType = null;
                $swatchValue = null;
                $swatchImageDisk = null;
                $swatchImagePath = null;
                $swatchSourceUrl = null;

                if (is_string($rawSwatchValue) && $rawSwatchValue !== '') {
                    if ($this->isHexColour($rawSwatchValue)) {
                        $swatchType = 'color';
                        $swatchValue = $rawSwatchValue;
                    } elseif (filter_var($rawSwatchValue, FILTER_VALIDATE_URL)) {
                        $swatchType = 'image';

                        $importedSwatch = $this->remoteImageImporter->import(
                            $rawSwatchValue,
                            'swatches/eldan/attribute-'.$this->pathSegment($externalAttributeId, 'unknown')
                                .'/option-'.$this->pathSegment($externalOptionId, Str::slug($optionLabel))
                        );

                        $swatchImageDisk = $importedSwatch['disk'];
                        $swatchImagePath = $importedSwatch['path'];
                        $swatchSourceUrl = $importedSwatch['source_url'];
                    } else {
                        $swatchType = $declaredSwatchType;
                    }
                }

                $value = $this->resolveAttributeValue(
                    attribute: $attribute,
                    externalOptionId: $externalOptionId,
                    value: $optionLabel,
                    sortOrder: (int) $sortOrder,
                    swatchType: $swatchType,
                    swatchValue: $swatchValue,
                    swatchImageDisk: $swatchImageDisk,
                    swatchImagePath: $swatchImagePath,
                    swatchSourceUrl: $swatchSourceUrl,
                );

                $map[$attributeName][$optionLabel] = $value->id;

                if ($externalAttributeId !== null && $externalOptionId !== null) {
                    $map['by_attribute_id'][$externalAttributeId][$externalOptionId] = $value->id;
                }
            }
        }

        return $map;
    }

    private function resolveAttribute(?string $externalAttributeId, string $name, AttributeDisplayType $displayType): Attribute
    {
        $slug = Str::slug($name);

        $attribute = $externalAttributeId !== null
            ? Attribute::query()->where('external_attribute_id', $externalAttributeId)->first()
            : null;

        if (! $attribute) {
            $attribute = Attribute::query()->where('slug', $slug)->first();
        }

        if ($attribute) {
            $updates = [
                'name' => $name,
                'display_type' => $displayType,
            ];

            if ($externalAttributeId !== null && ! filled($attribute->external_attribute_id)) {
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

    private function resolveAttributeValue(
        Attribute $attribute,
        ?string $externalOptionId,
        string $value,
        int $sortOrder,
        ?string $swatchType,
        ?string $swatchValue,
        ?string $swatchImageDisk,
        ?string $swatchImagePath,
        ?string $swatchSourceUrl,
    ): AttributeValue {
        $slug = Str::slug($value);

        $attributeValue = $externalOptionId !== null
            ? AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where('external_option_id', $externalOptionId)
                ->first()
            : null;

        if (! $attributeValue) {
            $attributeValue = AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where('slug', $slug)
                ->first();
        }

        $attributes = [
            'value' => $value,
            'sort_order' => $sortOrder,
            'swatch_type' => $swatchType,
            'swatch_value' => $swatchValue,
            'swatch_image_disk' => $swatchImageDisk,
            'swatch_image_path' => $swatchImagePath,
            'swatch_source_url' => $swatchSourceUrl,
        ];

        if ($attributeValue) {
            if ($externalOptionId !== null && ! filled($attributeValue->external_option_id)) {
                $attributes['external_option_id'] = $externalOptionId;
            }

            $attributeValue->update($attributes);

            return $attributeValue;
        }

        return AttributeValue::query()->create(array_merge($attributes, [
            'attribute_id' => $attribute->id,
            'external_option_id' => $externalOptionId,
            'slug' => $slug,
        ]));
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
                        ?: $normalized['external_parent_sku'].'-'.$variantData['external_variant_id'],
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
                $attributeCode = $resolvedAttribute['code'] ?? null;
                $externalAttributeId = isset($resolvedAttribute['external_attribute_id'])
                    ? (string) $resolvedAttribute['external_attribute_id']
                    : null;
                $externalOptionId = isset($resolvedAttribute['external_option_id'])
                    ? (string) $resolvedAttribute['external_option_id']
                    : null;

                $id = null;

                if ($externalAttributeId !== null && $externalOptionId !== null) {
                    $id = $attributeValueMap['by_attribute_id'][$externalAttributeId][$externalOptionId] ?? null;
                }

                if ($id === null && $attributeName !== null && $attributeValue !== null) {
                    $id = $attributeValueMap[$attributeName][$attributeValue] ?? null;
                }

                if ($id) {
                    $attributeValueIds[] = $id;

                    if (is_string($attributeCode) && str_contains($attributeCode, 'color')) {
                        $colourValueId = $id;
                    }
                }
            }

            $variant->attributeValues()->sync($attributeValueIds);

            if ($colourValueId !== null && ! isset($syncedColourValueIds[$colourValueId])) {
                $this->syncColourGalleryImages(
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

    private function syncColourGalleryImages(Product $product, int $attributeValueId, array $images, string $productName): void
    {
        $imageRows = [];

        foreach (array_values(array_unique($images)) as $index => $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $imported = $this->remoteImageImporter->import(
                $url,
                'products/eldan/'.$product->external_id.'/attribute-values/'.$attributeValueId
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

        ProductAttributeValueImage::query()
            ->where('product_id', $product->id)
            ->where('attribute_value_id', $attributeValueId)
            ->when(
                $paths !== [],
                fn ($query) => $query->whereNotIn('path', $paths),
                fn ($query) => $query
            )
            ->delete();

        foreach ($imageRows as $row) {
            ProductAttributeValueImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'attribute_value_id' => $attributeValueId,
                    'path' => $row['path'],
                ],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $productName,
                    'title' => $productName,
                    'is_main' => $row['is_main'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function pathSegment(?string $value, string $fallback): string
    {
        return $value !== null && $value !== '' ? $value : $fallback;
    }

    private function isHexColour(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
    }
}
