<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use App\Enums\VatRate;

final class NeoxmedPricedMapBuilder
{
    private const SOURCE = 'neoxmed';

    private const CURRENCY = 'PLN';

    /**
     * @param  array<string, mixed>  $importMap
     * @return array<string, mixed>
     */
    public function buildApprovalTemplate(array $importMap, string $importMapSha256): array
    {
        $products = [];

        foreach ($this->records($importMap['products'] ?? []) as $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $variant = $this->records($mapped['variants'] ?? [])[0] ?? [];
            $externalId = $this->stringOrNull($product['external_id'] ?? null);

            if ($externalId === null) {
                continue;
            }

            $products[] = [
                'external_product_id' => $externalId,
                'planned_sku' => $this->stringOrNull($variant['sku'] ?? null),
                'name' => $this->stringOrNull($product['name'] ?? null),
                'net_amount_pln' => null,
                'gross_amount_pln' => null,
                'vat_rate' => null,
                'media_override_url' => null,
                'media_override_alt' => null,
                'notes' => null,
            ];
        }

        return [
            'source' => self::SOURCE,
            'mode' => 'commercial_approval_template',
            'database_writes' => false,
            'import_map_sha256' => strtolower($importMapSha256),
            'currency' => self::CURRENCY,
            'approval_reference' => null,
            'approved_by' => null,
            'approved_at' => null,
            'products' => $products,
        ];
    }

    /**
     * @param  array<string, mixed>  $importMap
     * @param  array<string, mixed>  $approvals
     * @return array<string, mixed>
     */
    public function build(
        array $importMap,
        string $importMapSha256,
        array $approvals,
        string $approvalsSha256,
    ): array {
        $errors = [];
        $blockingReviewItems = [];
        $reviewItems = [];
        $pricedProducts = [];
        $approvalRows = $this->records($approvals['products'] ?? []);
        $approvalsById = [];
        $seenApprovalIds = [];

        if (($importMap['source'] ?? null) !== self::SOURCE || ! is_array($importMap['products'] ?? null)) {
            $errors[] = 'Import map must contain source="neoxmed" and a products array.';
        }

        if (($importMap['mapping_structurally_valid'] ?? false) !== true) {
            $errors[] = 'NeoxMed import map is not structurally valid.';
        }

        if (($approvals['source'] ?? null) !== self::SOURCE) {
            $errors[] = 'Commercial approvals must contain source="neoxmed".';
        }

        if (($approvals['currency'] ?? null) !== self::CURRENCY) {
            $errors[] = 'Commercial approvals currency must be PLN.';
        }

        $approvalImportSha = strtolower((string) ($approvals['import_map_sha256'] ?? ''));
        if ($approvalImportSha === '' || ! hash_equals(strtolower($importMapSha256), $approvalImportSha)) {
            $errors[] = 'Commercial approvals were not generated from this exact NeoxMed import map SHA-256.';
        }

        foreach ($approvalRows as $row) {
            $externalId = $this->stringOrNull($row['external_product_id'] ?? null);
            if ($externalId === null) {
                $errors[] = 'Commercial approval row is missing external_product_id.';

                continue;
            }

            if (isset($seenApprovalIds[$externalId])) {
                $errors[] = 'Duplicate commercial approval row for '.$externalId.'.';

                continue;
            }

            $seenApprovalIds[$externalId] = true;
            $approvalsById[$externalId] = $row;
        }

        $importExternalIds = [];
        $missingPriceCount = 0;
        $missingVatCount = 0;
        $grossVatMismatchCount = 0;
        $mediaOverrideCount = 0;
        $missingRequiredMediaCount = 0;
        $approvedProductCount = 0;
        $vatCounts = [];

        foreach ($this->records($importMap['products'] ?? []) as $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $variants = $this->records($mapped['variants'] ?? []);
            $variant = $variants[0] ?? [];
            $externalId = $this->stringOrNull($product['external_id'] ?? null);
            $name = $this->stringOrNull($product['name'] ?? null) ?? 'Unnamed NeoxMed product';

            if ($externalId === null) {
                $errors[] = $name.': mapped product external ID is missing.';

                continue;
            }

            $importExternalIds[$externalId] = true;
            $approval = $approvalsById[$externalId] ?? null;

            if (! is_array($approval)) {
                $blockingReviewItems[] = $externalId.' | '.$name.': commercial approval row is missing.';
                $missingPriceCount++;
                $missingVatCount++;
                $pricedProducts[] = $mapped;

                continue;
            }

            $expectedSku = $this->stringOrNull($variant['sku'] ?? null);
            $approvalSku = $this->stringOrNull($approval['planned_sku'] ?? null);
            if ($expectedSku === null || $approvalSku === null || $expectedSku !== $approvalSku) {
                $errors[] = $externalId.' | '.$name.': planned_sku does not match the frozen import map.';
            }

            $approvalName = $this->stringOrNull($approval['name'] ?? null);
            if ($approvalName === null || $approvalName !== $name) {
                $errors[] = $externalId.' | '.$name.': approval name does not match the frozen import map.';
            }

            $netMinor = $this->parseMoney($approval['net_amount_pln'] ?? null, $externalId.' net_amount_pln', $errors);
            $grossMinor = $this->parseMoney($approval['gross_amount_pln'] ?? null, $externalId.' gross_amount_pln', $errors);
            $vatRate = $this->parseVatRate($approval['vat_rate'] ?? null, $externalId, $errors);

            if ($netMinor === null || $grossMinor === null) {
                $missingPriceCount++;
                $blockingReviewItems[] = $externalId.' | '.$name.': approved positive net and gross PLN prices are required.';
            }

            if ($vatRate === null) {
                $missingVatCount++;
                $blockingReviewItems[] = $externalId.' | '.$name.': an explicit supported VAT rate is required.';
            }

            if ($netMinor !== null && $grossMinor !== null && $vatRate !== null) {
                $expectedGross = $vatRate->grossFromNet($netMinor);
                if ($expectedGross !== $grossMinor) {
                    $grossVatMismatchCount++;
                    $errors[] = sprintf(
                        '%s | %s: gross/VAT mismatch; net %s PLN at %d%% requires gross %s PLN, approval contains %s PLN.',
                        $externalId,
                        $name,
                        $this->formatMoney($netMinor),
                        $vatRate->value,
                        $this->formatMoney($expectedGross),
                        $this->formatMoney($grossMinor),
                    );
                } else {
                    $approvedProductCount++;
                    $vatCounts[$vatRate->value] = ($vatCounts[$vatRate->value] ?? 0) + 1;
                    $mapped['pricing'] = [
                        'source_gross_amount' => null,
                        'net_minor' => $netMinor,
                        'gross_minor' => $grossMinor,
                        'vat_rate' => $vatRate->value,
                        'currency' => self::CURRENCY,
                        'requires_review' => false,
                        'source' => 'approved_neoxmed_commercial_map',
                    ];
                    $mapped['variants'][0]['price_net_minor'] = $netMinor;
                    $mapped['variants'][0]['price_gross_minor'] = $grossMinor;
                    $mapped['variants'][0]['vat_rate'] = $vatRate->value;
                    $mapped['variants'][0]['currency'] = self::CURRENCY;
                }
            }

            if ($this->records($mapped['images'] ?? []) === []) {
                $overrideUrl = $this->httpsUrlOrNull($approval['media_override_url'] ?? null);
                if ($overrideUrl === null) {
                    $missingRequiredMediaCount++;
                    $blockingReviewItems[] = $externalId.' | '.$name.': approved HTTPS product media is required because the source has no normal product image.';
                } else {
                    $mediaOverrideCount++;
                    $mapped['images'][] = [
                        'source_url' => $overrideUrl,
                        'alt' => $this->stringOrNull($approval['media_override_alt'] ?? null) ?? $name,
                        'role' => 'product',
                        'source' => 'commercial_approval_override',
                    ];
                    $mapped['blocking_review_items'] = array_values(array_filter(
                        $mapped['blocking_review_items'] ?? [],
                        static fn (mixed $item): bool => ! is_string($item) || ! str_contains($item, 'does not publish a normal product image'),
                    ));
                }
            }

            $pricedProducts[] = $mapped;
        }

        foreach (array_keys($approvalsById) as $approvalId) {
            if (! isset($importExternalIds[$approvalId])) {
                $errors[] = 'Commercial approval contains unknown NeoxMed product '.$approvalId.'.';
            }
        }

        if ($missingPriceCount > 0) {
            $blockingReviewItems[] = $missingPriceCount.' NeoxMed products still lack approved positive net/gross PLN pricing.';
        }

        if ($missingVatCount > 0) {
            $blockingReviewItems[] = $missingVatCount.' NeoxMed products still lack an explicit supported VAT rate.';
        }

        if ($missingRequiredMediaCount > 0) {
            $blockingReviewItems[] = $missingRequiredMediaCount.' NeoxMed products still lack required approved product media.';
        }

        if ($this->stringOrNull($approvals['approval_reference'] ?? null) === null) {
            $reviewItems[] = 'Commercial approval reference is blank; populate it for audit traceability before production use.';
        }

        if ($this->stringOrNull($approvals['approved_by'] ?? null) === null) {
            $reviewItems[] = 'Commercial approver is blank; populate it for audit traceability before production use.';
        }

        if ($this->stringOrNull($approvals['approved_at'] ?? null) === null) {
            $reviewItems[] = 'Commercial approval timestamp is blank; populate it for audit traceability before production use.';
        }

        $errors = array_values(array_unique($errors));
        $blockingReviewItems = array_values(array_unique($blockingReviewItems));
        $reviewItems = array_values(array_unique($reviewItems));
        ksort($vatCounts);

        $databaseAudit = is_array($importMap['database_audit'] ?? null) ? $importMap['database_audit'] : [];
        $databaseSafe = ($databaseAudit['safe_for_future_import_implementation'] ?? false) === true;
        if (! $databaseSafe) {
            $errors[] = 'Frozen NeoxMed import map does not contain a passing current-database audit.';
        }

        $ready = $errors === []
            && $blockingReviewItems === []
            && $missingPriceCount === 0
            && $missingVatCount === 0
            && $missingRequiredMediaCount === 0
            && count($pricedProducts) === count($this->records($importMap['products'] ?? []));

        return [
            'source' => self::SOURCE,
            'mode' => 'priced_import_mapping_dry_run',
            'database_writes' => false,
            'images_downloaded' => false,
            'input_fingerprints' => [
                'import_map_sha256' => strtolower($importMapSha256),
                'commercial_approvals_sha256' => strtolower($approvalsSha256),
            ],
            'approval_metadata' => [
                'approval_reference' => $this->stringOrNull($approvals['approval_reference'] ?? null),
                'approved_by' => $this->stringOrNull($approvals['approved_by'] ?? null),
                'approved_at' => $this->stringOrNull($approvals['approved_at'] ?? null),
            ],
            'source_product_count' => count($this->records($importMap['products'] ?? [])),
            'approval_row_count' => count($approvalRows),
            'mapped_product_count' => count($pricedProducts),
            'products' => $pricedProducts,
            'database_audit' => $databaseAudit,
            'summary' => [
                'approved_products' => $approvedProductCount,
                'products_without_price' => $missingPriceCount,
                'products_without_vat' => $missingVatCount,
                'gross_vat_mismatches' => $grossVatMismatchCount,
                'required_media_missing' => $missingRequiredMediaCount,
                'media_overrides' => $mediaOverrideCount,
                'currency' => self::CURRENCY,
                'vat_rate_counts' => $vatCounts,
            ],
            'errors' => array_values(array_unique($errors)),
            'blocking_review_items' => $blockingReviewItems,
            'review_items' => $reviewItems,
            'ready_for_database_write' => $ready,
        ];
    }

    /** @param list<string> $errors */
    private function parseMoney(mixed $value, string $label, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $errors[] = $label.' must be a positive PLN decimal amount.';

            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $normalized) !== 1) {
            $errors[] = $label.' must use at most two decimal places.';

            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($minor <= 0) {
            $errors[] = $label.' must be greater than zero.';

            return null;
        }

        return $minor;
    }

    /** @param list<string> $errors */
    private function parseVatRate(mixed $value, string $externalId, array &$errors): ?VatRate
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        if (! is_int($value)) {
            $errors[] = $externalId.' vat_rate must be one of 0, 5, 8, 23.';

            return null;
        }

        $vat = VatRate::tryFrom($value);
        if ($vat === null) {
            $errors[] = $externalId.' vat_rate must be one of 0, 5, 8, 23.';
        }

        return $vat;
    }

    private function httpsUrlOrNull(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    private function formatMoney(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
