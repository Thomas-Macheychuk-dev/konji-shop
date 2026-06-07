<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

final class WojdakVariantBuilder
{
    /**
     * Cached fallback loaded directly from config/wojdak.php.
     *
     * This protects the importer when Laravel configuration is cached before
     * the new Wojdak config file has been added to the cached config array.
     *
     * @var array<string, mixed>|null
     */
    private ?array $fallbackConfig = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{variants: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function build(array $payload): array
    {
        $woocommerceVariations = $payload['woocommerce_variations'] ?? null;

        if (is_array($woocommerceVariations) && $woocommerceVariations !== []) {
            return $this->buildWooCommerceVariants($payload, $woocommerceVariations);
        }

        $type = $this->productType($payload);
        $gender = $this->gender($payload);

        if ($type === null) {
            return [
                'variants' => [],
                'warnings' => ['Could not determine Wojdak size table type. No variants were generated.'],
            ];
        }

        if ($gender === null) {
            return [
                'variants' => [],
                'warnings' => ['Could not determine Wojdak product gender/category. No variants were generated.'],
            ];
        }

        return match ($type) {
            'footwear' => $this->buildFootwearVariants($payload, $gender),
            'clothing' => $this->buildClothingVariants($payload, $gender),
            default => [
                'variants' => [],
                'warnings' => ['Unsupported Wojdak size table type ['.$type.'].'],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $woocommerceVariations
     * @return array{variants: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function buildWooCommerceVariants(array $payload, array $woocommerceVariations): array
    {
        $variants = [];
        $warnings = [];

        foreach (array_values($woocommerceVariations) as $index => $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $attributes = array_values(array_filter(
                $variation['attributes'] ?? [],
                fn (mixed $attribute): bool => is_array($attribute) && isset($attribute['code'], $attribute['name'], $attribute['value'])
            ));

            if ($attributes === []) {
                $warnings[] = 'Skipped Wojdak WooCommerce variation without attributes.';

                continue;
            }

            $externalVariantId = (string) ($variation['external_variant_id'] ?? 'row-'.$index);
            $sku = $this->stringOrNull($variation['sku'] ?? null)
                ?? $this->generatedSkuFromAttributes($payload, $attributes);

            $isSellable = (bool) ($variation['is_active'] ?? false)
                && (bool) ($variation['is_visible'] ?? false)
                && (bool) ($variation['is_purchasable'] ?? false)
                && is_int($variation['price_gross_amount'] ?? null);

            $variants[] = [
                'external_variant_id' => $externalVariantId,
                'sku' => $sku,
                'attributes' => $attributes,
                'sort_order' => $index,
                'status' => $isSellable ? 'active' : 'draft',
                'stock_status' => (bool) ($variation['is_in_stock'] ?? false) ? 'in_stock' : 'out_of_stock',
                'price_gross_amount' => $variation['price_gross_amount'] ?? null,
                'regular_price_gross_amount' => $variation['regular_price_gross_amount'] ?? null,
                'currency' => 'PLN',
                'vat_rate' => 23,
                'package_weight_grams' => $variation['weight_grams'] ?? null,
                'source_image_url' => $variation['image_url'] ?? null,
                'source_max_qty' => $variation['max_qty'] ?? null,
            ];
        }

        if ($variants === []) {
            $warnings[] = 'No usable Wojdak WooCommerce variations were found.';
        }

        return [
            'variants' => $variants,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{variants: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function buildFootwearVariants(array $payload, string $gender): array
    {
        $sizes = $this->wojdakConfig("size_tables.footwear.{$gender}.sizes", []);

        if (! is_array($sizes) || $sizes === []) {
            return [
                'variants' => [],
                'warnings' => ['No Wojdak footwear sizes configured for gender ['.$gender.'].'],
            ];
        }

        $range = $this->extractNumericRange((string) ($payload['description_text'] ?? ''));

        if ($range !== null) {
            $sizes = array_filter(
                $sizes,
                fn (mixed $data, string|int $size): bool => (int) $size >= $range[0] && (int) $size <= $range[1],
                ARRAY_FILTER_USE_BOTH
            );
        }

        $variants = [];
        $sort = 0;

        foreach (array_keys($sizes) as $size) {
            $size = (string) $size;
            $variants[] = $this->variant($payload, [$this->attribute('size', $size, $sort)], $sort);
            $sort++;
        }

        $warnings = [];

        if ($variants === []) {
            $warnings[] = 'Footwear size range did not overlap with configured Wojdak footwear sizes.';
        }

        return [
            'variants' => $variants,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{variants: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function buildClothingVariants(array $payload, string $gender): array
    {
        $sizes = $this->wojdakConfig("size_tables.clothing.{$gender}.sizes", []);

        if (! is_array($sizes) || $sizes === []) {
            return [
                'variants' => [],
                'warnings' => ['No Wojdak clothing sizes configured for gender ['.$gender.'].'],
            ];
        }

        $text = $this->searchableText($payload);
        $variants = [];
        $sort = 0;

        if ($this->isSkirt($text) && $gender === 'female') {
            $skirtLengths = $this->wojdakConfig('size_tables.clothing.female.skirt_lengths_cm', []);

            foreach (array_keys($sizes) as $size) {
                foreach ($skirtLengths as $skirtLength) {
                    $variants[] = $this->variant($payload, [
                        $this->attribute('size', (string) $size, $sort),
                        $this->attribute('skirt_length', (string) $skirtLength.' cm', $sort),
                    ], $sort);
                    $sort++;
                }
            }

            return [
                'variants' => $variants,
                'warnings' => [],
            ];
        }

        $heightGroupKey = $this->heightGroupKey($text, $gender);
        $heightGroups = $this->wojdakConfig("size_tables.clothing.{$gender}.height_groups.{$heightGroupKey}", []);

        if (! is_array($heightGroups) || $heightGroups === []) {
            return [
                'variants' => [],
                'warnings' => ['No Wojdak height groups configured for key ['.$heightGroupKey.'].'],
            ];
        }

        foreach (array_keys($sizes) as $size) {
            foreach ($heightGroups as $height) {
                $variants[] = $this->variant($payload, [
                    $this->attribute('size', (string) $size, $sort),
                    $this->attribute('height', (string) $height, $sort),
                ], $sort);
                $sort++;
            }
        }

        return [
            'variants' => $variants,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function variant(array $payload, array $attributes, int $index): array
    {
        $externalId = (string) ($payload['external_id'] ?? 'wojdak-product');
        $skuBase = 'WOJDAK-'.Str::upper(Str::slug($externalId, '-'));
        $suffix = collect($attributes)
            ->pluck('value')
            ->map(fn (mixed $value): string => $this->skuSegment((string) $value))
            ->filter()
            ->implode('-');

        return [
            'external_variant_id' => $externalId.'-'.$suffix,
            'sku' => $skuBase.'-'.$suffix,
            'attributes' => $attributes,
            'sort_order' => $index,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $attributes
     */
    private function generatedSkuFromAttributes(array $payload, array $attributes): string
    {
        $externalId = (string) ($payload['external_id'] ?? 'wojdak-product');
        $skuBase = 'WOJDAK-'.Str::upper(Str::slug($externalId, '-'));
        $suffix = collect($attributes)
            ->pluck('value')
            ->map(fn (mixed $value): string => $this->skuSegment((string) $value))
            ->filter()
            ->implode('-');

        return $suffix === '' ? $skuBase : $skuBase.'-'.$suffix;
    }

    private function skuSegment(string $value): string
    {
        $value = str_replace(['/', '\\'], '-', $value);

        return Str::upper(Str::slug($value, '-'));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(string $code, string $value, int $sortOrder): array
    {
        $config = $this->wojdakConfig("attributes.{$code}", []);
        $externalAttributeId = (string) ($config['external_attribute_id'] ?? 'wojdak-'.$code);

        return [
            'code' => $code,
            'name' => (string) ($config['name'] ?? Str::headline($code)),
            'value' => $value,
            'external_attribute_id' => $externalAttributeId,
            'external_option_id' => $externalAttributeId.'-'.Str::slug($value),
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @return array{0:int, 1:int}|null
     */
    private function extractNumericRange(string $text): ?array
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/(?:rozmiar(?:ach|y)?|od)?\s*(\d{2})\s*(?:-|–|do)\s*(\d{2})/iu', $text, $matches) !== 1) {
            return null;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function productType(array $payload): ?string
    {
        $type = $payload['size_table_type'] ?? null;

        if (is_string($type) && in_array($type, ['clothing', 'footwear'], true)) {
            return $type;
        }

        $text = $this->searchableText($payload);

        return match (true) {
            str_contains($text, 'obuwie') || str_contains($text, 'buty') => 'footwear',
            str_contains($text, 'odziez') || str_contains($text, 'odzież') || str_contains($text, 'bluza') || str_contains($text, 'marynarka') => 'clothing',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function gender(array $payload): ?string
    {
        $text = $this->searchableText($payload);

        return match (true) {
            str_contains($text, 'damska') || str_contains($text, 'damskie') || str_contains($text, 'damskich') => 'female',
            str_contains($text, 'meska') || str_contains($text, 'męska') || str_contains($text, 'meskie') || str_contains($text, 'męskie') || str_contains($text, 'męskich') => 'male',
            default => null,
        };
    }

    private function heightGroupKey(string $text, string $gender): string
    {
        $containsLongSleeve = str_contains($text, 'dlugi rekaw')
            || str_contains($text, 'długi rękaw')
            || str_contains($text, 'dlugim rekawem')
            || str_contains($text, 'długim rękawem');
        $containsShortSleeve = str_contains($text, 'krotki rekaw')
            || str_contains($text, 'krótki rękaw')
            || str_contains($text, 'krotkim rekawem')
            || str_contains($text, 'krótkim rękawem')
            || str_contains($text, '3/4');
        $containsLongGroupProduct = str_contains($text, 'spodnie')
            || str_contains($text, 'fartuch')
            || $containsLongSleeve;

        if ($gender === 'female') {
            return $containsShortSleeve && ! $containsLongGroupProduct
                ? 'short_or_three_quarter_sleeve'
                : 'long_sleeve_or_trousers';
        }

        return $containsShortSleeve && ! $containsLongGroupProduct
            ? 'short_sleeve'
            : 'long_sleeve_or_trousers';
    }

    private function isSkirt(string $text): bool
    {
        return str_contains($text, 'spodnica') || str_contains($text, 'spódnica') || str_contains($text, 'spodnice') || str_contains($text, 'spódnice');
    }


    /**
     * Read Wojdak config, falling back to config/wojdak.php if Laravel's
     * configuration repository is stale or cached without the new file.
     */
    private function wojdakConfig(string $key, mixed $default = null): mixed
    {
        $config = Config::get('wojdak');

        if (! is_array($config) || $config === []) {
            $config = $this->fallbackWojdakConfig();

            if ($config !== []) {
                Config::set('wojdak', $config);
            }
        }

        return data_get($config, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackWojdakConfig(): array
    {
        if ($this->fallbackConfig !== null) {
            return $this->fallbackConfig;
        }

        $path = config_path('wojdak.php');

        if (! is_file($path)) {
            return $this->fallbackConfig = [];
        }

        $config = require $path;

        return $this->fallbackConfig = is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function searchableText(array $payload): string
    {
        $text = implode(' ', array_filter([
            $payload['name'] ?? null,
            $payload['description_text'] ?? null,
            $payload['category_url'] ?? null,
            $payload['category_slug'] ?? null,
            $payload['size_table_pdf_url'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value) && (string) $value !== ''));

        return Str::of($text)
            ->lower()
            ->ascii()
            ->value().' '.mb_strtolower($text);
    }
}
