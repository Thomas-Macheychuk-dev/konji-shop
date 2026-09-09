<?php

declare(strict_types=1);

namespace App\Services\Antar;

use App\Enums\VatRate;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AntarSelectivePricedImportPlan
{
    public function __construct(
        private readonly AntarPriceReconciliation $reconciliation,
        private readonly AntarSupplierPriceList $supplierPriceList,
    ) {}

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $savedReconciliation
     * @return array<string, mixed>
     */
    public function build(
        array $source,
        string $sourceSha256,
        array $savedReconciliation,
        string $savedReconciliationSha256,
        array $databasePolicy = [],
    ): array {
        $current = $this->reconciliation->build($source, $sourceSha256);
        $errors = [];

        $this->validateSavedReconciliationMetadata($savedReconciliation, $current, $sourceSha256, $errors);
        $this->validateFrozenCohorts($savedReconciliation, $current, $errors);

        $products = $this->records($source['products'] ?? []);
        $sourceIndex = [];

        foreach ($products as $position => $product) {
            $key = $this->sourceIdentityKey($product, $position);

            if (isset($sourceIndex[$key])) {
                $errors[] = 'Duplicate source identity while building selective Antar import plan: '.$key.'.';

                continue;
            }

            $sourceIndex[$key] = $product;
        }

        $eligibleRows = $this->records($savedReconciliation['eligible_priced_products'] ?? []);
        $importRows = [];
        $eligibleExternalIds = [];
        $baseStorageSkuCounts = [];
        $vatBreakdown = [];
        $totalNetMinor = 0;
        $totalGrossMinor = 0;

        foreach ($eligibleRows as $position => $row) {
            $key = $this->reconciliationIdentityKey($row, $position);
            $product = $sourceIndex[$key] ?? null;

            if (! is_array($product)) {
                $errors[] = 'Eligible reconciliation row has no matching source product: '.$key.'.';

                continue;
            }

            $supplierCode = $this->supplierPriceList->normalizeCode($row['normalized_supplier_code'] ?? null);
            $proposed = $row['proposed'] ?? null;

            if ($supplierCode === null || ! is_array($proposed)) {
                $errors[] = 'Eligible reconciliation row '.$key.' is missing canonical supplier code or proposed pricing.';

                continue;
            }

            $pricing = $this->validatedPricing($proposed, $key, $errors);

            if ($pricing === null) {
                continue;
            }

            $externalId = $this->externalProductId($product);
            $eligibleExternalIds[$externalId] = true;
            $approvedCatalogueSku = $this->supplierPriceList->normalizeCode($row['approved_catalogue_sku'] ?? $row['normalized_supplier_code'] ?? null);
            $sourceSku = $this->supplierPriceList->normalizeCode($product['sku'] ?? null);
            $baseStorageSku = $sourceSku ?? $approvedCatalogueSku;

            if ($approvedCatalogueSku === null || $baseStorageSku === null) {
                $errors[] = 'Eligible reconciliation row '.$key.' is missing an approved catalogue/storage SKU identity.';

                continue;
            }

            $baseStorageSkuCounts[$baseStorageSku] = ($baseStorageSkuCounts[$baseStorageSku] ?? 0) + 1;
            $vatBreakdown[$pricing['vat_rate']] = ($vatBreakdown[$pricing['vat_rate']] ?? 0) + 1;
            $totalNetMinor += $pricing['price_net_amount'];
            $totalGrossMinor += $pricing['price_gross_amount'];

            $importRows[] = [
                'identity_key' => $key,
                'external_id' => $externalId,
                'name' => (string) ($row['name'] ?? $product['name'] ?? ''),
                'source_url' => $row['source_url'] ?? $product['canonical_url'] ?? $product['source_url'] ?? null,
                'match_method' => (string) ($row['match_method'] ?? ''),
                'supplier_price_code' => $supplierCode,
                'approved_catalogue_sku' => $approvedCatalogueSku,
                'base_storage_sku' => $baseStorageSku,
                'approved_pricing' => $pricing,
                'product' => $product,
            ];
        }

        $legacyExternalIdAliases = $this->normaliseLegacyExternalIdAliases(
            $databasePolicy['legacy_external_id_aliases'] ?? [],
        );
        $allowedExistingOutsideEligibleExternalIds = $this->normaliseExternalIdList(
            $databasePolicy['allowed_existing_outside_eligible_external_ids'] ?? [],
        );

        $storageResolution = $this->resolveStorageSkus(
            $importRows,
            $baseStorageSkuCounts,
            $legacyExternalIdAliases,
        );
        $importRows = $storageResolution['rows'];

        foreach ($storageResolution['hard_errors'] as $error) {
            $errors[] = $error;
        }

        ksort($vatBreakdown);
        $databaseAudit = $this->databaseAudit(
            array_keys($eligibleExternalIds),
            $allowedExistingOutsideEligibleExternalIds,
        );

        foreach ($databaseAudit['hard_errors'] as $error) {
            $errors[] = $error;
        }

        $summary = (array) ($savedReconciliation['summary'] ?? []);

        if (count($importRows) !== (int) ($summary['eligible_priced_products'] ?? -1)) {
            $errors[] = sprintf(
                'Eligible import-row count mismatch: reconciliation=%d, import plan=%d.',
                (int) ($summary['eligible_priced_products'] ?? -1),
                count($importRows),
            );
        }

        return [
            'schema_version' => 'konji.antar.selective-priced-import-plan.v1',
            'source' => 'antar',
            'database_writes' => false,
            'network_requests' => false,
            'source_product_data_sha256' => $sourceSha256,
            'price_reconciliation_sha256' => $savedReconciliationSha256,
            'supplier_price_list' => $current['supplier_price_list'] ?? [],
            'summary' => [
                'source_products' => count($products),
                'eligible_priced_products' => count($importRows),
                'manual_price_review' => (int) ($summary['manual_price_review'] ?? 0),
                'excluded_unpriced_products' => (int) ($summary['excluded_unpriced_products'] ?? 0),
                'eligible_vat_breakdown' => $vatBreakdown,
                'total_net_minor' => $totalNetMinor,
                'total_gross_minor' => $totalGrossMinor,
                'existing_antar_products' => $databaseAudit['existing_antar_products'],
                'existing_antar_products_outside_eligible_cohort' => $databaseAudit['existing_antar_products_outside_eligible_cohort'],
                'stored_sku_collisions_with_other_sources' => $storageResolution['cross_source_base_sku_collisions'],
                'duplicate_source_sku_groups_resolved' => $storageResolution['duplicate_source_sku_groups_resolved'],
                'namespaced_storage_skus' => $storageResolution['namespaced_storage_skus'],
                'hard_errors' => count($errors),
            ],
            'hard_errors' => array_values(array_unique($errors)),
            'ready_for_local_draft_import' => $errors === [] && $importRows !== [],
            'eligible_import_rows' => $importRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $current
     * @param  list<string>  $errors
     */
    private function validateSavedReconciliationMetadata(array $saved, array $current, string $sourceSha256, array &$errors): void
    {
        if (($saved['schema_version'] ?? null) !== 'konji.antar.price-reconciliation.v1') {
            $errors[] = 'Saved Antar reconciliation schema version is not supported.';
        }

        if (($saved['source'] ?? null) !== 'antar') {
            $errors[] = 'Saved price reconciliation does not identify Antar as its source.';
        }

        if (($saved['database_writes'] ?? null) !== false || ($saved['network_requests'] ?? null) !== false) {
            $errors[] = 'Saved Antar reconciliation is missing the expected read-only evidence flags.';
        }

        if (($saved['ready_for_selective_priced_import'] ?? null) !== true) {
            $errors[] = 'Saved Antar reconciliation is not approved for selective priced import.';
        }

        if (($saved['source_product_data_sha256'] ?? null) !== $sourceSha256) {
            $errors[] = 'Saved Antar reconciliation was generated from a different product-data file.';
        }

        $savedSupplier = (array) ($saved['supplier_price_list'] ?? []);
        $currentSupplier = (array) ($current['supplier_price_list'] ?? []);

        foreach (['source_sha256', 'reference_json_sha256', 'effective_from', 'source_file'] as $key) {
            if (($savedSupplier[$key] ?? null) !== ($currentSupplier[$key] ?? null)) {
                $errors[] = 'Saved Antar reconciliation supplier fingerprint mismatch for '.$key.'.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $current
     * @param  list<string>  $errors
     */
    private function validateFrozenCohorts(array $saved, array $current, array &$errors): void
    {
        foreach (['summary', 'eligible_priced_products', 'manual_price_review', 'excluded_unpriced_products', 'hard_errors'] as $key) {
            if (($saved[$key] ?? null) !== ($current[$key] ?? null)) {
                $errors[] = 'Saved Antar reconciliation has drifted from the current deterministic reconciliation for '.$key.'.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $errors
     * @return array{price_net_amount: int, price_gross_amount: int, vat_rate: int, currency: string}|null
     */
    private function validatedPricing(array $proposed, string $identityKey, array &$errors): ?array
    {
        $net = $proposed['price_net_amount'] ?? null;
        $gross = $proposed['price_gross_amount'] ?? null;
        $vat = $proposed['vat_rate'] ?? null;
        $currency = $proposed['currency'] ?? null;

        if (! is_int($net) || $net <= 0 || ! is_int($gross) || $gross <= 0 || ! is_int($vat) || ! is_string($currency)) {
            $errors[] = 'Eligible Antar pricing is incomplete for '.$identityKey.'.';

            return null;
        }

        $vatRate = VatRate::tryFrom($vat);

        if ($vatRate === null || strtoupper(trim($currency)) !== 'PLN') {
            $errors[] = 'Eligible Antar pricing has unsupported VAT/currency for '.$identityKey.'.';

            return null;
        }

        if ($vatRate->grossFromNet($net) !== $gross) {
            $errors[] = 'Eligible Antar pricing arithmetic mismatch for '.$identityKey.'.';

            return null;
        }

        return [
            'price_net_amount' => $net,
            'price_gross_amount' => $gross,
            'vat_rate' => $vat,
            'currency' => 'PLN',
        ];
    }

    /**
     * @param  list<string>  $eligibleExternalIds
     * @param  list<string>  $allowedExistingOutsideEligibleExternalIds
     * @return array{existing_antar_products: int, existing_antar_products_outside_eligible_cohort: int, hard_errors: list<string>}
     */
    private function databaseAudit(array $eligibleExternalIds, array $allowedExistingOutsideEligibleExternalIds = []): array
    {
        $existingAntar = Product::withTrashed()
            ->where('external_source', 'antar')
            ->get(['id', 'external_id']);

        $eligibleExternalIdSet = array_fill_keys($eligibleExternalIds, true);
        $outside = $existingAntar
            ->filter(fn (Product $product): bool => ! isset($eligibleExternalIdSet[(string) $product->external_id]));

        $allowedOutsideSet = array_fill_keys($allowedExistingOutsideEligibleExternalIds, true);
        $errors = [];

        foreach ($outside as $product) {
            $externalId = (string) $product->external_id;

            if (isset($allowedOutsideSet[$externalId])) {
                continue;
            }

            $errors[] = sprintf(
                'Existing Antar product ID %d (%s) is outside the frozen eligible priced cohort.',
                $product->id,
                $externalId,
            );
        }

        return [
            'existing_antar_products' => $existingAntar->count(),
            'existing_antar_products_outside_eligible_cohort' => $outside->count(),
            'hard_errors' => $errors,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $baseCounts
     * @param  array<string, string>  $legacyExternalIdAliases
     * @return array{rows: list<array<string, mixed>>, cross_source_base_sku_collisions: int, duplicate_source_sku_groups_resolved: int, namespaced_storage_skus: int, hard_errors: list<string>}
     */
    private function resolveStorageSkus(array $rows, array $baseCounts, array $legacyExternalIdAliases = []): array
    {
        $baseSkus = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['base_storage_sku'], $rows)));
        $crossSource = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.sku', $baseSkus)
            ->where(function ($query): void {
                $query->whereNull('products.external_source')->orWhere('products.external_source', '!=', 'antar');
            })
            ->get(['product_variants.sku']);
        $crossSourceSet = [];
        foreach ($crossSource as $variant) {
            $crossSourceSet[(string) $variant->sku] = true;
        }

        $duplicateGroups = count(array_filter($baseCounts, static fn (int $count): bool => $count > 1));
        $namespaced = 0;
        $finalSeen = [];
        $resolved = [];

        foreach ($rows as $row) {
            $base = (string) $row['base_storage_sku'];
            $externalId = (string) $row['external_id'];
            $needsNamespace = ($baseCounts[$base] ?? 0) > 1 || isset($crossSourceSet[$base]);
            $storageSku = $base;

            if ($needsNamespace) {
                $storageSku = $this->limitDatabaseString('ANTAR-'.$base.'-'.strtoupper(substr(sha1($externalId), 0, 8)));
                $namespaced++;
            }

            if (isset($finalSeen[$storageSku])) {
                $resolved[] = $row + ['storage_sku' => $storageSku];

                continue;
            }

            $finalSeen[$storageSku] = $externalId;
            $resolved[] = $row + ['storage_sku' => $storageSku];
        }

        $errors = [];
        foreach (array_count_values(array_map(static fn (array $row): string => (string) $row['storage_sku'], $resolved)) as $sku => $count) {
            if ($count > 1) {
                $errors[] = sprintf('Resolved Antar storage SKU %s is still assigned to %d eligible products.', $sku, $count);
            }
        }

        $finalSkus = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['storage_sku'], $resolved)));
        $existingFinals = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.sku', $finalSkus)
            ->get(['product_variants.sku', 'products.external_source', 'products.external_id']);

        $allowedBySku = [];
        foreach ($resolved as $row) {
            $externalId = (string) $row['external_id'];
            $allowedExternalIds = [$externalId];
            $legacyExternalId = $legacyExternalIdAliases[$externalId] ?? null;

            if (is_string($legacyExternalId) && $legacyExternalId !== '') {
                $allowedExternalIds[] = $legacyExternalId;
            }

            $allowedBySku[(string) $row['storage_sku']] = array_values(array_unique($allowedExternalIds));
        }

        foreach ($existingFinals as $variant) {
            $sku = (string) $variant->sku;
            $allowedExternalIds = $allowedBySku[$sku] ?? [];
            if ((string) ($variant->external_source ?? '') === 'antar' && in_array((string) $variant->external_id, $allowedExternalIds, true)) {
                continue;
            }
            $errors[] = sprintf('Resolved Antar storage SKU %s still collides with existing product source %s external ID %s.', $sku, (string) ($variant->external_source ?? 'manual'), (string) ($variant->external_id ?? 'unknown'));
        }

        return [
            'rows' => $resolved,
            'cross_source_base_sku_collisions' => count($crossSourceSet),
            'duplicate_source_sku_groups_resolved' => $duplicateGroups,
            'namespaced_storage_skus' => $namespaced,
            'hard_errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return list<string> */
    private function normaliseExternalIdList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $resolved = [];

        foreach ($value as $externalId) {
            $externalId = $this->stringOrNull($externalId);

            if ($externalId !== null) {
                $resolved[$externalId] = true;
            }
        }

        return array_keys($resolved);
    }

    /** @return array<string, string> */
    private function normaliseLegacyExternalIdAliases(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $resolved = [];

        foreach ($value as $currentExternalId => $legacyExternalId) {
            $currentExternalId = $this->stringOrNull($currentExternalId);
            $legacyExternalId = $this->stringOrNull($legacyExternalId);

            if ($currentExternalId !== null && $legacyExternalId !== null && $currentExternalId !== $legacyExternalId) {
                $resolved[$currentExternalId] = $legacyExternalId;
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $product */
    private function sourceIdentityKey(array $product, int $position): string
    {
        $externalId = $this->stringOrNull($product['external_product_id'] ?? $product['external_id'] ?? null);

        if ($externalId !== null) {
            return 'id:'.$externalId;
        }

        $url = $this->stringOrNull($product['canonical_url'] ?? $product['source_url'] ?? $product['url'] ?? null);

        if ($url !== null) {
            return 'url:'.$url;
        }

        throw new RuntimeException('Antar source product at position '.($position + 1).' has no stable identity.');
    }

    /** @param array<string, mixed> $row */
    private function reconciliationIdentityKey(array $row, int $position): string
    {
        $externalId = $this->stringOrNull($row['external_product_id'] ?? null);

        if ($externalId !== null) {
            return 'id:'.$externalId;
        }

        $url = $this->stringOrNull($row['source_url'] ?? null);

        if ($url !== null) {
            return 'url:'.$url;
        }

        throw new RuntimeException('Eligible Antar reconciliation row at position '.($position + 1).' has no stable identity.');
    }

    /** @param array<string, mixed> $product */
    private function externalProductId(array $product): string
    {
        $externalId = $this->stringOrNull($product['external_product_id'] ?? null)
            ?: $this->stringOrNull($product['source_product_external_id'] ?? null)
                ?: $this->stringOrNull($product['external_id'] ?? null)
                    ?: $this->stringOrNull($product['slug'] ?? null);

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $sourceUrl = $this->stringOrNull($product['canonical_url'] ?? null)
            ?: $this->stringOrNull($product['source_url'] ?? null)
                ?: (string) ($product['name'] ?? 'antar-product');

        return substr(sha1($sourceUrl), 0, 32);
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= 190) {
            return $value;
        }

        $hash = substr(sha1($value), 0, 10);
        $prefixLength = 190 - mb_strlen($hash) - 1;

        return rtrim(mb_substr($value, 0, max(1, $prefixLength)), '-_ .').'_'.$hash;
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
