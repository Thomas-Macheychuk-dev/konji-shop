<?php

declare(strict_types=1);

namespace App\Services\Armedical;

final class ArmedicalPricingPreflight
{
    /** @var array<string, string> */
    private const CODE_ALIASES = [
        'PDG-06' => 'PDG-6',
    ];

    private const LEGACY_MISSING_PRICE_REVIEW = 'ARmedical does not provide selling prices or VAT in the scraped catalogue; price and VAT must be supplied before any database write.';

    /** @var array<string, list<string>> */
    private const RELATED_CODE_HINTS = [
        'AR-600' => ['AR-600L', 'AR-600P'],
    ];

    /**
     * @param  array<string, mixed>  $importMap
     * @param  array{metadata:array<string,mixed>,rows:list<array<string,mixed>>,index:array<string,array<string,mixed>>,summary:array<string,mixed>}  $priceList
     * @return array<string, mixed>
     */
    public function apply(array $importMap, array $priceList): array
    {
        $result = $importMap;
        $products = $this->records($importMap['products'] ?? []);
        $priceIndex = is_array($priceList['index'] ?? null) ? $priceList['index'] : [];
        $errors = $this->strings($importMap['errors'] ?? []);
        $reviewItems = array_values(array_filter(
            $this->strings($importMap['review_items'] ?? []),
            static fn (string $item): bool => $item !== self::LEGACY_MISSING_PRICE_REVIEW,
        ));
        $blockingReviewItems = $this->strings($importMap['blocking_review_items'] ?? []);

        if (($importMap['source'] ?? null) !== 'armedical') {
            $errors[] = 'Pricing preflight input must have source="armedical".';
        }

        if (($importMap['mode'] ?? null) !== 'import_mapping_dry_run') {
            $errors[] = 'Pricing preflight input must be an ARmedical import_mapping_dry_run map.';
        }

        if (($importMap['database_writes'] ?? null) !== false) {
            $errors[] = 'Pricing preflight input must explicitly state database_writes=false.';
        }

        $matchedVariants = 0;
        $unmatchedVariants = 0;
        $fullyPricedProducts = 0;
        $unpricedProducts = 0;
        $vatVariantBreakdown = [];
        $matchStrategyBreakdown = [];
        $aliasMatches = [];
        $unmatchedProducts = [];
        $resolvedProducts = [];

        foreach ($products as $productMap) {
            $product = is_array($productMap['product'] ?? null) ? $productMap['product'] : [];
            $name = $this->stringOrNull($product['name'] ?? null) ?? 'Unnamed ARmedical product';
            $catalogueNumber = $this->normalizeCode($product['catalogue_number'] ?? null);
            $variants = $this->records($productMap['variants'] ?? []);
            $resolvedVariants = [];
            $productUnmatched = [];
            $productPrices = [];

            foreach ($variants as $variant) {
                $resolution = $this->resolvePrice($variant, $catalogueNumber, $priceIndex);

                if ($resolution === null) {
                    $unmatchedVariants++;
                    $resolvedVariants[] = $this->unpricedVariant($variant);
                    $productUnmatched[] = [
                        'external_variant_id' => $this->stringOrNull($variant['external_variant_id'] ?? null),
                        'source_option_label' => $this->stringOrNull($variant['source_option_label'] ?? null),
                        'source_option_value' => $this->stringOrNull($variant['source_option_value'] ?? null),
                        'source_external_variant_id' => $this->normalizeCode($variant['source_external_variant_id'] ?? null),
                    ];
                    continue;
                }

                $matchedVariants++;
                $matchStrategy = (string) $resolution['match_strategy'];
                $matchStrategyBreakdown[$matchStrategy] = ($matchStrategyBreakdown[$matchStrategy] ?? 0) + 1;
                $vatRate = (int) $resolution['vat_rate'];
                $vatVariantBreakdown[$vatRate] = ($vatVariantBreakdown[$vatRate] ?? 0) + 1;

                if ($matchStrategy === 'variant_alias' || $matchStrategy === 'parent_alias') {
                    $aliasMatches[] = [
                        'from' => $resolution['matched_from_code'],
                        'to' => $resolution['price_code'],
                        'product' => $name,
                        'external_variant_id' => $this->stringOrNull($variant['external_variant_id'] ?? null),
                    ];
                }

                $resolvedVariants[] = $this->pricedVariant($variant, $resolution, $priceList['metadata']);
                $productPrices[$resolution['net_minor'].'|'.$resolution['gross_minor'].'|'.$resolution['vat_rate']] = [
                    'net_minor' => (int) $resolution['net_minor'],
                    'gross_minor' => (int) $resolution['gross_minor'],
                    'vat_rate' => (int) $resolution['vat_rate'],
                ];
            }

            $allVariantsPriced = $variants !== [] && $productUnmatched === [];

            if ($allVariantsPriced) {
                $fullyPricedProducts++;
            } else {
                $unpricedProducts++;
            }

            if ($productUnmatched !== []) {
                $candidates = $this->candidatePriceCodes($catalogueNumber, $priceIndex);
                $unmatchedProducts[] = [
                    'catalogue_number' => $catalogueNumber,
                    'name' => $name,
                    'unmatched_variant_count' => count($productUnmatched),
                    'unmatched_variants' => $productUnmatched,
                    'candidate_price_codes' => $candidates,
                ];

                if ($candidates !== []) {
                    $reviewItems[] = $name.': supplier price list has related code(s) '.implode(', ', $candidates)
                        .' but the current source map does not provide a deterministic variant identity for them; review before import.';
                }
            }

            $productMap['variants'] = $resolvedVariants;
            $productMap['pricing'] = $this->productPricing(
                is_array($productMap['pricing'] ?? null) ? $productMap['pricing'] : [],
                $allVariantsPriced,
                array_values($productPrices),
                $priceList['metadata'],
            );
            $resolvedProducts[] = $productMap;
        }

        ksort($vatVariantBreakdown);
        ksort($matchStrategyBreakdown);

        if ($unmatchedVariants > 0) {
            $reviewItems[] = sprintf(
                'ARmedical supplier price list %s resolves price/VAT for %d of %d planned variants; %d variants across %d products remain unresolved.',
                ArmedicalSupplierPriceList::EFFECTIVE_FROM,
                $matchedVariants,
                $matchedVariants + $unmatchedVariants,
                $unmatchedVariants,
                $unpricedProducts,
            );
        }

        $reviewItems[] = 'Pricing uses supplier column "Cena netto"; promotional column "Pakiet 5+1 cena*" is intentionally ignored.';

        if ($aliasMatches !== []) {
            $reviewItems[] = count($aliasMatches).' explicit supplier-code alias match(es) applied; each alias is retained in pricing provenance.';
        }

        $errors = array_values(array_unique($errors));
        $reviewItems = array_values(array_unique($reviewItems));
        $blockingReviewItems = array_values(array_unique($blockingReviewItems));
        $pricingStructurallyValid = $products !== [] && $errors === [];
        $readyForDatabaseWrite = $pricingStructurallyValid
            && $blockingReviewItems === []
            && $unmatchedVariants === 0;

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $summary['products_without_price'] = $unpricedProducts;
        $summary['products_without_vat'] = $unpricedProducts;
        $summary['price_strategy'] = 'supplier Cena netto matched by exact variant code, explicit alias, then exact parent catalogue code';
        $summary['vat_strategy'] = 'supplier VAT % per matched price-list code; no blanket medical-device VAT assumption';
        $summary['supplier_price_list_effective_from'] = ArmedicalSupplierPriceList::EFFECTIVE_FROM;

        $result['mode'] = 'pricing_mapping_dry_run';
        $result['database_writes'] = false;
        $result['images_downloaded'] = false;
        $result['products'] = $resolvedProducts;
        $result['summary'] = $summary;
        $result['supplier_price_list'] = [
            'metadata' => $priceList['metadata'],
            'summary' => $priceList['summary'],
        ];
        $result['pricing_summary'] = [
            'planned_variants' => $matchedVariants + $unmatchedVariants,
            'matched_variants' => $matchedVariants,
            'unmatched_variants' => $unmatchedVariants,
            'fully_priced_products' => $fullyPricedProducts,
            'unpriced_products' => $unpricedProducts,
            'vat_variant_breakdown' => $vatVariantBreakdown,
            'match_strategy_breakdown' => $matchStrategyBreakdown,
            'explicit_alias_matches' => $aliasMatches,
        ];
        $result['unmatched_products'] = $unmatchedProducts;
        $result['errors'] = $errors;
        $result['review_items'] = $reviewItems;
        $result['blocking_review_items'] = $blockingReviewItems;
        $result['pricing_structurally_valid'] = $pricingStructurallyValid;
        $result['ready_for_local_import_implementation'] = $readyForDatabaseWrite;
        $result['ready_for_database_write'] = $readyForDatabaseWrite;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variant
     * @param  array<string, array<string, mixed>>  $priceIndex
     * @return array<string, mixed>|null
     */
    private function resolvePrice(array $variant, ?string $catalogueNumber, array $priceIndex): ?array
    {
        $sourceVariantCode = $this->normalizeCode($variant['source_external_variant_id'] ?? null);
        $candidates = [];

        if ($sourceVariantCode !== null) {
            $candidates[] = ['strategy' => 'variant_code', 'from' => $sourceVariantCode, 'code' => $sourceVariantCode];

            if (isset(self::CODE_ALIASES[$sourceVariantCode])) {
                $candidates[] = [
                    'strategy' => 'variant_alias',
                    'from' => $sourceVariantCode,
                    'code' => self::CODE_ALIASES[$sourceVariantCode],
                ];
            }
        }

        if ($catalogueNumber !== null) {
            $candidates[] = ['strategy' => 'parent_code', 'from' => $catalogueNumber, 'code' => $catalogueNumber];

            if (isset(self::CODE_ALIASES[$catalogueNumber])) {
                $candidates[] = [
                    'strategy' => 'parent_alias',
                    'from' => $catalogueNumber,
                    'code' => self::CODE_ALIASES[$catalogueNumber],
                ];
            }
        }

        $seen = [];

        foreach ($candidates as $candidate) {
            $code = $this->normalizeCode($candidate['code'] ?? null);

            if ($code === null || isset($seen[$candidate['strategy'].'|'.$code])) {
                continue;
            }

            $seen[$candidate['strategy'].'|'.$code] = true;
            $price = $priceIndex[$code] ?? null;

            if (! is_array($price)) {
                continue;
            }

            $netMinor = (int) ($price['net_minor'] ?? 0);
            $vatRate = (int) ($price['vat_rate'] ?? 0);

            if ($netMinor <= 0 || ! in_array($vatRate, [8, 23], true)) {
                continue;
            }

            return [
                'price_code' => $code,
                'matched_from_code' => $candidate['from'],
                'match_strategy' => $candidate['strategy'],
                'net_minor' => $netMinor,
                'gross_minor' => $this->grossFromNetMinor($netMinor, $vatRate),
                'vat_rate' => $vatRate,
                'currency' => 'PLN',
                'source_rows' => is_array($price['source_rows'] ?? null) ? $price['source_rows'] : [],
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $variant @param array<string, mixed> $resolution @param array<string, mixed> $metadata */
    private function pricedVariant(array $variant, array $resolution, array $metadata): array
    {
        $variant['price_net_minor'] = (int) $resolution['net_minor'];
        $variant['price_gross_minor'] = (int) $resolution['gross_minor'];
        $variant['vat_rate'] = (int) $resolution['vat_rate'];
        $variant['currency'] = 'PLN';
        $variant['pricing_resolution'] = [
            'status' => 'matched',
            'price_code' => $resolution['price_code'],
            'matched_from_code' => $resolution['matched_from_code'],
            'match_strategy' => $resolution['match_strategy'],
            'supplier_source_rows' => $resolution['source_rows'],
            'supplier_source_file' => $metadata['source_file'] ?? null,
            'supplier_source_sha256' => $metadata['source_sha256'] ?? null,
            'supplier_effective_from' => $metadata['effective_from'] ?? null,
            'price_column' => $metadata['price_column'] ?? null,
            'vat_column' => $metadata['vat_column'] ?? null,
        ];

        return $variant;
    }

    /** @param array<string, mixed> $variant */
    private function unpricedVariant(array $variant): array
    {
        $variant['price_net_minor'] = null;
        $variant['price_gross_minor'] = null;
        $variant['vat_rate'] = null;
        $variant['pricing_resolution'] = [
            'status' => 'unmatched',
            'reason' => 'No deterministic supplier price-list code match.',
        ];

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  list<array{net_minor:int,gross_minor:int,vat_rate:int}>  $prices
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function productPricing(array $existing, bool $complete, array $prices, array $metadata): array
    {
        $existing['source_gross_amount'] = null;
        $existing['currency'] = 'PLN';
        $existing['variant_pricing_complete'] = $complete;
        $existing['mixed_variant_pricing'] = $complete && count($prices) > 1;
        $existing['source'] = 'armedical_supplier_price_list_2026_03_04';
        $existing['supplier_source_file'] = $metadata['source_file'] ?? null;
        $existing['supplier_source_sha256'] = $metadata['source_sha256'] ?? null;
        $existing['supplier_effective_from'] = $metadata['effective_from'] ?? null;
        $existing['requires_review'] = ! $complete;

        if ($complete && count($prices) === 1) {
            $price = $prices[0];
            $existing['net_minor'] = $price['net_minor'];
            $existing['gross_minor'] = $price['gross_minor'];
            $existing['vat_rate'] = $price['vat_rate'];
        } else {
            $existing['net_minor'] = null;
            $existing['gross_minor'] = null;
            $existing['vat_rate'] = null;
        }

        return $existing;
    }

    /** @param array<string, array<string, mixed>> $priceIndex @return list<string> */
    private function candidatePriceCodes(?string $catalogueNumber, array $priceIndex): array
    {
        if ($catalogueNumber === null) {
            return [];
        }

        $hints = self::RELATED_CODE_HINTS[$catalogueNumber] ?? [];

        return array_values(array_filter(
            $hints,
            static fn (string $code): bool => isset($priceIndex[$code]),
        ));
    }

    private function grossFromNetMinor(int $netMinor, int $vatRate): int
    {
        return (int) round($netMinor * (100 + $vatRate) / 100, 0, PHP_ROUND_HALF_UP);
    }

    private function normalizeCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtoupper(trim((string) $value));

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

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringOrNull($item),
            $value,
        )));
    }
}
