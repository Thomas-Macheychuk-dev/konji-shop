<?php

declare(strict_types=1);

namespace App\Services\Antar;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Product;
use App\Models\ProductVariant;

final class AntarProductionPreflight
{
    public function __construct(
        private readonly AntarSelectivePricedImportPlan $planner,
    ) {}

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $savedReconciliation
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    public function inspect(
        array $source,
        string $sourceSha256,
        array $savedReconciliation,
        string $savedReconciliationSha256,
        array $expected,
    ): array {
        $errors = [];
        $checks = [];

        $sourceRows = $this->records($source['products'] ?? []);
        $approvedRows = $this->records($savedReconciliation['eligible_priced_products'] ?? []);
        $sourceIds = $this->externalIds($sourceRows, 'external_product_id');
        $approvedIds = $this->externalIds($approvedRows, 'external_product_id');
        $currentNonApprovedIds = array_values(array_diff($sourceIds, $approvedIds));

        $legacyAliases = $this->legacyAliases($expected['legacy_aliases'] ?? []);
        $obsoleteExternalIds = $this->stringList($expected['obsolete_external_ids'] ?? []);
        $allowedOutside = array_values(array_unique(array_merge(
            $currentNonApprovedIds,
            array_values($legacyAliases),
            $obsoleteExternalIds,
        )));

        $plan = $this->planner->build(
            $source,
            $sourceSha256,
            $savedReconciliation,
            $savedReconciliationSha256,
            [
                'legacy_external_id_aliases' => $legacyAliases,
                'allowed_existing_outside_eligible_external_ids' => $allowedOutside,
            ],
        );

        foreach (array_values(array_filter($plan['hard_errors'] ?? [], 'is_string')) as $error) {
            $errors[] = 'planner: '.$error;
        }

        $antarProducts = Product::withTrashed()
            ->where('external_source', 'antar')
            ->with('variants')
            ->get();
        $antarByExternalId = $antarProducts->keyBy(static fn (Product $product): string => (string) $product->external_id);
        $dbIds = $antarByExternalId->keys()->all();
        $approvedExactExisting = array_values(array_intersect($approvedIds, $dbIds));
        $approvedMissing = array_values(array_diff($approvedIds, $dbIds));
        $currentMissing = array_values(array_diff($sourceIds, $dbIds));
        $productionNotCurrent = array_values(array_diff($dbIds, $sourceIds));
        $currentNonApprovedExisting = array_values(array_intersect($currentNonApprovedIds, $dbIds));
        $variants = ProductVariant::withTrashed()
            ->whereIn('product_id', $antarProducts->pluck('id')->all())
            ->get();

        $metrics = [
            'source_products' => count($sourceRows),
            'source_unique_external_ids' => count(array_unique($sourceIds)),
            'approved_products' => count($approvedRows),
            'approved_unique_external_ids' => count(array_unique($approvedIds)),
            'production_products' => $antarProducts->count(),
            'production_variants' => $variants->count(),
            'production_live_products' => $antarProducts->filter(static fn (Product $product): bool => ! $product->trashed())->count(),
            'production_live_variants' => $variants->filter(static fn (ProductVariant $variant): bool => ! $variant->trashed())->count(),
            'production_drafts' => $antarProducts->filter(static fn (Product $product): bool => $product->status === ProductStatus::DRAFT)->count(),
            'production_variant_drafts' => $variants->filter(static fn (ProductVariant $variant): bool => $variant->status === ProductVariantStatus::DRAFT)->count(),
            'production_unpriced_variants' => $variants->filter(static fn (ProductVariant $variant): bool => $variant->price_net_amount === null || $variant->price_gross_amount === null)->count(),
            'approved_exact_existing' => count($approvedExactExisting),
            'approved_missing_current_ids' => count($approvedMissing),
            'current_source_missing_from_production' => count($currentMissing),
            'production_not_in_current_source' => count($productionNotCurrent),
            'current_non_approved' => count($currentNonApprovedIds),
            'current_non_approved_existing' => count($currentNonApprovedExisting),
            'existing_outside_approved' => (int) (($plan['summary']['existing_antar_products_outside_eligible_cohort'] ?? -1)),
            'cross_source_base_sku_collisions' => (int) (($plan['summary']['stored_sku_collisions_with_other_sources'] ?? -1)),
            'duplicate_source_sku_groups' => (int) (($plan['summary']['duplicate_source_sku_groups_resolved'] ?? -1)),
            'namespaced_storage_skus' => (int) (($plan['summary']['namespaced_storage_skus'] ?? -1)),
        ];

        $this->checkCount($checks, $errors, 'source_products', $metrics['source_products'], $expected['source_products'] ?? null);
        $this->checkCount($checks, $errors, 'source_unique_external_ids', $metrics['source_unique_external_ids'], $expected['source_products'] ?? null);
        $this->checkCount($checks, $errors, 'approved_products', $metrics['approved_products'], $expected['approved_products'] ?? null);
        $this->checkCount($checks, $errors, 'approved_unique_external_ids', $metrics['approved_unique_external_ids'], $expected['approved_products'] ?? null);
        $this->checkCount($checks, $errors, 'production_products', $metrics['production_products'], $expected['production_products'] ?? null);
        $this->checkCount($checks, $errors, 'production_variants', $metrics['production_variants'], $expected['production_variants'] ?? null);
        $this->checkCount($checks, $errors, 'production_live_products', $metrics['production_live_products'], $expected['production_live_products'] ?? null);
        $this->checkCount($checks, $errors, 'production_live_variants', $metrics['production_live_variants'], $expected['production_live_variants'] ?? null);
        $this->checkCount($checks, $errors, 'production_drafts', $metrics['production_drafts'], $expected['production_drafts'] ?? null);
        $this->checkCount($checks, $errors, 'production_variant_drafts', $metrics['production_variant_drafts'], $expected['production_variant_drafts'] ?? null);
        $this->checkCount($checks, $errors, 'production_unpriced_variants', $metrics['production_unpriced_variants'], $expected['production_unpriced_variants'] ?? null);
        $this->checkCount($checks, $errors, 'approved_exact_existing', $metrics['approved_exact_existing'], $expected['approved_exact_existing'] ?? null);
        $this->checkCount($checks, $errors, 'approved_missing_current_ids', $metrics['approved_missing_current_ids'], $expected['approved_missing_current_ids'] ?? null);
        $this->checkCount($checks, $errors, 'current_source_missing_from_production', $metrics['current_source_missing_from_production'], $expected['current_source_missing_from_production'] ?? null);
        $this->checkCount($checks, $errors, 'production_not_in_current_source', $metrics['production_not_in_current_source'], $expected['production_not_in_current_source'] ?? null);
        $this->checkCount($checks, $errors, 'current_non_approved', $metrics['current_non_approved'], $expected['current_non_approved'] ?? null);
        $this->checkCount($checks, $errors, 'current_non_approved_existing', $metrics['current_non_approved_existing'], $expected['current_non_approved_existing'] ?? null);
        $this->checkCount($checks, $errors, 'existing_outside_approved', $metrics['existing_outside_approved'], $expected['existing_outside_approved'] ?? null);
        $this->checkCount($checks, $errors, 'cross_source_base_sku_collisions', $metrics['cross_source_base_sku_collisions'], $expected['cross_source_base_sku_collisions'] ?? null);
        $this->checkCount($checks, $errors, 'duplicate_source_sku_groups', $metrics['duplicate_source_sku_groups'], $expected['duplicate_source_sku_groups'] ?? null);
        $this->checkCount($checks, $errors, 'namespaced_storage_skus', $metrics['namespaced_storage_skus'], $expected['namespaced_storage_skus'] ?? null);

        $this->checkExactSet($checks, $errors, 'approved_missing_external_ids', $approvedMissing, $this->stringList($expected['approved_missing_external_ids'] ?? []));
        $this->checkExactSet($checks, $errors, 'current_missing_external_ids', $currentMissing, $this->stringList($expected['current_missing_external_ids'] ?? []));
        $this->checkExactSet($checks, $errors, 'production_not_current_external_ids', $productionNotCurrent, $this->stringList($expected['production_not_current_external_ids'] ?? []));
        $this->checkExactSet($checks, $errors, 'create_external_ids', array_values(array_diff($approvedMissing, array_keys($legacyAliases))), $this->stringList($expected['create_external_ids'] ?? []));

        foreach ($this->legacyRows($expected['legacy_aliases'] ?? []) as $currentId => $legacy) {
            $currentMatches = $antarProducts->filter(static fn (Product $product): bool => (string) $product->external_id === $currentId);
            $legacyMatches = $antarProducts->filter(static fn (Product $product): bool => (string) $product->external_id === $legacy['external_id']);

            if ($currentMatches->count() !== 0) {
                $errors[] = 'legacy '.$currentId.': current external ID already exists before migration.';
            }

            if ($legacyMatches->count() !== 1) {
                $errors[] = sprintf('legacy %s: expected exactly one production row for %s; actual %d.', $currentId, $legacy['external_id'], $legacyMatches->count());

                continue;
            }

            /** @var Product $legacyProduct */
            $legacyProduct = $legacyMatches->first();
            $legacyVariant = $legacyProduct->variants->first();

            if (isset($legacy['product_id']) && $legacyProduct->id !== (int) $legacy['product_id']) {
                $errors[] = sprintf('legacy %s: expected product ID %d; actual %d.', $currentId, (int) $legacy['product_id'], $legacyProduct->id);
            }

            if ($legacyProduct->status !== ProductStatus::DRAFT) {
                $errors[] = 'legacy '.$currentId.': production legacy row is not draft.';
            }

            if (($legacy['sku'] ?? null) !== null && $legacyVariant?->sku !== $legacy['sku']) {
                $errors[] = sprintf('legacy %s: expected variant SKU %s; actual %s.', $currentId, (string) $legacy['sku'], (string) ($legacyVariant?->sku ?? 'NULL'));
            }
        }

        foreach ($this->obsoleteRows($expected['obsolete_rows'] ?? []) as $externalId => $obsolete) {
            $product = $antarByExternalId->get($externalId);

            if (! $product instanceof Product) {
                $errors[] = 'obsolete '.$externalId.': expected production-only draft row is missing.';

                continue;
            }

            if (isset($obsolete['product_id']) && $product->id !== (int) $obsolete['product_id']) {
                $errors[] = sprintf('obsolete %s: expected product ID %d; actual %d.', $externalId, (int) $obsolete['product_id'], $product->id);
            }

            if ($product->status !== ProductStatus::DRAFT) {
                $errors[] = 'obsolete '.$externalId.': row must remain draft.';
            }
        }

        $rescueFiles = $this->stringList($expected['rescue_files'] ?? []);
        $missingRescueFiles = [];
        foreach ($rescueFiles as $relativePath) {
            $path = resource_path($relativePath);
            if (! is_file($path) || filesize($path) === 0) {
                $missingRescueFiles[] = $relativePath;
            }
        }
        $metrics['reviewed_rescue_files'] = count($rescueFiles);
        $metrics['missing_reviewed_rescue_files'] = count($missingRescueFiles);
        if ($missingRescueFiles !== []) {
            $errors[] = 'Reviewed rescue files are missing/empty: '.implode(', ', $missingRescueFiles).'.';
        }

        $minimumFreeMiB = max(0, (int) ($expected['minimum_free_mib'] ?? 0));
        $freeBytes = @disk_free_space(storage_path('app/public'));
        $freeMiB = is_numeric($freeBytes) ? (int) floor((float) $freeBytes / 1024 / 1024) : null;
        $metrics['public_storage_free_mib'] = $freeMiB;
        if ($minimumFreeMiB > 0 && ($freeMiB === null || $freeMiB < $minimumFreeMiB)) {
            $errors[] = sprintf('Public storage free space is below the required %d MiB (actual %s MiB).', $minimumFreeMiB, $freeMiB === null ? 'unknown' : (string) $freeMiB);
        }

        $errors = array_values(array_unique($errors));

        return [
            'schema_version' => 'konji.antar.production-preflight.v1',
            'source' => 'antar',
            'database_writes' => false,
            'filesystem_writes' => false,
            'network_requests' => false,
            'source_product_data_sha256' => $sourceSha256,
            'price_reconciliation_sha256' => $savedReconciliationSha256,
            'metrics' => $metrics,
            'checks' => $checks,
            'approved_missing_external_ids' => $approvedMissing,
            'current_missing_external_ids' => $currentMissing,
            'production_not_current_external_ids' => $productionNotCurrent,
            'current_non_approved_external_ids' => $currentNonApprovedIds,
            'planner_summary' => $plan['summary'] ?? [],
            'eligible_import_rows' => $plan['eligible_import_rows'] ?? [],
            'errors' => $errors,
            'ready_for_production_reconciliation' => $errors === [],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function externalIds(array $rows, string $key): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $value = $this->stringOrNull($row[$key] ?? null);
            if ($value !== null) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $item = $this->stringOrNull($item);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    /** @return array<string, string> */
    private function legacyAliases(mixed $value): array
    {
        $result = [];
        foreach ($this->legacyRows($value) as $currentId => $row) {
            $result[$currentId] = $row['external_id'];
        }

        return $result;
    }

    /** @return array<string, array{external_id: string, product_id?: int, sku?: string}> */
    private function legacyRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $currentId => $row) {
            $currentId = $this->stringOrNull($currentId);
            if ($currentId === null || ! is_array($row)) {
                continue;
            }
            $legacyId = $this->stringOrNull($row['external_id'] ?? null);
            if ($legacyId === null) {
                continue;
            }
            $resolved = ['external_id' => $legacyId];
            if (isset($row['product_id']) && is_numeric($row['product_id'])) {
                $resolved['product_id'] = (int) $row['product_id'];
            }
            if (($sku = $this->stringOrNull($row['sku'] ?? null)) !== null) {
                $resolved['sku'] = $sku;
            }
            $result[$currentId] = $resolved;
        }

        return $result;
    }

    /** @return array<string, array{product_id?: int}> */
    private function obsoleteRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $externalId => $row) {
            $externalId = $this->stringOrNull($externalId);
            if ($externalId === null || ! is_array($row)) {
                continue;
            }
            $resolved = [];
            if (isset($row['product_id']) && is_numeric($row['product_id'])) {
                $resolved['product_id'] = (int) $row['product_id'];
            }
            $result[$externalId] = $resolved;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $checks @param list<string> $errors */
    private function checkCount(array &$checks, array &$errors, string $name, int $actual, mixed $expected): void
    {
        if (! is_numeric($expected)) {
            return;
        }
        $expected = (int) $expected;
        $passed = $actual === $expected;
        $checks[] = ['name' => $name, 'status' => $passed ? 'PASS' : 'FAIL', 'expected' => $expected, 'actual' => $actual];
        if (! $passed) {
            $errors[] = sprintf('%s mismatch: expected %d; actual %d.', $name, $expected, $actual);
        }
    }

    /** @param list<array<string, mixed>> $checks @param list<string> $errors @param list<string> $actual @param list<string> $expected */
    private function checkExactSet(array &$checks, array &$errors, string $name, array $actual, array $expected): void
    {
        sort($actual);
        sort($expected);
        $passed = $actual === $expected;
        $checks[] = ['name' => $name, 'status' => $passed ? 'PASS' : 'FAIL', 'expected' => $expected, 'actual' => $actual];
        if (! $passed) {
            $errors[] = $name.' mismatch.';
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
