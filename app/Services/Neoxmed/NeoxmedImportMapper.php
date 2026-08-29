<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use Illuminate\Support\Str;

final class NeoxmedImportMapper
{
    private const SOURCE = 'neoxmed';

    private const BRAND = 'Neox';

    private const MANUFACTURER = 'Neox s.c.';

    /**
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    public function mapCatalogue(array $catalogue, ?int $limit = null, int $offset = 0): array
    {
        $sourceProducts = $this->records($catalogue['products'] ?? []);
        $selectedProducts = array_slice($sourceProducts, max(0, $offset), $limit === null ? null : max(1, $limit));
        $mappedProducts = [];
        $errors = [];
        $reviewItems = [];
        $blockingReviewItems = [];
        $seenProductIds = [];
        $seenVariantIds = [];
        $seenSkus = [];
        $categoryPaths = [];
        $productImageCount = 0;
        $sizeChartImageCount = 0;
        $productsWithNfz = 0;
        $nfzCodes = [];
        $productsWithSizeInformation = 0;
        $productsWithoutPrice = 0;
        $productsWithoutVat = 0;
        $productsWithoutAvailability = 0;

        foreach ($selectedProducts as $index => $source) {
            $mapped = $this->mapProduct($source);
            $mappedProducts[] = $mapped;
            $name = (string) ($mapped['product']['name'] ?? 'Product '.($index + 1));
            $externalId = $mapped['product']['external_id'] ?? null;
            $label = is_string($externalId) && $externalId !== '' ? $externalId.' | '.$name : $name;

            foreach ($mapped['errors'] as $error) {
                $errors[] = $label.': '.$error;
            }

            foreach ($mapped['review_items'] as $item) {
                $reviewItems[] = $label.': '.$item;
            }

            foreach ($mapped['blocking_review_items'] as $item) {
                $blockingReviewItems[] = $label.': '.$item;
            }

            if (is_string($externalId) && $externalId !== '') {
                if (isset($seenProductIds[$externalId])) {
                    $errors[] = $label.': duplicate external product ID '.$externalId.'.';
                }

                $seenProductIds[$externalId] = true;
            }

            foreach ($mapped['categories'] as $category) {
                $path = is_array($category['path'] ?? null) ? $category['path'] : [];
                if ($path !== []) {
                    $categoryPaths[implode(' > ', $path)] = true;
                }
            }

            foreach ($mapped['variants'] as $variant) {
                $externalVariantId = $variant['external_variant_id'] ?? null;
                if (is_string($externalVariantId) && $externalVariantId !== '') {
                    if (isset($seenVariantIds[$externalVariantId])) {
                        $errors[] = $label.': duplicate planned external variant ID '.$externalVariantId.'.';
                    }

                    $seenVariantIds[$externalVariantId] = true;
                }

                $sku = $variant['sku'] ?? null;
                if (is_string($sku) && $sku !== '') {
                    if (isset($seenSkus[$sku])) {
                        $errors[] = $label.': duplicate planned SKU '.$sku.'.';
                    }

                    $seenSkus[$sku] = true;
                }
            }

            $productImageCount += count($mapped['images']);
            $sizeChartImageCount += count($mapped['sizing']['size_chart_images']);

            if ($mapped['nfz']['codes'] !== []) {
                $productsWithNfz++;
                foreach ($mapped['nfz']['codes'] as $code) {
                    $nfzCodes[$code] = true;
                }
            }

            if (($mapped['sizing']['has_source_size_information'] ?? false) === true) {
                $productsWithSizeInformation++;
            }

            if (($mapped['pricing']['gross_minor'] ?? null) === null) {
                $productsWithoutPrice++;
            }

            if (($mapped['pricing']['vat_rate'] ?? null) === null) {
                $productsWithoutVat++;
            }

            if (($mapped['availability']['source_status'] ?? null) === null) {
                $productsWithoutAvailability++;
            }
        }

        if ($productsWithoutPrice > 0) {
            $blockingReviewItems[] = 'NeoxMed catalogue pages do not provide approved selling prices; all prices must be supplied before any database write.';
        }

        if ($productsWithoutVat > 0) {
            $blockingReviewItems[] = 'VAT is not published by the scraped catalogue and must not be inferred from medical-device status; approve VAT before any database write.';
        }

        if ($productsWithSizeInformation > 0) {
            $reviewItems[] = 'Source size information is preserved as text/images only; no size variants are generated from visual size charts.';
        }

        if ($productsWithoutAvailability > 0) {
            $reviewItems[] = 'Source availability is not published; any future imported variants must remain non-purchasable until stock policy is explicitly approved.';
        }

        $errors = array_values(array_unique($errors));
        $reviewItems = array_values(array_unique($reviewItems));
        $blockingReviewItems = array_values(array_unique($blockingReviewItems));
        $structurallyValid = $mappedProducts !== [] && $errors === [];

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
                'planned_variants' => count($seenVariantIds),
                'unique_planned_variant_ids' => count($seenVariantIds),
                'unique_planned_skus' => count($seenSkus),
                'distinct_category_paths' => count($categoryPaths),
                'category_paths' => array_keys($categoryPaths),
                'product_images' => $productImageCount,
                'size_chart_images' => $sizeChartImageCount,
                'products_with_nfz_codes' => $productsWithNfz,
                'unique_nfz_codes' => count($nfzCodes),
                'products_with_size_information' => $productsWithSizeInformation,
                'products_without_price' => $productsWithoutPrice,
                'products_without_vat' => $productsWithoutVat,
                'products_without_source_availability' => $productsWithoutAvailability,
                'brand' => self::BRAND,
                'manufacturer' => self::MANUFACTURER,
                'planned_product_status' => 'draft',
                'planned_variant_status' => 'draft',
                'planned_stock_status' => 'out_of_stock',
                'variant_strategy' => 'one safe placeholder variant per distinct source product; source size data is not converted into variants',
                'price_strategy' => 'not provided by source; do not invent',
                'vat_strategy' => 'not provided by source; do not infer from medical-device status',
            ],
            'errors' => $errors,
            'review_items' => $reviewItems,
            'blocking_review_items' => $blockingReviewItems,
            'mapping_structurally_valid' => $structurallyValid,
            'ready_for_local_import_implementation' => $structurallyValid,
            'ready_for_database_write' => $structurallyValid && $blockingReviewItems === [] && $productsWithoutPrice === 0 && $productsWithoutVat === 0,
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
        $externalId = $this->stringOrNull($source['external_product_id'] ?? null);
        $sourceCode = $this->stringOrNull($source['source_code'] ?? null);
        $sourceQualifier = $this->stringOrNull($source['source_qualifier'] ?? null);
        $sourceSku = $this->stringOrNull($source['sku'] ?? null) ?: $externalId;
        $name = $this->stringOrNull($source['name'] ?? null);
        $sourceSlug = $this->stringOrNull($source['slug'] ?? null);
        $slug = $sourceSlug !== null ? 'neox-'.$sourceSlug : ($name !== null && $externalId !== null ? Str::slug('neox '.$externalId.' '.$name) : null);
        $categories = $this->categories($source);
        $images = $this->images($source['images'] ?? []);
        $sizeChartImages = $this->images($source['size_chart_images'] ?? [], 'size_chart');
        $nfzCodes = $this->stringList($source['nfz_codes'] ?? []);
        $sizeNote = $this->stringOrNull($source['size_note'] ?? null);
        $descriptionText = $this->stringOrNull($source['description_text'] ?? null);
        $descriptionHtml = $this->stringOrNull($source['description_html'] ?? null);
        $medicalDevice = ($source['is_medical_device'] ?? null) === true;

        if ($externalId === null) {
            $errors[] = 'external product ID is missing.';
        }

        if ($sourceCode === null) {
            $errors[] = 'source code is missing.';
        }

        if ($sourceSku === null) {
            $errors[] = 'source SKU is missing.';
        }

        if ($name === null) {
            $errors[] = 'product name is missing.';
        }

        if ($slug === null || $slug === '') {
            $errors[] = 'product slug cannot be resolved.';
        }

        if ($descriptionText === null && $descriptionHtml === null) {
            $errors[] = 'product description is missing.';
        }

        if ($categories === []) {
            $errors[] = 'no NeoxMed category path is mapped.';
        }

        if ($images === []) {
            $errors[] = 'no product image is mapped.';
        }

        if (! $medicalDevice) {
            $reviewItems[] = 'scraped record does not positively identify this product as a medical device.';
        }

        $plannedSku = $sourceSku !== null ? 'NEOX-'.Str::upper($sourceSku) : null;
        $externalVariantId = $externalId !== null ? 'neoxmed-'.$externalId.'-default' : null;

        return [
            'source' => self::SOURCE,
            'source_url' => $this->stringOrNull($source['source_url'] ?? null),
            'source_locator' => $this->stringOrNull($source['source_locator'] ?? null),
            'product' => [
                'external_source' => self::SOURCE,
                'external_id' => $externalId,
                'external_parent_sku' => $plannedSku,
                'source_code' => $sourceCode,
                'source_qualifier' => $sourceQualifier,
                'source_sku' => $sourceSku,
                'name' => $name,
                'slug' => $slug,
                'status' => 'draft',
                'short_description_html' => $this->paragraphHtml($descriptionText),
                'description_html' => $descriptionHtml,
                'seo_title' => $name,
                'seo_description' => $this->seoDescription($descriptionText),
                'brand' => self::BRAND,
                'manufacturer' => self::MANUFACTURER,
            ],
            'medical_device' => [
                'is_medical_device' => $medicalDevice,
                'source' => 'neoxmed_catalogue',
            ],
            'nfz' => [
                'codes' => $nfzCodes,
                'requires_review' => false,
            ],
            'pricing' => [
                'source_gross_amount' => null,
                'gross_minor' => null,
                'net_minor' => null,
                'vat_rate' => null,
                'currency' => 'PLN',
                'requires_review' => true,
                'source' => 'not_provided_by_neoxmed_catalogue',
            ],
            'availability' => [
                'source_status' => $this->stringOrNull($source['availability'] ?? null),
                'planned_stock_status' => 'out_of_stock',
                'requires_review' => true,
            ],
            'categories' => $categories,
            'sizing' => [
                'size_note' => $sizeNote,
                'size_chart_images' => $sizeChartImages,
                'has_source_size_information' => $sizeNote !== null || $sizeChartImages !== [],
                'variant_generation_allowed' => false,
                'strategy' => 'preserve source sizing information; do not infer variants from text or images',
            ],
            'variants' => [[
                'external_variant_id' => $externalVariantId,
                'sku' => $plannedSku,
                'source_sku' => $sourceSku,
                'status' => 'draft',
                'is_default' => true,
                'price_net_minor' => null,
                'price_gross_minor' => null,
                'currency' => 'PLN',
                'vat_rate' => null,
                'stock_status' => 'out_of_stock',
                'attributes' => [],
            ]],
            'images' => $images,
            'errors' => array_values(array_unique($errors)),
            'review_items' => array_values(array_unique($reviewItems)),
            'blocking_review_items' => array_values(array_unique($blockingReviewItems)),
        ];
    }

    /** @param array<string, mixed> $source */
    private function categories(array $source): array
    {
        $paths = $this->records($source['source_category_paths'] ?? []);
        $normalized = [];

        foreach ($paths as $path) {
            $values = $this->stringList($path);
            if ($values !== []) {
                $normalized[implode(' > ', $values)] = [
                    'path' => $values,
                    'leaf_name' => end($values) ?: null,
                    'leaf_slug' => Str::slug((string) (end($values) ?: '')),
                    'source_preserved' => true,
                ];
            }
        }

        if ($normalized === []) {
            foreach ($this->stringList($source['categories'] ?? []) as $name) {
                $normalized[$name] = [
                    'path' => [$name],
                    'leaf_name' => $name,
                    'leaf_slug' => Str::slug($name),
                    'source_preserved' => true,
                ];
            }
        }

        return array_values($normalized);
    }

    /**
     * @return list<array{source_url:string,alt:?string,role:string}>
     */
    private function images(mixed $value, string $role = 'product'): array
    {
        $images = [];

        foreach ($this->records($value) as $image) {
            $url = $this->normalizeNeoxmedImageUrl($image['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $images[$url] = [
                'source_url' => $url,
                'alt' => $this->stringOrNull($image['alt'] ?? null),
                'role' => $role,
            ];
        }

        return array_values($images);
    }

    private function normalizeNeoxmedImageUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || ! in_array($host, ['neoxmed.com', 'www.neoxmed.com'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://neoxmed.com'.$path.$query;
    }

    private function seoDescription(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? strip_tags($text));

        return $normalized === '' ? null : Str::limit($normalized, 155, '');
    }

    private function paragraphHtml(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $paragraphs = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R+/u', $text) ?: [],
        ), static fn (string $line): bool => $line !== ''));

        if ($paragraphs === []) {
            return null;
        }

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>'.e($paragraph).'</p>',
            $paragraphs,
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values(array_unique($strings));
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
