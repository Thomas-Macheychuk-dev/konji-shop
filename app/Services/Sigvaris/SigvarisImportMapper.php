<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use Illuminate\Support\Str;

final class SigvarisImportMapper
{
    private const SOURCE = 'sigvaris';

    /**
     * @param array<string,mixed> $productCatalogue
     * @param array<string,mixed> $combinationCatalogue
     * @return array<string,mixed>
     */
    public function mapCatalogue(array $productCatalogue, array $combinationCatalogue, ?int $limit = null, int $offset = 0): array
    {
        $sourceProducts = $this->arrayRecords($productCatalogue['products'] ?? []);
        $combinationProducts = $this->arrayRecords($combinationCatalogue['products'] ?? []);
        $combinationByProductId = [];
        $errors = [];
        $reviewItems = [];

        foreach ($combinationProducts as $record) {
            $id = $this->stringOrNull($record['external_product_id'] ?? null);
            if ($id === null) {
                $errors[] = 'Combination catalogue contains a product without external_product_id.';
                continue;
            }
            if (isset($combinationByProductId[$id])) {
                $errors[] = 'Combination catalogue contains duplicate product ID '.$id.'.';
                continue;
            }
            $combinationByProductId[$id] = $record;
        }

        $selectedProducts = array_slice($sourceProducts, max(0, $offset), $limit === null ? null : max(1, $limit));
        $mappedProducts = [];
        $seenProductIds = [];
        $seenPlannedVariantIds = [];
        $seenSourceVariantIds = [];
        $categoryPaths = [];
        $imageCount = 0;
        $downloadCount = 0;
        $sourceCombinationCount = 0;
        $plannedVariantCount = 0;
        $vatBreakdown = [];
        $manufacturerBreakdown = [];
        $defaultVariantCount = 0;

        foreach ($selectedProducts as $index => $sourceProduct) {
            $productId = $this->stringOrNull($sourceProduct['external_product_id'] ?? null);
            $combinationRecord = $productId !== null ? ($combinationByProductId[$productId] ?? null) : null;
            $mapped = $this->mapProduct($sourceProduct, is_array($combinationRecord) ? $combinationRecord : null);
            $mappedProducts[] = $mapped;
            $label = $mapped['product']['name'] ?? 'Product '.($index + 1);

            foreach ($mapped['errors'] ?? [] as $error) {
                $errors[] = $label.': '.$error;
            }
            foreach ($mapped['review_items'] ?? [] as $item) {
                $reviewItems[] = $label.': '.$item;
            }

            $externalId = $mapped['product']['external_id'] ?? null;
            if (is_string($externalId) && $externalId !== '') {
                if (isset($seenProductIds[$externalId])) {
                    $errors[] = $label.': duplicate external product ID '.$externalId.'.';
                }
                $seenProductIds[$externalId] = true;
            }

            foreach ($mapped['categories'] ?? [] as $category) {
                if (! is_array($category)) {
                    continue;
                }
                $path = $this->stringList($category['path'] ?? []);
                if ($path !== []) {
                    $categoryPaths[implode(' > ', $path)] = true;
                }
            }

            $imageCount += count($mapped['images'] ?? []);
            $downloadCount += count($mapped['downloads'] ?? []);
            $sourceCombinationCount += (int) ($mapped['source_combination_count'] ?? 0);
            $plannedVariantCount += count($mapped['variants'] ?? []);

            $vat = $mapped['tax']['vat_rate'] ?? null;
            if (is_numeric($vat)) {
                $key = number_format((float) $vat, 2, '.', '');
                $vatBreakdown[$key] = ($vatBreakdown[$key] ?? 0) + 1;
            }

            $manufacturer = $this->stringOrNull($mapped['product']['manufacturer'] ?? null) ?? 'UNKNOWN';
            $manufacturerBreakdown[$manufacturer] = ($manufacturerBreakdown[$manufacturer] ?? 0) + 1;

            foreach ($mapped['variants'] ?? [] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $plannedId = $this->stringOrNull($variant['external_variant_id'] ?? null);
                if ($plannedId !== null) {
                    if (isset($seenPlannedVariantIds[$plannedId])) {
                        $errors[] = $label.': duplicate planned variant ID '.$plannedId.'.';
                    }
                    $seenPlannedVariantIds[$plannedId] = true;
                }

                $sourceId = $this->stringOrNull($variant['source_external_variant_id'] ?? null);
                if ($sourceId !== null) {
                    if (isset($seenSourceVariantIds[$sourceId])) {
                        $errors[] = $label.': source PrestaShop combination ID '.$sourceId.' is reused across products.';
                    }
                    $seenSourceVariantIds[$sourceId] = true;
                }

                if (($variant['is_default'] ?? false) === true) {
                    $defaultVariantCount++;
                }
            }
        }

        if ($limit === null && $offset === 0) {
            $sourceProductIds = [];

            foreach ($sourceProducts as $sourceProduct) {
                $id = $this->stringOrNull($sourceProduct['external_product_id'] ?? null);
                if ($id === null) {
                    continue;
                }

                $sourceProductIds[$id] = true;

                if (! isset($combinationByProductId[$id])) {
                    $errors[] = 'Product '.$id.' is missing from the combination catalogue.';
                }
            }

            foreach ($combinationByProductId as $combinationProductId => $_record) {
                $id = (string) $combinationProductId;

                if (! isset($sourceProductIds[$id])) {
                    $errors[] = 'Combination catalogue product '.$id.' is absent from product-data.json.';
                }
            }
        }

        ksort($vatBreakdown);
        arsort($manufacturerBreakdown);
        $errors = array_values(array_unique($errors));
        $reviewItems = array_values(array_unique($reviewItems));

        return [
            'source' => self::SOURCE,
            'platform' => 'prestashop',
            'mode' => 'import_mapping_dry_run',
            'database_writes' => false,
            'images_downloaded' => false,
            'source_product_count' => count($sourceProducts),
            'source_combination_product_count' => count($combinationProducts),
            'selected_product_count' => count($selectedProducts),
            'mapped_product_count' => count($mappedProducts),
            'products' => $mappedProducts,
            'summary' => [
                'unique_external_product_ids' => count($seenProductIds),
                'source_concrete_combinations' => $sourceCombinationCount,
                'planned_variants' => $plannedVariantCount,
                'unique_source_combination_ids' => count($seenSourceVariantIds),
                'unique_planned_variant_ids' => count($seenPlannedVariantIds),
                'stable_default_variants' => $plannedVariantCount - $sourceCombinationCount,
                'products_with_default_variant' => $defaultVariantCount,
                'distinct_category_paths' => count($categoryPaths),
                'category_paths' => array_keys($categoryPaths),
                'images' => $imageCount,
                'downloads' => $downloadCount,
                'vat_breakdown' => $vatBreakdown,
                'manufacturer_breakdown' => $manufacturerBreakdown,
                'planned_product_status' => 'draft',
                'planned_variant_status' => 'draft',
                'variant_identity' => 'prestashop_id_product_attribute',
                'sku_strategy' => 'SIGVARIS-{product_id}-{combination_id}',
            ],
            'errors' => $errors,
            'review_items' => $reviewItems,
            'ready_for_local_import_implementation' => $mappedProducts !== [] && $errors === [],
        ];
    }

    /** @param array<string,mixed> $source @param array<string,mixed>|null $combinationRecord @return array<string,mixed> */
    public function mapProduct(array $source, ?array $combinationRecord): array
    {
        $errors = [];
        $reviewItems = [];
        $productId = $this->stringOrNull($source['external_product_id'] ?? null);
        $name = $this->stringOrNull($source['name'] ?? null);
        $currency = strtoupper($this->stringOrNull($source['currency'] ?? null) ?? 'PLN');
        $gross = $this->numericOrNull($source['price_gross_amount'] ?? null);
        $net = $this->numericOrNull($source['price_net_amount'] ?? null);
        $vat = $this->numericOrNull($source['tax_rate_percent'] ?? null);
        $manufacturer = $this->stringOrNull($source['manufacturer'] ?? null);
        $sourceAttributes = $this->arrayRecords($source['attributes'] ?? []);
        $combinations = $combinationRecord !== null ? $this->arrayRecords($combinationRecord['combinations'] ?? []) : [];

        if ($productId === null) {
            $errors[] = 'external product ID is missing.';
        }
        if ($name === null) {
            $errors[] = 'product name is missing.';
        }
        if ($currency !== 'PLN') {
            $errors[] = 'unsupported currency '.$currency.'.';
        }
        if ($gross === null || $gross <= 0) {
            $errors[] = 'positive gross price is missing.';
        }
        if ($net === null || $net <= 0) {
            $errors[] = 'positive net price is missing.';
        }
        if ($vat === null || ! in_array(round($vat, 2), [8.0, 23.0], true)) {
            $errors[] = 'VAT must resolve to the source-supported 8% or 23% rate.';
        }
        if ($manufacturer === null) {
            $reviewItems[] = 'source manufacturer is not stated; do not invent Producent during import.';
        }
        if ($combinationRecord === null) {
            $errors[] = 'combination enumeration record is missing.';
        } elseif (($combinationRecord['truncated'] ?? false) === true) {
            $errors[] = 'combination enumeration is truncated.';
        } elseif (($combinationRecord['failed_requests'] ?? []) !== []) {
            $errors[] = 'combination enumeration contains failed refresh requests.';
        }
        if ($sourceAttributes !== [] && $combinations === []) {
            $errors[] = 'source product has selectors but no concrete PrestaShop combinations.';
        }

        $images = $this->images($source['images'] ?? []);
        $downloads = $this->downloads($source['downloads'] ?? []);
        $categories = $this->categories($source['source_category_paths'] ?? []);

        if ($images === []) {
            $errors[] = 'no product images are mapped.';
        }
        if ($categories === []) {
            $errors[] = 'no category path is mapped.';
        }

        $variants = $this->variants($source, $combinationRecord, $productId, $currency, $gross, $vat, $errors);
        $attributes = $this->attributes($variants, $manufacturer);
        $slug = $name !== null ? Str::slug($name) : null;

        return [
            'source' => self::SOURCE,
            'source_url' => $this->stringOrNull($source['source_url'] ?? null),
            'canonical_url' => $this->stringOrNull($source['canonical_url'] ?? null),
            'product' => [
                'external_source' => self::SOURCE,
                'external_id' => $productId,
                'external_parent_sku' => $productId !== null ? 'SIGVARIS-'.$productId : null,
                'name' => $name,
                'slug' => $slug,
                'status' => 'draft',
                'short_description_html' => null,
                'description_html' => $this->stringOrNull($source['description_html'] ?? null),
                'seo_title' => $name,
                'seo_description' => $this->seoDescription($source['description_html'] ?? null),
                'manufacturer' => $manufacturer,
                'features' => $this->arrayRecords($source['features'] ?? []),
            ],
            'tax' => [
                'is_medical_device' => $source['is_medical_device'] ?? null,
                'vat_rate' => $vat,
                'requires_review' => false,
                'gross_minor' => $gross !== null ? $this->moneyToMinorUnits($gross) : null,
                'net_minor' => $net !== null ? $this->moneyToMinorUnits($net) : null,
                'currency' => $currency,
                'source' => 'sigvaris_price_history',
            ],
            'categories' => $categories,
            'attributes' => $attributes,
            'source_combination_count' => count($combinations),
            'variants' => $variants,
            'images' => $images,
            'downloads' => $downloads,
            'size_chart' => $this->sizeChart($source['size_chart'] ?? null),
            'videos' => [],
            'errors' => array_values(array_unique($errors)),
            'review_items' => array_values(array_unique($reviewItems)),
        ];
    }

    /** @param array<string,mixed>|null $combinationRecord @param list<string> $errors @return list<array<string,mixed>> */
    private function variants(array $source, ?array $combinationRecord, ?string $productId, string $currency, ?float $productGross, ?float $vat, array &$errors): array
    {
        if ($productId === null) {
            return [];
        }

        $combinations = $combinationRecord !== null ? $this->arrayRecords($combinationRecord['combinations'] ?? []) : [];
        $defaultCombinationId = $this->stringOrNull($source['default_combination_id'] ?? $combinationRecord['default_combination_id'] ?? null);

        if ($combinations === []) {
            $grossMinor = $productGross !== null ? $this->moneyToMinorUnits($productGross) : null;
            return [[
                'source_external_variant_id' => null,
                'external_variant_id' => 'sigvaris-'.$productId.'-default',
                'sku' => 'SIGVARIS-'.$productId,
                'source_reference' => $this->stringOrNull($source['reference'] ?? null),
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => $grossMinor,
                'price_net_minor' => $grossMinor !== null && $vat !== null ? $this->netFromGross($grossMinor, $vat) : null,
                'currency' => $currency,
                'vat_rate' => $vat,
                'stock_status' => $this->availability($source['availability'] ?? null),
                'stock_quantity' => is_numeric($source['stock_quantity'] ?? null) ? (int) $source['stock_quantity'] : null,
                'attributes' => [],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ]];
        }

        $variants = [];
        $seen = [];
        foreach ($combinations as $combination) {
            $sourceId = $this->stringOrNull($combination['external_variant_id'] ?? null);
            if ($sourceId === null) {
                $errors[] = 'concrete combination without external_variant_id.';
                continue;
            }
            if (isset($seen[$sourceId])) {
                $errors[] = 'duplicate concrete combination ID '.$sourceId.'.';
                continue;
            }
            $seen[$sourceId] = true;
            $price = $this->numericOrNull($combination['display_price_amount'] ?? null) ?? $productGross;
            if ($price === null || $price <= 0) {
                $errors[] = 'combination '.$sourceId.' has no positive gross price.';
            }
            $grossMinor = $price !== null ? $this->moneyToMinorUnits($price) : null;
            $attributes = [];
            foreach ($this->arrayRecords($combination['attributes'] ?? []) as $attribute) {
                $value = $this->stringOrNull($attribute['value'] ?? null);
                $label = $this->stringOrNull($attribute['label'] ?? null);
                if ($value === null || $label === null) {
                    continue;
                }
                $groupId = $this->stringOrNull($attribute['external_group_id'] ?? null);
                $attributeId = $this->stringOrNull($attribute['external_attribute_id'] ?? null);
                $attributes[] = [
                    'code' => $this->attributeCode($label, $groupId),
                    'label' => $label,
                    'value' => $attributeId !== null ? 'sigvaris-'.$attributeId : Str::slug($value),
                    'value_label' => $value,
                    'source_group_id' => $groupId,
                    'source_attribute_id' => $attributeId,
                ];
            }
            if ($attributes === []) {
                $errors[] = 'combination '.$sourceId.' has no concrete attributes.';
            }
            $variants[] = [
                'source_external_variant_id' => $sourceId,
                'external_variant_id' => 'sigvaris-'.$productId.'-'.$sourceId,
                'sku' => 'SIGVARIS-'.$productId.'-'.$sourceId,
                'source_reference' => $this->stringOrNull($combination['reference'] ?? null),
                'status' => 'draft',
                'is_default' => $defaultCombinationId !== null ? $sourceId === $defaultCombinationId : $variants === [],
                'price_gross_minor' => $grossMinor,
                'price_net_minor' => $grossMinor !== null && $vat !== null ? $this->netFromGross($grossMinor, $vat) : null,
                'currency' => $currency,
                'vat_rate' => $vat,
                'stock_status' => $this->availability($combination['availability'] ?? $source['availability'] ?? null),
                'stock_quantity' => is_numeric($combination['stock_quantity'] ?? null) ? (int) $combination['stock_quantity'] : null,
                'attributes' => $attributes,
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
                'source_product_url' => $this->stringOrNull($combination['product_url'] ?? null),
            ];
        }

        $hasDefault = false;
        foreach ($variants as $variant) {
            if (($variant['is_default'] ?? false) === true) {
                $hasDefault = true;
                break;
            }
        }
        if (! $hasDefault && $variants !== []) {
            $variants[0]['is_default'] = true;
        }
        return $variants;
    }

    /** @param list<array<string,mixed>> $variants @return list<array<string,mixed>> */
    private function attributes(array $variants, ?string $manufacturer): array
    {
        $attributes = [];
        if ($manufacturer !== null) {
            $attributes['producent'] = [
                'code' => 'producent',
                'label' => 'Producent',
                'values' => [[
                    'value' => Str::slug($manufacturer) ?: mb_strtolower($manufacturer),
                    'value_label' => $manufacturer,
                ]],
                'source' => 'source_manufacturer',
            ];
        }

        foreach ($variants as $variant) {
            foreach ($variant['attributes'] ?? [] as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }
                $code = (string) ($attribute['code'] ?? '');
                $value = (string) ($attribute['value'] ?? '');
                if ($code === '' || $value === '') {
                    continue;
                }
                $attributes[$code] ??= [
                    'code' => $code,
                    'label' => $attribute['label'] ?? $code,
                    'values' => [],
                    'source' => 'prestashop_combinations',
                ];
                $attributes[$code]['values'][$value] = [
                    'value' => $value,
                    'value_label' => $attribute['value_label'] ?? $value,
                    'source_attribute_id' => $attribute['source_attribute_id'] ?? null,
                ];
            }
        }

        return array_values(array_map(static function (array $attribute): array {
            if (isset($attribute['values']) && is_array($attribute['values'])) {
                $attribute['values'] = array_values($attribute['values']);
            }
            return $attribute;
        }, $attributes));
    }

    /** @return list<array<string,mixed>> */
    private function categories(mixed $paths): array
    {
        $unique = [];
        foreach (is_array($paths) ? $paths : [] as $path) {
            $normalized = $this->stringList($path);
            if ($normalized !== []) {
                $unique[implode("\0", $normalized)] = $normalized;
            }
        }
        $paths = array_values($unique);
        usort($paths, static fn (array $a, array $b): int => count($b) <=> count($a));
        return array_values(array_map(static fn (array $path, int $index): array => [
            'path' => $path,
            'path_label' => implode(' > ', $path),
            'is_primary' => $index === 0,
        ], $paths, array_keys($paths)));
    }

    /** @return list<array<string,mixed>> */
    private function images(mixed $source): array
    {
        /** @var array<string, array{source_url:string,alt:?string,role:string,priority:int}> $images */
        $images = [];

        foreach ($this->arrayRecords($source) as $image) {
            $url = $this->stringOrNull($image['url'] ?? null);

            if ($url === null) {
                continue;
            }

            $identity = $this->imageIdentity($url);
            $priority = $this->imageRenditionPriority($url);
            $existing = $images[$identity] ?? null;

            if (is_array($existing) && $existing['priority'] >= $priority) {
                continue;
            }

            $images[$identity] = [
                'source_url' => $url,
                'alt' => $this->stringOrNull($image['alt'] ?? null),
                'role' => 'product',
                'priority' => $priority,
            ];
        }

        $mapped = [];

        foreach (array_values($images) as $index => $image) {
            $mapped[] = [
                'source_url' => $image['source_url'],
                'alt' => $image['alt'],
                'role' => $image['role'],
                'is_main' => $index === 0,
            ];
        }

        return $mapped;
    }

    private function imageIdentity(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~/([0-9]+)-[a-z0-9_-]+_default/~i', $path, $matches) === 1) {
            return 'prestashop-image:'.$matches[1];
        }

        return 'url:'.$url;
    }

    private function imageRenditionPriority(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~/[0-9]+-([a-z0-9_-]+)_default/~i', $path, $matches) !== 1) {
            return 0;
        }

        return match (strtolower($matches[1])) {
            'large' => 500,
            'thickbox' => 450,
            'home' => 400,
            'medium' => 300,
            'small' => 200,
            'cart' => 100,
            default => 250,
        };
    }

    /** @return list<array<string,mixed>> */
    private function downloads(mixed $source): array
    {
        $downloads = [];
        foreach ($this->arrayRecords($source) as $download) {
            $url = $this->stringOrNull($download['url'] ?? null);
            if ($url === null || isset($downloads[$url])) {
                continue;
            }
            $downloads[$url] = [
                'source_url' => $url,
                'label' => $this->stringOrNull($download['label'] ?? null),
            ];
        }
        return array_values($downloads);
    }

    /** @return array{source_url:string,label:string}|null */
    private function sizeChart(mixed $source): ?array
    {
        if (! is_array($source)) {
            return null;
        }

        $url = $this->stringOrNull($source['url'] ?? null);

        if ($url === null) {
            return null;
        }

        return [
            'source_url' => $url,
            'label' => $this->stringOrNull($source['label'] ?? null) ?: 'TABELA ROZMIARÓW',
        ];
    }

    private function attributeCode(string $label, ?string $groupId): string
    {
        $slug = Str::slug($label, '_');
        return $slug !== '' ? $slug : 'sigvaris_group_'.($groupId ?? substr(sha1($label), 0, 8));
    }

    private function availability(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        return match ($value) {
            'in_stock', 'instock', 'available' => 'in_stock',
            'out_of_stock', 'outofstock', 'unavailable' => 'out_of_stock',
            'on_order', 'backorder', 'backordered' => 'on_order',
            default => 'unknown',
        };
    }

    private function moneyToMinorUnits(float $amount): int
    {
        return (int) round($amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function netFromGross(int $grossMinor, float $vat): int
    {
        return (int) round($grossMinor / (1 + ($vat / 100)), 0, PHP_ROUND_HALF_UP);
    }

    private function seoDescription(mixed $html): ?string
    {
        $html = $this->stringOrNull($html);
        if ($html === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? strip_tags($html));
        return $text !== '' ? Str::limit($text, 160, '') : null;
    }

    /** @return list<array<string,mixed>> */
    private function arrayRecords(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $item = $this->stringOrNull($item);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
