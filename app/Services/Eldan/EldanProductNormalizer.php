<?php

namespace App\Services\Eldan;

class EldanProductNormalizer
{
    public function __construct(
        private readonly EldanProductContentCleaner $cleaner
    ) {}

    public function normalize(array $payload): array
    {
        $product = $payload['product'] ?? [];
        $config = $payload['config'] ?? [];
        $sections = $payload['sections'] ?? [];

        $attributeMaps = $this->buildAttributeMaps($config['attributes'] ?? []);
        $variantImages = $this->buildVariantImagesMap(
            $config['variant_images'] ?? []
        );

        $variants = $this->buildVariants(
            $config['index'] ?? [],
            $config['variant_prices'] ?? [],
            $attributeMaps,
            $variantImages
        );

        $mainDescription = $this->cleaner->cleanHtml($product['description'] ?? null);
        $productDetails = $this->buildSectionBlock('Szczegóły produktu', $sections['product_details_html'] ?? null);
        $care = $this->buildSectionBlock('Skład i Pielęgnacja', $sections['care_html'] ?? null);
        $sizeTable = $this->buildSectionBlock('Tabela rozmiarów', $sections['size_table_html'] ?? null);

        $mainImage = null;
        if (! empty($product['base_image']) && is_array($product['base_image'])) {
            $mainImage = $this->pickPreferredImageUrl($product['base_image']);
        }

        return [
            'external_id' => $product['id'] ?? null,
            'external_parent_sku' => $product['sku'] ?? null,
            'name' => $product['name'] ?? null,
            'slug' => $product['url_key'] ?? null,
            'short_description_html' => $this->cleaner->cleanShortHtml($product['short_description'] ?? null),
            'description_html' => $this->joinHtmlBlocks([
                $mainDescription,
                $productDetails,
                $care,
                $sizeTable,
            ]),
            'category_name' => $product['category_name'] ?? null,
            'is_saleable' => (bool) ($product['is_saleable'] ?? false),
            'is_bestseller' => (bool) ($product['is_bestseller'] ?? false),
            'main_image' => $mainImage,
            'images' => $this->extractImages($product),
            'base_price' => $this->normalizePriceBlock($product['prices']['final'] ?? null),
            'attributes' => $this->normalizeAttributes($config['attributes'] ?? []),
            'variants' => $variants,
        ];
    }

    private function buildSectionBlock(string $heading, ?string $rawHtml): ?string
    {
        $cleaned = $this->cleaner->cleanHtml($rawHtml);

        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        return sprintf('<h3>%s</h3>%s', $heading, $cleaned);
    }

    private function joinHtmlBlocks(array $blocks): ?string
    {
        $blocks = array_values(array_filter($blocks, fn ($block) => $block !== null && trim($block) !== ''));

        if ($blocks === []) {
            return null;
        }

        return implode('', $blocks);
    }

    private function normalizeAttributes(array $attributes): array
    {
        return array_map(function (array $attribute) {
            return [
                'external_attribute_id' => $attribute['id'] ?? null,
                'code' => $attribute['code'] ?? null,
                'name' => $attribute['label'] ?? null,
                'swatch_type' => $attribute['swatch_type'] ?? null,
                'options' => array_map(function (array $option) {
                    return [
                        'external_option_id' => $option['id'] ?? null,
                        'label' => $option['label'] ?? null,
                        'swatch_value' => $option['swatch_value'] ?? null,
                        'product_ids' => $option['products'] ?? [],
                    ];
                }, $attribute['options'] ?? []),
            ];
        }, $attributes);
    }

    private function buildAttributeMaps(array $attributes): array
    {
        $maps = [];

        foreach ($attributes as $attribute) {
            $attributeId = (string) ($attribute['id'] ?? '');

            if ($attributeId === '') {
                continue;
            }

            $maps[$attributeId] = [
                'id' => $attribute['id'],
                'code' => $attribute['code'] ?? null,
                'label' => $attribute['label'] ?? null,
                'swatch_type' => $attribute['swatch_type'] ?? null,
                'options' => [],
            ];

            foreach ($attribute['options'] ?? [] as $option) {
                $optionId = (string) ($option['id'] ?? '');

                if ($optionId === '') {
                    continue;
                }

                $maps[$attributeId]['options'][$optionId] = [
                    'id' => $option['id'],
                    'label' => $option['label'] ?? null,
                    'swatch_value' => $option['swatch_value'] ?? null,
                ];
            }
        }

        return $maps;
    }

    private function buildVariants(
        array $index,
        array $variantPrices,
        array $attributeMaps,
        array $variantImages
    ): array {
        $variants = [];

        foreach ($index as $variantId => $attributeOptionMap) {
            $resolvedAttributes = [];

            foreach ($attributeOptionMap as $attributeId => $optionId) {
                $attributeId = (string) $attributeId;
                $optionId = (string) $optionId;

                $attribute = $attributeMaps[$attributeId] ?? null;
                $option = $attribute['options'][$optionId] ?? null;

                if (! $attribute || ! $option) {
                    continue;
                }

                $resolvedAttributes[] = [
                    'external_attribute_id' => $attribute['id'],
                    'external_option_id' => $option['id'],
                    'code' => $attribute['code'],
                    'name' => $attribute['label'],
                    'value' => $option['label'],
                    'swatch_type' => $attribute['swatch_type'],
                    'swatch_value' => $option['swatch_value'],
                ];
            }

            $priceBlock = $variantPrices[$variantId]['final'] ?? null;

            $variants[] = [
                'external_variant_id' => (int) $variantId,
                'sku' => null,
                'attributes' => $resolvedAttributes,
                'price' => $this->normalizePriceBlock($priceBlock),
                'images' => $variantImages[(string) $variantId] ?? [],
            ];
        }

        usort($variants, function (array $a, array $b) {
            return $a['external_variant_id'] <=> $b['external_variant_id'];
        });

        return $variants;
    }

    private function normalizePriceBlock(?array $priceBlock): ?array
    {
        if (! $priceBlock) {
            return null;
        }

        $raw = $priceBlock['price'] ?? null;

        return [
            'amount_decimal' => $raw,
            'amount_minor' => $raw !== null ? (int) round(((float) $raw) * 100) : null,
            'formatted' => $priceBlock['formatted_price'] ?? null,
            'currency' => 'PLN',
        ];
    }

    private function extractImages(array $product): array
    {
        $groups = [];

        if (! empty($product['base_image']) && is_array($product['base_image'])) {
            $groups[] = $product['base_image'];
        }

        if (! empty($product['images']) && is_array($product['images'])) {
            foreach ($product['images'] as $image) {
                $groups[] = $image;
            }
        }

        $result = [];
        $seen = [];

        foreach ($groups as $group) {
            $preferred = $this->pickPreferredImageUrl($group);

            if ($preferred === null) {
                continue;
            }

            $canonicalKey = $this->canonicalizeImageUrl($preferred);

            if (isset($seen[$canonicalKey])) {
                continue;
            }

            $seen[$canonicalKey] = true;
            $result[] = $preferred;
        }

        return array_values($result);
    }

    private function buildVariantImagesMap(array $variantImages): array
    {
        $map = [];

        foreach ($variantImages as $variantId => $images) {
            $map[(string) $variantId] = $this->flattenVariantImages($images);
        }

        return $map;
    }

    private function flattenVariantImages(array $images): array
    {
        $result = [];
        $seen = [];

        foreach ($images as $image) {
            $preferred = $this->pickPreferredImageUrl($image);

            if ($preferred === null) {
                continue;
            }

            $canonicalKey = $this->canonicalizeImageUrl($preferred);

            if (isset($seen[$canonicalKey])) {
                continue;
            }

            $seen[$canonicalKey] = true;
            $result[] = $preferred;
        }

        return array_values($result);
    }

    private function pickPreferredImageUrl(mixed $image): ?string
    {
        $candidates = [];

        if (is_string($image) && $image !== '') {
            $candidates[] = $image;
        }

        if (is_array($image)) {
            foreach ([
                         'original_image_url',
                         'large_image_url',
                         'medium_image_url',
                         'small_image_url',
                         'url',
                         'path',
                     ] as $key) {
                if (! empty($image[$key]) && is_string($image[$key])) {
                    $candidates[] = $image[$key];
                }
            }
        }

        $candidates = array_values(array_unique(array_filter(
            $candidates,
            fn ($url) => is_string($url) && trim($url) !== ''
        )));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (string $a, string $b): int {
            return $this->imageUrlPriority($a) <=> $this->imageUrlPriority($b);
        });

        return $candidates[0] ?? null;
    }

    private function imageUrlPriority(string $url): int
    {
        $parsed = parse_url($url);
        $query = [];

        if (! empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        if (! empty($query['p'])) {
            return 0;
        }

        if (str_contains($url, 'original')) {
            return 1;
        }

        if (str_contains($url, 'large')) {
            return 2;
        }

        if (str_contains($url, 'medium')) {
            return 3;
        }

        if (str_contains($url, 'small')) {
            return 4;
        }

        return 10;
    }

    private function canonicalizeImageUrl(string $url): string
    {
        $parsed = parse_url($url);
        $query = [];

        if (! empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        if (! empty($query['p']) && is_string($query['p'])) {
            return urldecode($query['p']);
        }

        $path = $parsed['path'] ?? $url;

        return is_string($path) ? $path : $url;
    }
}
