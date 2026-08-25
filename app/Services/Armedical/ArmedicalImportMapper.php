<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Illuminate\Support\Str;

final class ArmedicalImportMapper
{
    private const SOURCE = 'armedical';

    /** @var list<string> */
    private const TOP_CATEGORIES = [
        'Produkty ortopedyczne',
        'Produkty rehabilitacyjne',
        'Środki pomocnicze',
        'Produkty medyczne',
    ];

    /**
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    public function mapCatalogue(array $catalogue, ?int $limit = null, int $offset = 0): array
    {
        $sourceProducts = $this->arrayRecords($catalogue['products'] ?? []);
        $selectedProducts = array_slice(
            $sourceProducts,
            max(0, $offset),
            $limit === null ? null : max(1, $limit),
        );

        $mappedProducts = [];
        $errors = [];
        $reviewItems = [];
        $blockingReviewItems = [];
        $seenProductIds = [];
        $seenVariantIds = [];
        $seenSkus = [];
        $categoryPaths = [];
        $plannedVariantCount = 0;
        $defaultVariantCount = 0;
        $productsWithOptions = 0;
        $sourceOptionRows = 0;
        $imageCount = 0;
        $documentCount = 0;
        $medicalDeviceCount = 0;
        $productsWithoutPrice = 0;
        $productsWithoutVat = 0;

        foreach ($selectedProducts as $index => $source) {
            $mapped = $this->mapProduct($source);
            $mappedProducts[] = $mapped;
            $label = (string) ($mapped['product']['name'] ?? 'Product '.($index + 1));
            $externalProductId = $mapped['product']['external_id'] ?? null;

            foreach ($mapped['errors'] as $error) {
                $errors[] = $label.': '.$error;
            }

            foreach ($mapped['review_items'] as $reviewItem) {
                $reviewItems[] = $label.': '.$reviewItem;
            }

            foreach ($mapped['blocking_review_items'] as $reviewItem) {
                $blockingReviewItems[] = $label.': '.$reviewItem;
            }

            if (is_string($externalProductId) && $externalProductId !== '') {
                if (isset($seenProductIds[$externalProductId])) {
                    $errors[] = $label.': duplicate external product ID '.$externalProductId.'.';
                }

                $seenProductIds[$externalProductId] = true;
            }

            foreach ($mapped['categories'] as $category) {
                $path = is_array($category['path'] ?? null) ? $category['path'] : [];

                if ($path !== []) {
                    $categoryPaths[implode(' > ', $path)] = true;
                }
            }

            $sourceOptionRows += (int) ($mapped['source_option_count'] ?? 0);
            $productsWithOptions += (int) (($mapped['source_option_count'] ?? 0) > 0);
            $plannedVariantCount += count($mapped['variants']);
            $defaultVariantCount += (int) (($mapped['source_option_count'] ?? 0) === 0);
            $imageCount += count($mapped['images']);
            $documentCount += count($mapped['documents']);
            $medicalDeviceCount += (int) (($mapped['medical_device']['is_medical_device'] ?? false) === true);
            $productsWithoutPrice += (int) (($mapped['pricing']['gross_minor'] ?? null) === null);
            $productsWithoutVat += (int) (($mapped['pricing']['vat_rate'] ?? null) === null);

            foreach ($mapped['variants'] as $variant) {
                $externalVariantId = $variant['external_variant_id'] ?? null;

                if (is_string($externalVariantId) && $externalVariantId !== '') {
                    if (isset($seenVariantIds[$externalVariantId])) {
                        $errors[] = $label.': duplicate planned external variant ID '.$externalVariantId.'.';
                    }

                    $seenVariantIds[$externalVariantId] = true;
                }

                $sku = $variant['sku'] ?? null;

                if (! is_string($sku) || $sku === '') {
                    continue;
                }

                if (isset($seenSkus[$sku])) {
                    $errors[] = $label.': duplicate planned SKU '.$sku.'.';
                }

                $seenSkus[$sku] = true;
            }
        }

        if ($productsWithoutPrice > 0 || $productsWithoutVat > 0) {
            $reviewItems[] = 'ARmedical does not provide selling prices or VAT in the scraped catalogue; price and VAT must be supplied before any database write.';
        }

        foreach ($this->arrayRecords($catalogue['warnings'] ?? []) as $warning) {
            $url = $this->stringOrNull($warning['url'] ?? null);
            $message = $this->stringOrNull($warning['warning'] ?? null);

            if ($message !== null) {
                $reviewItems[] = 'Source warning'.($url !== null ? ' ['.$url.']' : '').': '.$message;
            }
        }

        $errors = array_values(array_unique($errors));
        $reviewItems = array_values(array_unique($reviewItems));
        $blockingReviewItems = array_values(array_unique($blockingReviewItems));
        $structurallyValid = $mappedProducts !== [] && $errors === [];
        $databaseWriteReady = $structurallyValid
            && $blockingReviewItems === []
            && $productsWithoutPrice === 0
            && $productsWithoutVat === 0;

        return [
            'source' => self::SOURCE,
            'mode' => 'import_mapping_dry_run',
            'database_writes' => false,
            'images_downloaded' => false,
            'source_product_count' => count($sourceProducts),
            'selected_product_count' => count($selectedProducts),
            'mapped_product_count' => count($mappedProducts),
            'products' => $mappedProducts,
            'summary' => [
                'unique_external_product_ids' => count($seenProductIds),
                'products_with_table_options' => $productsWithOptions,
                'source_table_option_rows' => $sourceOptionRows,
                'default_only_products' => $defaultVariantCount,
                'planned_variants' => $plannedVariantCount,
                'unique_planned_variant_ids' => count($seenVariantIds),
                'unique_non_null_skus' => count($seenSkus),
                'distinct_category_paths' => count($categoryPaths),
                'category_paths' => array_keys($categoryPaths),
                'images' => $imageCount,
                'documents' => $documentCount,
                'medical_devices' => $medicalDeviceCount,
                'products_without_price' => $productsWithoutPrice,
                'products_without_vat' => $productsWithoutVat,
                'manufacturer' => 'ARMEDICAL Sp. z o.o.',
                'brand' => 'ARmedical',
                'planned_product_status' => 'draft',
                'planned_variant_status' => 'draft',
                'variant_identity' => 'exact source option label + exact source option value',
                'default_variant_strategy' => 'one stable default variant only when no source option matrix exists',
                'price_strategy' => 'not provided by source; do not invent',
                'vat_strategy' => 'not provided by source; do not infer from medical-device flag',
            ],
            'errors' => $errors,
            'review_items' => $reviewItems,
            'blocking_review_items' => $blockingReviewItems,
            'mapping_structurally_valid' => $structurallyValid,
            'ready_for_local_import_implementation' => $structurallyValid && $blockingReviewItems === [],
            'ready_for_database_write' => $databaseWriteReady,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function mapProduct(array $source): array
    {
        $errors = [];
        $reviewItems = [];
        $blockingReviewItems = [];
        $externalProductId = $this->stringOrNull($source['external_product_id'] ?? null);
        $name = $this->stringOrNull($source['name'] ?? null);
        $slug = $this->stringOrNull($source['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($source['canonical_url'] ?? $source['source_url'] ?? null))
            ?: ($name !== null ? Str::slug($name) : null);
        $catalogueNumber = $this->stringOrNull($source['catalogue_number'] ?? null);
        $sourceSku = $this->stringOrNull($source['sku'] ?? null);
        $currency = strtoupper($this->stringOrNull($source['currency'] ?? null) ?? 'PLN');
        $price = $this->numericOrNull($source['price_gross_amount'] ?? null);
        $medicalDevice = $this->nullableBoolean($source['is_medical_device'] ?? null);
        $categories = $this->categories($source);
        $images = $this->images($source['images'] ?? []);
        $documents = $this->documents($source['documents'] ?? []);
        $technicalSpecifications = $this->technicalSpecifications($source['technical_specifications'] ?? []);
        $sourceOptions = $this->arrayRecords($source['size_options'] ?? []);

        if ($externalProductId === null) {
            $errors[] = 'external product ID is missing.';
        }

        if ($name === null) {
            $errors[] = 'product name is missing.';
        }

        if ($slug === null || $slug === '') {
            $errors[] = 'product slug cannot be resolved.';
        }

        if ($catalogueNumber === null) {
            $errors[] = 'catalogue number is missing.';
        }

        if ($currency !== 'PLN') {
            $errors[] = 'unsupported currency '.$currency.'.';
        }

        if ($categories === []) {
            $errors[] = 'no ARmedical category path is mapped.';
        }

        if ($images === []) {
            $errors[] = 'no product images are mapped.';
        }

        if ($price !== null && $price <= 0) {
            $errors[] = 'source gross price, when present, must be positive.';
        }

        if ($medicalDevice !== true) {
            $reviewItems[] = 'source does not positively identify this product as a medical device.';
        }

        $variants = $this->variants(
            $sourceOptions,
            $externalProductId,
            $sourceSku,
            $catalogueNumber,
            $currency,
            $errors,
            $blockingReviewItems,
        );

        $grossMinor = $price !== null ? $this->moneyToMinorUnits($price) : null;

        return [
            'source' => self::SOURCE,
            'source_url' => $this->stringOrNull($source['source_url'] ?? null),
            'canonical_url' => $this->stringOrNull($source['canonical_url'] ?? null),
            'product' => [
                'external_source' => self::SOURCE,
                'external_id' => $externalProductId,
                'external_parent_sku' => $catalogueNumber,
                'catalogue_number' => $catalogueNumber,
                'source_sku' => $sourceSku,
                'name' => $name,
                'slug' => $slug,
                'status' => 'draft',
                'short_description_html' => $this->paragraphHtml($this->stringOrNull($source['short_description'] ?? null)),
                'description_html' => $this->stringOrNull($source['description_html'] ?? null),
                'seo_title' => $this->stringOrNull($source['seo_title'] ?? null) ?: $name,
                'seo_description' => $this->stringOrNull($source['seo_description'] ?? null),
                'brand' => $this->stringOrNull($source['brand'] ?? null) ?: 'ARmedical',
                'manufacturer' => $this->stringOrNull($source['manufacturer'] ?? null) ?: 'ARMEDICAL Sp. z o.o.',
            ],
            'medical_device' => [
                'is_medical_device' => $medicalDevice,
                'source_statement' => $this->stringOrNull($source['short_description'] ?? null),
            ],
            'pricing' => [
                'source_gross_amount' => $price,
                'gross_minor' => $grossMinor,
                'net_minor' => null,
                'vat_rate' => null,
                'currency' => $currency,
                'requires_review' => true,
                'source' => 'not_provided_by_armedical_catalogue',
            ],
            'availability' => [
                'status' => $this->availability($source['availability'] ?? null),
                'label' => $this->stringOrNull($source['availability_label'] ?? null),
                'stock_quantity' => is_numeric($source['stock_quantity'] ?? null) ? (int) $source['stock_quantity'] : null,
            ],
            'categories' => $categories,
            'technical_specifications' => $technicalSpecifications,
            'attributes' => $this->sourceAttributes($source['attributes'] ?? []),
            'source_option_count' => count($sourceOptions),
            'variants' => $variants,
            'images' => $images,
            'documents' => $documents,
            'errors' => array_values(array_unique($errors)),
            'review_items' => array_values(array_unique($reviewItems)),
            'blocking_review_items' => array_values(array_unique($blockingReviewItems)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceOptions
     * @param  list<string>  $errors
     * @param  list<string>  $blockingReviewItems
     * @return list<array<string, mixed>>
     */
    private function variants(
        array $sourceOptions,
        ?string $externalProductId,
        ?string $sourceSku,
        ?string $catalogueNumber,
        string $currency,
        array &$errors,
        array &$blockingReviewItems,
    ): array {
        if ($externalProductId === null) {
            return [];
        }

        if ($sourceOptions === []) {
            return [[
                'source_external_variant_id' => null,
                'external_variant_id' => $externalProductId.'-default',
                'sku' => $this->defaultSku($sourceSku, $catalogueNumber, $externalProductId),
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => null,
                'price_net_minor' => null,
                'currency' => $currency,
                'vat_rate' => null,
                'stock_status' => 'unknown',
                'stock_quantity' => null,
                'attributes' => [],
                'source_option_label' => null,
                'source_option_value' => null,
            ]];
        }

        $labelValues = [];
        foreach ($sourceOptions as $option) {
            $label = $this->stringOrNull($option['label'] ?? null);
            $value = $this->stringOrNull($option['value'] ?? null);

            if ($label === null || $value === null) {
                continue;
            }

            $labelValues[$label][$value] = true;
        }

        $ambiguousLabels = [];
        foreach ($labelValues as $label => $values) {
            if (count($values) > 1) {
                $ambiguousLabels[$label] = array_keys($values);
                $blockingReviewItems[] = 'source option label '.$label.' is reused for multiple values ('.implode(' / ', array_keys($values)).'); do not invent or rewrite a source variant identity.';
            }
        }

        $variants = [];
        $seenTuples = [];

        foreach ($sourceOptions as $index => $option) {
            $label = $this->stringOrNull($option['label'] ?? null);
            $value = $this->stringOrNull($option['value'] ?? null);

            if ($label === null || $value === null) {
                $errors[] = 'source option row '.($index + 1).' is missing label or value.';
                continue;
            }

            $tuple = $label."\0".$value;

            if (isset($seenTuples[$tuple])) {
                $errors[] = 'duplicate exact source option tuple '.$label.' = '.$value.'.';
                continue;
            }

            $seenTuples[$tuple] = true;
            $fingerprint = substr(hash('sha256', $tuple), 0, 16);
            $variantId = $externalProductId.'-'.$fingerprint;
            $sku = isset($ambiguousLabels[$label])
                ? null
                : $this->optionSku($sourceSku, $catalogueNumber, $externalProductId, $label);

            $variants[] = [
                'source_external_variant_id' => $this->sourceVariantReference($label),
                'external_variant_id' => $variantId,
                'sku' => $sku,
                'status' => 'draft',
                'is_default' => $index === 0,
                'price_gross_minor' => null,
                'price_net_minor' => null,
                'currency' => $currency,
                'vat_rate' => null,
                'stock_status' => 'unknown',
                'stock_quantity' => null,
                'attributes' => [[
                    'code' => 'wariant',
                    'label' => 'Wariant',
                    'value' => $this->attributeValue($label, $value),
                    'value_label' => $label,
                ]],
                'source_option_label' => $label,
                'source_option_value' => $value,
                'source_option_identity_fingerprint' => $fingerprint,
            ];
        }

        return $variants;
    }

    /** @param mixed $categories @return list<array<string, mixed>> */
    private function categories(array $source): array
    {
        $names = [];

        foreach ($source['categories'] ?? [] as $category) {
            $name = $this->stringOrNull($category);

            if ($name !== null && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            $fallback = $this->stringOrNull($source['category'] ?? null);

            if ($fallback !== null) {
                $names[] = $fallback;
            }
        }

        if ($names === []) {
            return [];
        }

        $top = null;
        foreach (self::TOP_CATEGORIES as $candidate) {
            if (in_array($candidate, $names, true)) {
                $top = $candidate;
                break;
            }
        }

        $path = $top === null
            ? $names
            : array_merge([$top], array_values(array_filter($names, static fn (string $name): bool => $name !== $top)));

        return [[
            'path' => $path,
            'source_categories' => $names,
        ]];
    }

    /** @param mixed $value @return list<array<string, mixed>> */
    private function images(mixed $value): array
    {
        $images = [];
        $seen = [];

        foreach ($this->arrayRecords($value) as $image) {
            $url = $this->stringOrNull($image['url'] ?? null);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $images[] = [
                'source_url' => $url,
                'alt' => $this->stringOrNull($image['alt'] ?? null),
                'is_primary' => ($image['is_primary'] ?? false) === true,
                'download' => false,
            ];
        }

        return $images;
    }

    /** @param mixed $value @return list<array<string, mixed>> */
    private function documents(mixed $value): array
    {
        $documents = [];
        $seen = [];

        foreach ($this->arrayRecords($value) as $document) {
            $url = $this->stringOrNull($document['url'] ?? null);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $documents[] = [
                'source_url' => $url,
                'label' => $this->stringOrNull($document['label'] ?? null),
                'type' => $this->stringOrNull($document['type'] ?? null) ?: 'document',
                'download' => false,
            ];
        }

        return $documents;
    }

    /** @param mixed $value @return list<array{label:string,value:string}> */
    private function technicalSpecifications(mixed $value): array
    {
        $specifications = [];

        foreach ($this->arrayRecords($value) as $specification) {
            $label = $this->stringOrNull($specification['label'] ?? null);
            $specValue = $this->stringOrNull($specification['value'] ?? null);

            if ($label === null || $specValue === null) {
                continue;
            }

            $specifications[] = [
                'label' => $label,
                'value' => $specValue,
            ];
        }

        return $specifications;
    }

    /** @param mixed $value @return array<string, mixed> */
    private function sourceAttributes(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function defaultSku(?string $sourceSku, ?string $catalogueNumber, string $externalProductId): string
    {
        $base = $sourceSku ?? $catalogueNumber;

        if ($base !== null) {
            return 'ARMEDICAL-'.$this->skuToken($base);
        }

        return 'ARMEDICAL-'.strtoupper(substr(hash('sha256', $externalProductId), 0, 16));
    }

    private function optionSku(?string $sourceSku, ?string $catalogueNumber, string $externalProductId, string $label): string
    {
        if ($this->looksLikeSourceCode($label)) {
            return 'ARMEDICAL-'.$this->skuToken($label);
        }

        $base = $sourceSku ?? $catalogueNumber;

        if ($base === null) {
            $base = strtoupper(substr(hash('sha256', $externalProductId), 0, 12));
        }

        return 'ARMEDICAL-'.$this->skuToken($base).'-'.$this->skuToken($label);
    }

    private function sourceVariantReference(string $label): ?string
    {
        return $this->looksLikeSourceCode($label) ? $label : null;
    }

    private function looksLikeSourceCode(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]*$/iu', $value) !== 1) {
            return false;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return strlen($value) >= 4;
        }

        return preg_match('/[A-Z]/iu', $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    private function skuToken(string $value): string
    {
        $token = strtoupper(Str::ascii(trim($value)));
        $token = preg_replace('/[^A-Z0-9]+/', '-', $token) ?? '';
        $token = trim($token, '-');

        return $token !== '' ? $token : strtoupper(substr(hash('sha256', $value), 0, 12));
    }

    private function attributeValue(string $label, string $value): string
    {
        $slug = Str::slug($label);

        if ($slug !== '') {
            return $slug;
        }

        return 'armedical-option-'.substr(hash('sha256', $label."\0".$value), 0, 12);
    }

    private function availability(mixed $value): string
    {
        $availability = strtolower(trim((string) $value));

        return in_array($availability, ['in_stock', 'out_of_stock', 'backorder', 'unknown'], true)
            ? $availability
            : 'unknown';
    }

    private function paragraphHtml(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return '<p>'.e($value).'</p>';
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        $slug = end($segments);

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private function moneyToMinorUnits(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<array<string, mixed>> */
    private function arrayRecords(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
