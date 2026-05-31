<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Shop\ShopSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductShowController extends Controller
{
    public function __construct(
        private readonly ShopSettings $shopSettings,
    ) {}

    public function __invoke(Product $product): View
    {
        abort_unless($product->status?->isActive() === true, 404);

        $product->load([
            'mainImage',
            'images',
            'variants' => fn ($query) => $query
                ->where('status', ProductVariantStatus::ACTIVE->value)
                ->with('attributeValues.attribute'),
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
            'default_image' => $this->imagePayload($product->selectedDefaultImage(), $product->name),
            'base_images' => $this->baseImages($product),

            'option_groups' => $this->buildOptionGroups($product),
            'variants' => $this->buildVariants($product),
            'default_variant_id' => $defaultVariant?->id,
        ];

        $seoTitle = $this->seoTitle($product);
        $seoDescription = $this->seoDescription($product);
        $canonicalUrl = route('products.show', $product->slug);
        $openGraphImage = $this->openGraphImage($product, $defaultVariant);
        $structuredData = $this->structuredData($product, $defaultVariant, $seoDescription, $canonicalUrl);

        return view('pages.products.show', [
            'product' => $product,
            'productPayload' => $productPayload,
            'defaultVariant' => $defaultVariant,

            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphImage' => $openGraphImage,
            'openGraphType' => 'product',
            'structuredData' => $structuredData,
        ]);
    }

    private function seoTitle(Product $product): string
    {
        return $this->limitText(
            $product->seo_title ?: $product->name,
            70
        );
    }

    private function seoDescription(Product $product): string
    {
        $description = $product->seo_description
            ?: $product->short_description
                ?: $product->description
                    ?: $product->name;

        return $this->limitText($description, 160);
    }

    private function openGraphImage(Product $product, ?ProductVariant $defaultVariant): ?string
    {
        $imageUrl = $product->default_image_url
            ?? $defaultVariant?->main_image_url
            ?? $product->images->first()?->url;

        return $this->absoluteUrl($imageUrl);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredData(
        Product $product,
        ?ProductVariant $defaultVariant,
        string $seoDescription,
        string $canonicalUrl,
    ): array {
        return [
            $this->productStructuredData($product, $defaultVariant, $seoDescription, $canonicalUrl),
            $this->breadcrumbStructuredData($product, $canonicalUrl),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productStructuredData(
        Product $product,
        ?ProductVariant $defaultVariant,
        string $seoDescription,
        string $canonicalUrl,
    ): array {
        $images = $this->structuredDataImages($product, $defaultVariant);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $seoDescription,
            'url' => $canonicalUrl,
            'brand' => [
                '@type' => 'Brand',
                'name' => $this->shopSettings->shopName(),
            ],
        ];

        if ($images !== []) {
            $data['image'] = $images;
        }

        if ($defaultVariant?->sku) {
            $data['sku'] = $defaultVariant->sku;
        }

        if ($defaultVariant !== null && $defaultVariant->grossPriceAmount() !== null) {
            $data['offers'] = [
                '@type' => 'Offer',
                'url' => $canonicalUrl,
                'priceCurrency' => $defaultVariant->currency?->value ?? 'PLN',
                'price' => number_format($defaultVariant->grossPriceAmount() / 100, 2, '.', ''),
                'availability' => $this->schemaAvailability($defaultVariant),
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => [
                    '@type' => 'Organization',
                    'name' => $this->shopSettings->companyName(),
                ],
                'hasMerchantReturnPolicy' => $this->merchantReturnPolicyStructuredData(),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantReturnPolicyStructuredData(): array
    {
        return [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'PL',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => $this->shopSettings->withdrawalDays(),
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/ReturnShippingFees',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function breadcrumbStructuredData(Product $product, string $canonicalUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Home',
                    'item' => route('home'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $product->name,
                    'item' => $canonicalUrl,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function structuredDataImages(Product $product, ?ProductVariant $defaultVariant): array
    {
        $urls = collect();

        if ($product->default_image_url) {
            $urls->push($product->default_image_url);
        }

        if ($defaultVariant?->main_image_url) {
            $urls->push($defaultVariant->main_image_url);
        }

        $urls = $urls
            ->merge($product->images->pluck('url'))
            ->filter()
            ->map(fn (string $url): ?string => $this->absoluteUrl($url))
            ->filter()
            ->unique()
            ->values();

        return $urls->all();
    }

    private function schemaAvailability(ProductVariant $variant): string
    {
        return match ($variant->stock_status) {
            StockStatus::IN_STOCK => 'https://schema.org/InStock',
            StockStatus::OUT_OF_STOCK => 'https://schema.org/OutOfStock',
            default => 'https://schema.org/LimitedAvailability',
        };
    }

    private function absoluteUrl(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $url = trim((string) $url);

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return url($url);
    }

    private function limitText(?string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '');

        if ($value === '') {
            return '';
        }

        return Str::limit($value, $limit, '');
    }

    private function baseImages(Product $product): array
    {
        $defaultImage = $product->selectedDefaultImage();
        $defaultProductImageId = $defaultImage instanceof ProductImage ? $defaultImage->id : null;

        return $product->images
            ->sortBy(fn (ProductImage $image): array => [
                $defaultProductImageId === $image->id ? 0 : 1,
                $image->sort_order,
                $image->id,
            ])
            ->map(fn (ProductImage $image): array => $this->imagePayload($image, $product->name))
            ->values()
            ->all();
    }

    private function imagePayload(ProductImage|ProductAttributeValueImage|null $image, string $fallbackAlt): ?array
    {
        if ($image === null) {
            return null;
        }

        return [
            'id' => $image instanceof ProductAttributeValueImage
                ? 'attribute-value-image-'.$image->id
                : $image->id,
            'url' => $image->url,
            'alt' => $image->alt_text ?: $fallbackAlt,
            'is_main' => (bool) $image->is_main,
            'sort_order' => $image->sort_order,
        ];
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

        $defaultImage = $product->selectedDefaultImage();
        $defaultAttributeValueImageId = $defaultImage instanceof ProductAttributeValueImage ? $defaultImage->id : null;

        $matchedImages = $product->attributeValueImages
            ->filter(fn ($item) => in_array($item->attribute_value_id, $variantValueIds, true))
            ->sortBy(fn (ProductAttributeValueImage $image): array => [
                $defaultAttributeValueImageId === $image->id ? 0 : 1,
                $image->sort_order,
                $image->id,
            ])
            ->map(fn (ProductAttributeValueImage $image): array => $this->imagePayload($image, $variant->sku ?: $product->name))
            ->values();

        if ($matchedImages->isNotEmpty()) {
            return $matchedImages->all();
        }

        return $this->baseImages($product);
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
