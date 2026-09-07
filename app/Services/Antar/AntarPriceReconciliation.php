<?php

declare(strict_types=1);

namespace App\Services\Antar;

final class AntarPriceReconciliation
{
    /**
     * Explicitly approved aliases where the scraped Antar SKU differs from the
     * supplier price-list code. These are deliberately finite: no fuzzy SKU
     * matching is permitted in the commercial import boundary.
     *
     * @var array<string, array{supplier_code: string, evidence: string}>
     */
    private const SAFE_SKU_ALIASES = [
        'SNW500' => [
            'supplier_code' => 'SNW-500',
            'evidence' => 'Formatting-only difference; product name and Antar URL state SNW-500.',
        ],
        'SPU370' => [
            'supplier_code' => 'SPU-370',
            'evidence' => 'Formatting-only difference; product name and Antar URL state SPU-370.',
        ],
        '4028' => [
            'supplier_code' => 'AMELIA',
            'evidence' => 'Scraped product is Biustonosz AMELIA 4028; supplier list prices the model under AMELIA.',
        ],
        'HF6001-ALU' => [
            'supplier_code' => 'XI-ALU',
            'evidence' => 'Scraped product is the Xi ALU bubble mattress; supplier list uses Xi (ALU).',
        ],
        'HF6001-N' => [
            'supplier_code' => 'XI-N',
            'evidence' => 'Scraped product is the Xi N bubble mattress; supplier list uses Xi (N).',
        ],
        'HF6002-ALU' => [
            'supplier_code' => 'XI-RUROWY-ALU',
            'evidence' => 'Scraped product is the Xi tubular ALU mattress; supplier list distinguishes it from the Xi ALU bubble mattress.',
        ],
        'HF6002-ALU-18R' => [
            'supplier_code' => 'XI-RUROWY-ALU-18R',
            'evidence' => 'Scraped product is the Xi tubular ALU 18R mattress; supplier list has a dedicated 18R row.',
        ],
        'HF6002-EKO' => [
            'supplier_code' => 'XI-RUROWY-EKO',
            'evidence' => 'Scraped product is the Xi tubular EKO mattress; supplier list uses Xi Rurowy (EKO).',
        ],
        'AT04702-1' => [
            'supplier_code' => 'AT04702',
            'evidence' => 'The scraped copy URL/name identifies model AT04702; supplier list prices AT04702.',
        ],
        'AT53049-12' => [
            'supplier_code' => 'AT53079',
            'evidence' => 'Scraped product name and canonical URL both identify AT53079; the scraped SKU is stale/mismatched.',
        ],
        '220' => [
            'supplier_code' => 'ECON220',
            'evidence' => 'Scraped product is Wózek inwalidzki econ 220; supplier list uses ECON220.',
        ],
        '115' => [
            'supplier_code' => 'S-ERGO-115',
            'evidence' => 'Scraped product is Wózek inwalidzki s-ergo 115; supplier list uses S-ERGO 115.',
        ],
        '125' => [
            'supplier_code' => 'S-ERGO-125',
            'evidence' => 'Scraped product is Wózek inwalidzki s-ergo 125; supplier list uses S-ERGO 125.',
        ],
        '305' => [
            'supplier_code' => 'S-ERGO-305',
            'evidence' => 'Scraped product is Wózek inwalidzki s-ergo 305; supplier list uses S-ERGO 305.',
        ],
    ];

    /**
     * Products where Antar publishes no usable SKU in the scraped product
     * metadata, but the exact product URL identifies a supplier price-list
     * code unambiguously. URL matching is exact by path; names/numbers are not
     * searched heuristically.
     *
     * @var array<string, array{supplier_code: string, evidence: string}>
     */
    private const SAFE_PRODUCT_PATH_RECOVERIES = [
        '/produkt/kula-lokciowa-ergodynamic/' => [
            'supplier_code' => 'ERGODYNAMIC',
            'evidence' => 'Exact Antar product URL identifies the ERGODYNAMIC model.',
        ],
        '/produkt/kula-lokciowa-ergotech/' => [
            'supplier_code' => 'ERGOTECH',
            'evidence' => 'Exact Antar product URL identifies the ERGOTECH model.',
        ],
        '/produkt/orteza-tulowia-oppo-2356/' => [
            'supplier_code' => '2356',
            'evidence' => 'Exact Antar product URL identifies model 2356.',
        ],
        '/produkt/proteza-piersi-supreme-475/' => [
            'supplier_code' => '475',
            'evidence' => 'Exact Antar product URL identifies model 475.',
        ],
        '/produkt/torba-na-materac-rehabilitacyjny-trojdzielny-at03107/' => [
            'supplier_code' => 'TORBA-AT03107',
            'evidence' => 'Supplier list has a distinct Torba AT03107 row; AT03107 alone is the mattress and must not be used for the bag.',
        ],
        '/produkt/wozek-inwalidzki-agile/' => [
            'supplier_code' => 'AGILE',
            'evidence' => 'Exact Antar product URL identifies the AGILE wheelchair model.',
        ],
        '/produkt/wozek-inwalidzki-elektryczny-at52304-3/' => [
            'supplier_code' => 'AT52304',
            'evidence' => 'Exact Antar product URL and product name identify AT52304.',
        ],
    ];

    /**
     * Explicitly known relationships that still require human review before a
     * commercial price can be attached. Keeping them here prevents a future
     * implementation from silently stripping suffixes or collapsing variants.
     *
     * @var array<string, array{reason: string, candidate_codes: list<string>, note: string}>
     */
    private const MANUAL_SKU_RULES = [
        'AT51112-NH' => [
            'reason' => 'suffix_requires_review',
            'candidate_codes' => ['AT51112'],
            'note' => 'NH may identify a distinct configuration; do not strip the suffix automatically.',
        ],
        'AT04510-PRO' => [
            'reason' => 'suffix_requires_review',
            'candidate_codes' => ['AT04510'],
            'note' => 'PRO may identify a distinct configuration; supplier confirmation is required.',
        ],
        'AT03552S' => [
            'reason' => 'suffix_requires_review',
            'candidate_codes' => ['AT03552'],
            'note' => 'S may identify a distinct size/version; supplier confirmation is required.',
        ],
        'AT52303-1' => [
            'reason' => 'suffix_requires_review',
            'candidate_codes' => ['AT52303'],
            'note' => 'The -1/P wheelchair identifier must be confirmed before using the base AT52303 price.',
        ],
        'AT5141-0-AT51411-AT51412' => [
            'reason' => 'variant_split_required',
            'candidate_codes' => ['AT51410', 'AT51411', 'AT51412'],
            'note' => 'One web product represents multiple diameter-specific supplier codes and prices.',
        ],
        'AT51406-AT51407-AT51408-AT51409' => [
            'reason' => 'variant_split_required',
            'candidate_codes' => ['AT51406', 'AT51407', 'AT51408'],
            'note' => 'One web product represents multiple ABS ball diameters; the 85 cm spreadsheet row has no supplier code and must remain unresolved.',
        ],
    ];

    public function __construct(
        private readonly AntarSupplierPriceList $supplierPriceList,
    ) {}

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function build(array $source, ?string $sourceSha256 = null): array
    {
        $priceList = $this->supplierPriceList->load();
        $safeIndex = $priceList['safe_index'];
        $ambiguousIndex = $priceList['ambiguous_index'];
        $products = $this->records($source['products'] ?? []);

        $eligible = [];
        $manual = [];
        $excluded = [];
        $hardErrors = [];
        $seenIdentities = [];
        $exactMatches = 0;
        $skuAliasMatches = 0;
        $urlRecoveryMatches = 0;
        $ambiguousMatches = 0;
        $explicitManualReviews = 0;
        $withSku = 0;
        $vatBreakdown = [];

        foreach ($products as $position => $product) {
            $identity = $this->identity($product, $position);
            $identityKey = $this->identityKey($identity, $position);

            if (isset($seenIdentities[$identityKey])) {
                $hardErrors[] = sprintf(
                    'Duplicate Antar source product identity %s at positions %d and %d.',
                    $identityKey,
                    $seenIdentities[$identityKey] + 1,
                    $position + 1,
                );
            } else {
                $seenIdentities[$identityKey] = $position;
            }

            $normalizedSku = $this->supplierPriceList->normalizeCode($product['sku'] ?? null);

            if ($normalizedSku !== null) {
                $withSku++;
            }

            if ($normalizedSku !== null && isset($safeIndex[$normalizedSku])) {
                $eligible[] = $this->eligibleRow(
                    $identity,
                    $normalizedSku,
                    $normalizedSku,
                    'exact_supplier_code',
                    'Scraped Antar SKU directly matches a deterministic supplier price-list code.',
                    $safeIndex[$normalizedSku],
                );
                $exactMatches++;
                $this->incrementVat($vatBreakdown, (int) $safeIndex[$normalizedSku]['vat_rate']);

                continue;
            }

            if ($normalizedSku !== null && isset($ambiguousIndex[$normalizedSku])) {
                $manual[] = $this->manualRow(
                    $identity,
                    $normalizedSku,
                    'ambiguous_supplier_price',
                    [$normalizedSku],
                    'The supplier spreadsheet contains multiple commercial prices for this exact code; no price is selected automatically.',
                    $safeIndex,
                    $ambiguousIndex,
                );
                $ambiguousMatches++;

                continue;
            }

            if ($normalizedSku !== null && isset(self::SAFE_SKU_ALIASES[$normalizedSku])) {
                $rule = self::SAFE_SKU_ALIASES[$normalizedSku];
                $target = $this->supplierPriceList->normalizeCode($rule['supplier_code']);

                if ($target === null || ! isset($safeIndex[$target])) {
                    $hardErrors[] = sprintf(
                        '%s: approved SKU alias %s -> %s does not resolve to a deterministic supplier price.',
                        $identity['name'],
                        $normalizedSku,
                        $rule['supplier_code'],
                    );
                    $excluded[] = $this->excludedRow($identity, $normalizedSku, 'stale_approved_alias', $rule['evidence']);

                    continue;
                }

                $eligible[] = $this->eligibleRow(
                    $identity,
                    $normalizedSku,
                    $target,
                    'explicit_sku_alias',
                    $rule['evidence'],
                    $safeIndex[$target],
                );
                $skuAliasMatches++;
                $this->incrementVat($vatBreakdown, (int) $safeIndex[$target]['vat_rate']);

                continue;
            }

            $productPath = $this->productPath($identity['source_url']);

            if ($productPath !== null && isset(self::SAFE_PRODUCT_PATH_RECOVERIES[$productPath])) {
                $rule = self::SAFE_PRODUCT_PATH_RECOVERIES[$productPath];
                $target = $this->supplierPriceList->normalizeCode($rule['supplier_code']);

                if ($target === null || ! isset($safeIndex[$target])) {
                    $hardErrors[] = sprintf(
                        '%s: approved URL recovery %s -> %s does not resolve to a deterministic supplier price.',
                        $identity['name'],
                        $productPath,
                        $rule['supplier_code'],
                    );
                    $excluded[] = $this->excludedRow($identity, $normalizedSku, 'stale_approved_recovery', $rule['evidence']);

                    continue;
                }

                $eligible[] = $this->eligibleRow(
                    $identity,
                    $normalizedSku,
                    $target,
                    'explicit_url_recovery',
                    $rule['evidence'],
                    $safeIndex[$target],
                );
                $urlRecoveryMatches++;
                $this->incrementVat($vatBreakdown, (int) $safeIndex[$target]['vat_rate']);

                continue;
            }

            if ($normalizedSku !== null && isset(self::MANUAL_SKU_RULES[$normalizedSku])) {
                $rule = self::MANUAL_SKU_RULES[$normalizedSku];
                $manual[] = $this->manualRow(
                    $identity,
                    $normalizedSku,
                    $rule['reason'],
                    $rule['candidate_codes'],
                    $rule['note'],
                    $safeIndex,
                    $ambiguousIndex,
                );
                $explicitManualReviews++;

                continue;
            }

            if ($normalizedSku === null) {
                $excluded[] = $this->excludedRow(
                    $identity,
                    null,
                    'missing_scraped_sku',
                    'No scraped SKU and no explicitly approved exact-URL recovery exists. Numeric/name substring matching is intentionally forbidden.',
                );

                continue;
            }

            $excluded[] = $this->excludedRow(
                $identity,
                $normalizedSku,
                'supplier_price_not_found',
                'The normalized scraped SKU is absent from the deterministic and ambiguous supplier price indexes and has no explicit approved reconciliation rule.',
            );
        }

        ksort($vatBreakdown);
        $accountedFor = count($eligible) + count($manual) + count($excluded);

        if ($accountedFor !== count($products)) {
            $hardErrors[] = sprintf(
                'Reconciliation accounting mismatch: source products=%d, accounted=%d.',
                count($products),
                $accountedFor,
            );
        }

        $readySelective = $hardErrors === [] && $eligible !== [] && $accountedFor === count($products);
        $readyFull = $readySelective && $manual === [] && $excluded === [];

        return [
            'schema_version' => 'konji.antar.price-reconciliation.v1',
            'source' => 'antar',
            'database_writes' => false,
            'network_requests' => false,
            'source_product_data_sha256' => $sourceSha256,
            'supplier_price_list' => $priceList['metadata'],
            'supplier_summary' => $priceList['summary'],
            'summary' => [
                'source_products' => count($products),
                'source_products_with_sku' => $withSku,
                'source_products_without_sku' => count($products) - $withSku,
                'eligible_priced_products' => count($eligible),
                'exact_price_matches' => $exactMatches,
                'explicit_sku_alias_matches' => $skuAliasMatches,
                'explicit_url_recovery_matches' => $urlRecoveryMatches,
                'manual_price_review' => count($manual),
                'ambiguous_supplier_price_products' => $ambiguousMatches,
                'explicit_manual_review_products' => $explicitManualReviews,
                'excluded_unpriced_products' => count($excluded),
                'eligible_vat_breakdown' => $vatBreakdown,
                'hard_errors' => count($hardErrors),
            ],
            'hard_errors' => $hardErrors,
            'ready_for_selective_priced_import' => $readySelective,
            'ready_for_full_catalogue_import' => $readyFull,
            'eligible_priced_products' => $eligible,
            'manual_price_review' => $manual,
            'excluded_unpriced_products' => $excluded,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $supplier
     * @return array<string, mixed>
     */
    private function eligibleRow(
        array $identity,
        ?string $normalizedScrapedSku,
        string $supplierCode,
        string $matchMethod,
        string $matchEvidence,
        array $supplier,
    ): array {
        return $identity + [
            'normalized_scraped_sku' => $normalizedScrapedSku,
            'normalized_supplier_code' => $supplierCode,
            'match_method' => $matchMethod,
            'match_evidence' => $matchEvidence,
            'proposed' => [
                'price_net_amount' => (int) $supplier['net_minor'],
                'price_gross_amount' => (int) $supplier['gross_minor'],
                'vat_rate' => (int) $supplier['vat_rate'],
                'currency' => (string) $supplier['currency'],
            ],
            'supplier' => [
                'supplier_code' => (string) $supplier['supplier_code'],
                'source_rows' => $supplier['source_rows'],
                'descriptions' => $supplier['descriptions'],
                'effective_from' => AntarSupplierPriceList::EFFECTIVE_FROM,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $candidateCodes
     * @param  array<string, array<string, mixed>>  $safeIndex
     * @param  array<string, array<string, mixed>>  $ambiguousIndex
     * @return array<string, mixed>
     */
    private function manualRow(
        array $identity,
        ?string $normalizedScrapedSku,
        string $reason,
        array $candidateCodes,
        string $note,
        array $safeIndex,
        array $ambiguousIndex,
    ): array {
        $candidates = [];

        foreach ($candidateCodes as $candidateCode) {
            $normalized = $this->supplierPriceList->normalizeCode($candidateCode);

            if ($normalized === null) {
                continue;
            }

            if (isset($safeIndex[$normalized])) {
                $row = $safeIndex[$normalized];
                $candidates[] = [
                    'normalized_supplier_code' => $normalized,
                    'status' => 'deterministic_supplier_price',
                    'net_minor' => (int) $row['net_minor'],
                    'gross_minor' => (int) $row['gross_minor'],
                    'vat_rate' => (int) $row['vat_rate'],
                    'currency' => (string) $row['currency'],
                    'source_rows' => $row['source_rows'],
                    'descriptions' => $row['descriptions'],
                ];

                continue;
            }

            if (isset($ambiguousIndex[$normalized])) {
                $candidates[] = [
                    'normalized_supplier_code' => $normalized,
                    'status' => 'ambiguous_supplier_price',
                    'source_rows' => $ambiguousIndex[$normalized]['source_rows'],
                    'prices' => $ambiguousIndex[$normalized]['prices'],
                ];

                continue;
            }

            $candidates[] = [
                'normalized_supplier_code' => $normalized,
                'status' => 'supplier_price_not_found',
            ];
        }

        return $identity + [
            'normalized_scraped_sku' => $normalizedScrapedSku,
            'reason' => $reason,
            'note' => $note,
            'candidate_supplier_codes' => array_values(array_filter(array_map(
                fn (string $candidate): ?string => $this->supplierPriceList->normalizeCode($candidate),
                $candidateCodes,
            ), 'is_string')),
            'supplier_candidates' => $candidates,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function excludedRow(array $identity, ?string $normalizedScrapedSku, string $reason, string $note): array
    {
        return $identity + [
            'normalized_scraped_sku' => $normalizedScrapedSku,
            'reason' => $reason,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{external_product_id: ?string, name: string, scraped_sku: ?string, source_url: ?string}
     */
    private function identity(array $product, int $position): array
    {
        return [
            'external_product_id' => $this->stringOrNull($product['external_product_id'] ?? $product['external_id'] ?? null),
            'name' => $this->stringOrNull($product['name'] ?? null) ?: '[unnamed Antar product '.($position + 1).']',
            'scraped_sku' => $this->stringOrNull($product['sku'] ?? null),
            'source_url' => $this->stringOrNull($product['canonical_url'] ?? $product['source_url'] ?? $product['url'] ?? null),
        ];
    }

    /** @param array<string, mixed> $identity */
    private function identityKey(array $identity, int $position): string
    {
        if (is_string($identity['external_product_id'] ?? null) && $identity['external_product_id'] !== '') {
            return 'id:'.$identity['external_product_id'];
        }

        if (is_string($identity['source_url'] ?? null) && $identity['source_url'] !== '') {
            return 'url:'.$identity['source_url'];
        }

        return 'position:'.($position + 1);
    }

    private function productPath(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = '/'.trim($path, '/').'/';

        return strtolower($path);
    }

    /** @param array<int, int> $vatBreakdown */
    private function incrementVat(array &$vatBreakdown, int $vatRate): void
    {
        $vatBreakdown[$vatRate] = ($vatBreakdown[$vatRate] ?? 0) + 1;
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
}
