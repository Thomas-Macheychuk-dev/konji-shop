<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductShowController extends Controller
{
    public function __invoke(Product $product): View
    {
        $product->load([
            'mainImage',
            'images',
            'variants.attributeValues.attribute',
            'attributeValueImages.attributeValue.attribute',
        ]);

        $defaultVariant = $product->variants
            ->sortByDesc(fn (ProductVariant $variant) => (int) $variant->is_default)
            ->first();

        $productPayload = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'base_images' => $product->images
                ->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'alt' => $image->alt_text ?: $product->name,
                    'is_main' => (bool) $image->is_main,
                    'sort_order' => $image->sort_order,
                ])
                ->values(),

            'option_groups' => $this->buildOptionGroups($product),
            'variants' => $this->buildVariants($product),
            'default_variant_id' => $defaultVariant?->id,
        ];

        return view('pages.products.show', [
            'product' => $product,
            'productPayload' => $productPayload,
        ]);
    }

    private function buildOptionGroups(Product $product): array
    {
        $allValues = $product->variants
            ->flatMap(fn (ProductVariant $variant) => $variant->attributeValues)
            ->unique('id')
            ->values();

        $grouped = $allValues->groupBy(function (AttributeValue $value) {
            return $this->normalizeAttributeCode($value->attribute->name);
        });

        return $grouped
            ->map(function (Collection $values, string $code) {
                $first = $values->first();

                return [
                    'code' => $code,
                    'label' => $this->displayLabelForCode($code, $first?->attribute?->name),
                    'values' => $values
                        ->sortBy('value')
                        ->map(function (AttributeValue $value) {
                            return [
                                'id' => $value->id,
                                'label' => $value->value,
                                'sort_order' => $value->sort_order,
                                'swatch' => [
                                    'type' => $value->swatch_type,
                                    'value' => $value->swatch_value,
                                    'image_url' => $value->swatch_image_url,
                                ],
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $group) => match ($group['code']) {
                'color' => 1,
                'size' => 2,
                default => 99,
            })
            ->values()
            ->all();
    }

    private function buildVariants(Product $product): array
    {
        return $product->variants
            ->map(function (ProductVariant $variant) use ($product) {
                $optionValueIds = $variant->attributeValues
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                $variantImages = $this->resolveVariantImages($product, $variant);

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'price' => $variant->grossPriceAmount(),
                    'price_net' => $variant->price_net_amount,
                    'currency' => $variant->currency?->value,
                    'stock_status' => $variant->stock_status?->value,
                    'is_default' => (bool) $variant->is_default,
                    'option_value_ids' => $optionValueIds,
                    'images' => $variantImages,
                ];
            })
            ->values()
            ->all();
    }

    private function resolveVariantImages(Product $product, ProductVariant $variant): array
    {
        $variantValueIds = $variant->attributeValues->pluck('id')->all();

        $matchedImages = $product->attributeValueImages
            ->filter(fn ($item) => in_array($item->attribute_value_id, $variantValueIds, true))
            ->map(fn ($item) => [
                'id' => 'attribute-value-image-'.$item->id,
                'url' => $item->url,
                'alt' => $variant->sku ?: $product->name,
                'sort_order' => $item->sort_order,
            ])
            ->sortBy('sort_order')
            ->values();

        if ($matchedImages->isNotEmpty()) {
            return $matchedImages->all();
        }

        return $product->images
            ->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'alt' => $image->alt_text ?: $product->name,
                'sort_order' => $image->sort_order,
            ])
            ->values()
            ->all();
    }

    private function normalizeAttributeCode(string $name): string
    {
        $normalized = Str::of($name)->lower()->ascii()->value();

        return match (true) {
            str_contains($normalized, 'kolor') => 'color',
            str_contains($normalized, 'colour') => 'color',
            str_contains($normalized, 'color') => 'color',
            str_contains($normalized, 'rozmiar') => 'size',
            str_contains($normalized, 'size') => 'size',
            default => Str::slug($name, '_'),
        };
    }

    private function displayLabelForCode(string $code, ?string $fallback = null): string
    {
        return match ($code) {
            'color' => 'Colour',
            'size' => 'Size',
            default => $fallback ?: Str::headline($code),
        };
    }
}
