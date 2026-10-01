<?php

declare(strict_types=1);

namespace App\Services\Footwave;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FootwaveProductScraper
{
    public function __construct(
        private readonly FootwaveStoreApiClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scrapeById(int $productId): array
    {
        return $this->scrape(
            $this->client->product($productId),
        );
    }

    /**
     * Transform one top-level WooCommerce Store API product into the
     * stable FootWave source boundary consumed later by the importer.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function scrape(array $product): array
    {
        $productId = $this->positiveInt($product['id'] ?? null);
        $type = $this->stringOrNull($product['type'] ?? null);

        if ($productId === null) {
            throw new RuntimeException(
                'FootWave product payload has no valid product ID.'
            );
        }

        if (! in_array($type, ['simple', 'variable'], true)) {
            throw new RuntimeException(
                'Unsupported FootWave product type ['.
                ($type ?? 'null').
                '] for product '.$productId.'.'
            );
        }

        $purchasable = ($product['is_purchasable'] ?? false) === true;

        $result = [
            'source' => 'footwave',
            'external_product_id' => (string) $productId,
            'external_parent_sku' => $this->stringOrNull(
                $product['sku'] ?? null
            ),
            'source_type' => $type,
            'name' => $this->plainTextOrNull($product['name'] ?? null),
            'slug' => $this->stringOrNull($product['slug'] ?? null),
            'canonical_url' => $this->stringOrNull(
                $product['permalink'] ?? null
            ),
            'short_description_html' => $this->stringOrNull(
                $product['short_description'] ?? null
            ),
            'description_html' => $this->stringOrNull(
                $product['description'] ?? null
            ),
            'images' => $this->images($product),
            'categories' => $this->categories($product),
            'is_purchasable' => $purchasable,
            'is_in_stock' => ($product['is_in_stock'] ?? false) === true,
            'variants_unresolved' => false,
            'eligible' => false,
            'exclusion_reason' => null,
            'variants' => [],
            'warnings' => [],
        ];

        /*
         * This is the important catalogue boundary. FootWave's
         * non-commerce / individual products currently expose themselves
         * as non-purchasable through the Store API.
         */
        if (! $purchasable) {
            $result['exclusion_reason'] = 'not_purchasable';

            return $result;
        }

        if ($type === 'simple') {
            return $this->withSimpleVariant($result, $product);
        }

        return $this->withVariableVariants($result, $product);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function withSimpleVariant(
        array $result,
        array $product,
    ): array {
        $productId = (int) $product['id'];
        $price = $this->minorAmount(
            $product['prices']['price'] ?? null
        );

        if ($price === null) {
            $result['variants_unresolved'] = true;
            $result['exclusion_reason'] = 'missing_simple_product_price';
            $result['warnings'][] =
                'FootWave simple product has no authoritative Store API price.';

            return $result;
        }

        $result['variants'] = [[
            'external_variant_id' => (string) $productId,
            'sku' => $this->stringOrNull($product['sku'] ?? null),
            'attributes' => [],
            'price_gross_amount' => $price,
            'regular_price_gross_amount' => $this->minorAmount(
                $product['prices']['regular_price'] ?? null
            ),
            'currency' => $this->currency($product),
            'stock_status' =>
                ($product['is_in_stock'] ?? false) === true
                    ? 'in_stock'
                    : 'out_of_stock',
            'is_purchasable' =>
                ($product['is_purchasable'] ?? false) === true,
            'source_max_qty' => $this->positiveInt(
                $product['add_to_cart']['maximum'] ?? null
            ),
        ]];

        $result['eligible'] = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function withVariableVariants(
        array $result,
        array $product,
    ): array {
        $parentVariations = $product['variations'] ?? null;

        if (! is_array($parentVariations) || $parentVariations === []) {
            $result['variants_unresolved'] = true;
            $result['exclusion_reason'] = 'missing_parent_variations';
            $result['warnings'][] =
                'FootWave variable product exposes no Store API variation IDs.';

            return $result;
        }

        $definitions = $this->attributeDefinitions($product);
        $variants = [];
        $unresolved = false;

        foreach (array_values($parentVariations) as $index => $parentVariation) {
            if (! is_array($parentVariation)) {
                $unresolved = true;
                $result['warnings'][] =
                    'FootWave parent variation row is not an object.';

                continue;
            }

            $variationId = $this->positiveInt(
                $parentVariation['id'] ?? null
            );

            if ($variationId === null) {
                $unresolved = true;
                $result['warnings'][] =
                    'FootWave parent variation has no valid variation ID.';

                continue;
            }

            try {
                $child = $this->client->product($variationId);
            } catch (Throwable $e) {
                $unresolved = true;
                $result['warnings'][] = sprintf(
                    'Could not hydrate FootWave variation %d: %s',
                    $variationId,
                    $e->getMessage(),
                );

                continue;
            }

            if (
                ($child['type'] ?? null) !== 'variation'
                || (int) ($child['parent'] ?? 0) !== (int) $product['id']
            ) {
                $unresolved = true;
                $result['warnings'][] = sprintf(
                    'FootWave variation %d is not bound to parent %d.',
                    $variationId,
                    (int) $product['id'],
                );

                continue;
            }

            $price = $this->minorAmount(
                $child['prices']['price'] ?? null
            );

            /*
             * A purchasable variation without a price is not safe to import.
             * A non-purchasable/out-of-stock historical option may still be
             * represented as a draft variant later.
             */
            if (
                ($child['is_purchasable'] ?? false) === true
                && $price === null
            ) {
                $unresolved = true;
                $result['warnings'][] = sprintf(
                    'Purchasable FootWave variation %d has no price.',
                    $variationId,
                );

                continue;
            }

            $attributes = $this->variationAttributes(
                $parentVariation,
                $definitions,
            );

            if ($attributes === []) {
                $unresolved = true;
                $result['warnings'][] = sprintf(
                    'FootWave variation %d has no resolved attributes.',
                    $variationId,
                );

                continue;
            }

            $variants[] = [
                'external_variant_id' => (string) $variationId,
                'sku' => $this->stringOrNull($child['sku'] ?? null),
                'attributes' => $attributes,
                'sort_order' => $index,
                'price_gross_amount' => $price,
                'regular_price_gross_amount' => $this->minorAmount(
                    $child['prices']['regular_price'] ?? null
                ),
                'currency' => $this->currency($child),
                'stock_status' =>
                    ($child['is_in_stock'] ?? false) === true
                        ? 'in_stock'
                        : 'out_of_stock',
                'is_purchasable' =>
                    ($child['is_purchasable'] ?? false) === true,
                'source_max_qty' => $this->positiveInt(
                    $child['add_to_cart']['maximum'] ?? null
                ),
                'source_url' => $this->stringOrNull(
                    $child['permalink'] ?? null
                ),
            ];
        }

        $result['variants'] = $variants;
        $result['variants_unresolved'] = $unresolved;

        if ($unresolved) {
            $result['exclusion_reason'] = 'unresolved_variations';

            return $result;
        }

        if ($variants === []) {
            $result['variants_unresolved'] = true;
            $result['exclusion_reason'] = 'no_usable_variations';
            $result['warnings'][] =
                'FootWave variable product has no usable variations.';

            return $result;
        }

        $result['eligible'] = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, array<string, mixed>>
     */
    private function attributeDefinitions(array $product): array
    {
        $definitions = [];

        foreach (($product['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if (($attribute['has_variations'] ?? false) !== true) {
                continue;
            }

            $name = $this->plainTextOrNull($attribute['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $taxonomy = $this->stringOrNull(
                $attribute['taxonomy'] ?? null
            );

            $code = $taxonomy !== null
                ? preg_replace('/^pa_/u', '', $taxonomy)
                : Str::slug($name, '_');

            $code = is_string($code) && $code !== ''
                ? $code
                : Str::slug($name, '_');

            $terms = [];

            foreach (
                array_values(
                    is_array($attribute['terms'] ?? null)
                        ? $attribute['terms']
                        : []
                )
                as $sortOrder => $term
            ) {
                if (! is_array($term)) {
                    continue;
                }

                $slug = $this->stringOrNull($term['slug'] ?? null);
                $value = $this->plainTextOrNull($term['name'] ?? null);

                if ($slug === null || $value === null) {
                    continue;
                }

                $terms[$slug] = [
                    'id' => $this->positiveInt($term['id'] ?? null),
                    'value' => $value,
                    'sort_order' => $sortOrder,
                ];
            }

            $definitions[mb_strtolower($name)] = [
                'name' => $name,
                'code' => $code,
                'taxonomy' => $taxonomy,
                'external_attribute_id' =>
                    'footwave-'.($taxonomy ?: $code),
                'terms' => $terms,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $parentVariation
     * @param array<string, array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    private function variationAttributes(
        array $parentVariation,
        array $definitions,
    ): array {
        $attributes = [];

        foreach (($parentVariation['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $name = $this->plainTextOrNull($attribute['name'] ?? null);
            $rawValue = $this->stringOrNull($attribute['value'] ?? null);

            if ($name === null || $rawValue === null) {
                continue;
            }

            $definition = $definitions[mb_strtolower($name)] ?? null;

            if (! is_array($definition)) {
                continue;
            }

            $term = $definition['terms'][$rawValue] ?? null;
            $displayValue = is_array($term)
                ? (string) $term['value']
                : $rawValue;

            $termId = is_array($term)
                ? $this->positiveInt($term['id'] ?? null)
                : null;

            $externalAttributeId =
                (string) $definition['external_attribute_id'];

            $externalOptionId = $termId !== null
                ? 'footwave-term-'.$termId
                : $externalAttributeId.'-'.Str::slug($rawValue);

            $attributes[] = [
                'code' => (string) $definition['code'],
                'name' => (string) $definition['name'],
                'value' => $displayValue,
                'external_attribute_id' => $externalAttributeId,
                'external_option_id' => $externalOptionId,
                'sort_order' => is_array($term)
                    ? (int) ($term['sort_order'] ?? 0)
                    : count($attributes),
                'source_value' => $rawValue,
            ];
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string>
     */
    private function images(array $product): array
    {
        $images = [];

        foreach (($product['images'] ?? []) as $image) {
            if (! is_array($image)) {
                continue;
            }

            $src = $this->stringOrNull($image['src'] ?? null);

            if ($src !== null) {
                $images[] = $src;
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{id:int|null,name:string,slug:string}>
     */
    private function categories(array $product): array
    {
        $categories = [];

        foreach (($product['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $name = $this->plainTextOrNull($category['name'] ?? null);
            $slug = $this->stringOrNull($category['slug'] ?? null);

            if ($name === null || $slug === null) {
                continue;
            }

            $categories[] = [
                'id' => $this->positiveInt($category['id'] ?? null),
                'name' => $name,
                'slug' => $slug,
            ];
        }

        return $categories;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function currency(array $product): string
    {
        $currency = $this->stringOrNull(
            $product['prices']['currency_code'] ?? null
        );

        return strtoupper($currency ?? 'PLN');
    }

    private function minorAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (
            is_string($value)
            && preg_match('/^\d+$/', trim($value)) === 1
        ) {
            return (int) trim($value);
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function plainTextOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $value = trim(
            html_entity_decode(
                $value,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            )
        );

        return $value === '' ? null : $value;
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
