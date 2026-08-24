<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SigvarisOfficialPriceWriter
{
    public const EXPECTED_PRODUCT_COUNT = 226;

    public const EXPECTED_VARIANT_COUNT = 14991;

    public const EXPECTED_MATCHED_PRODUCT_COUNT = 221;

    public const EXPECTED_MATCHED_VARIANT_COUNT = 14964;

    public const EXPECTED_UNMATCHED_PRODUCT_COUNT = 5;

    public const EXPECTED_UNMATCHED_VARIANT_COUNT = 27;


    /** @var array<string, string> */
    private const EXPECTED_SOURCE_FINGERPRINTS = [
        'import_map' => SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256,
        'Cennik_Sigvaris_podstawowy_21.01.2026.pdf' => 'a5c41a3459ebf12d8f64f0a304089183a20d9b004e8fd931094485d8e196d2ed',
        'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf' => 'a7ed53461e54af8bfe050fd3954264b87ff53e82dfcaed1358c046909d06ebde',
        'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf' => '15ac7f125888476289b05a776724d25877c07c4d053cf87b8a7d9848d09e7de9',
        'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf' => '5b6f3989f03e915f5c0e816a9fda9a7667b2778ab5e20e8a8ae6be3ccce8ff2a',
        'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf' => 'e51e9756480f52c3266996caf1e297834e6410ce694757bede0944d149000c4f',
    ];

    /** @var list<string> */
    public const UNMATCHED_PRODUCT_IDS = [
        '28035',
        '106565',
        '27276',
        '27275',
        '27268',
    ];

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function preflight(array $plan): array
    {
        $errors = $this->validatePlan($plan);
        $catalogue = $this->catalogueSnapshot();

        $errors = array_merge($errors, $this->validateCatalogue($catalogue, $plan));

        $plannedChanges = 0;
        $alreadyCorrect = 0;
        $vatChanges = 0;

        if ($errors === []) {
            foreach ($this->planVariants($plan) as $row) {
                $variant = $catalogue['variants_by_external_id'][$row['external_variant_id']];

                if ($this->variantMatchesPlan($variant, $row)) {
                    $alreadyCorrect++;
                } else {
                    $plannedChanges++;
                }

                if ($variant->vat_rate?->value !== $row['vat_rate']) {
                    $vatChanges++;
                }
            }
        }

        return [
            'passed' => $errors === [],
            'errors' => $errors,
            'metrics' => [
                'catalogue_products' => $catalogue['product_count'],
                'catalogue_variants' => $catalogue['variant_count'],
                'matched_products' => (int) ($plan['matched_product_count'] ?? 0),
                'matched_variants' => (int) ($plan['matched_variant_count'] ?? 0),
                'unmatched_products' => (int) ($plan['unmatched_product_count'] ?? 0),
                'unmatched_variants' => (int) ($plan['unmatched_variant_count'] ?? 0),
                'variants_to_change' => $plannedChanges,
                'variants_already_correct' => $alreadyCorrect,
                'vat_changes' => $vatChanges,
            ],
        ];
    }

    public function cataloguePriceFingerprint(): string
    {
        $rows = ProductVariant::query()
            ->whereHas('product', fn ($query) => $query->where('external_source', 'sigvaris'))
            ->with('product:id,external_id')
            ->orderBy('external_variant_id')
            ->get()
            ->map(static fn (ProductVariant $variant): array => [
                'product_external_id' => (string) $variant->product->external_id,
                'external_variant_id' => (string) $variant->external_variant_id,
                'sku' => (string) $variant->sku,
                'price_net_amount' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                'price_gross_amount' => $variant->price_gross_amount === null ? null : (int) $variant->price_gross_amount,
                'vat_rate' => $variant->vat_rate?->value,
                'currency' => $variant->currency?->value,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function apply(array $plan, ?callable $progress = null): array
    {
        $preflight = $this->preflight($plan);

        if (! ($preflight['passed'] ?? false)) {
            throw new InvalidArgumentException('Sigvaris official price write preflight failed: '.implode(' | ', $preflight['errors'] ?? []));
        }

        $beforeUnmatched = $this->unmatchedSnapshot();
        $updated = 0;
        $unchanged = 0;
        $postAudit = null;

        DB::transaction(function () use ($plan, $beforeUnmatched, $progress, &$updated, &$unchanged, &$postAudit): void {
            $catalogue = $this->catalogueSnapshot();
            $rows = $this->planVariants($plan);
            $total = count($rows);
            $processed = 0;

            foreach ($rows as $row) {
                /** @var ProductVariant $variant */
                $variant = $catalogue['variants_by_external_id'][$row['external_variant_id']];

                $variant->forceFill([
                    'price_net_amount' => $row['selling_net_minor'],
                    'price_gross_amount' => $row['selling_gross_minor'],
                    'currency' => Currency::PLN,
                    'vat_rate' => VatRate::from($row['vat_rate']),
                ]);

                if (! $variant->isDirty()) {
                    $unchanged++;
                } else {
                    $variant->save();
                    $updated++;
                }

                $processed++;
                if ($progress !== null && ($processed === $total || $processed % 500 === 0)) {
                    $progress($processed, $total, $updated, $unchanged);
                }
            }

            $postAudit = $this->audit($plan, $beforeUnmatched);

            if (! ($postAudit['passed'] ?? false)) {
                throw new InvalidArgumentException('Sigvaris official price post-write audit failed: '.implode(' | ', $postAudit['errors'] ?? []));
            }
        });

        if (! is_array($postAudit)) {
            throw new InvalidArgumentException('Sigvaris official price post-write audit did not run.');
        }

        return [
            'passed' => true,
            'variants_updated' => $updated,
            'variants_unchanged' => $unchanged,
            'post_audit' => $postAudit,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, array{price_net_amount:?int,price_gross_amount:?int,vat_rate:?int,currency:?string}>|null $expectedUnmatchedSnapshot
     * @return array<string, mixed>
     */
    public function audit(array $plan, ?array $expectedUnmatchedSnapshot = null): array
    {
        $errors = $this->validatePlan($plan);
        $catalogue = $this->catalogueSnapshot();
        $errors = array_merge($errors, $this->validateCatalogue($catalogue, $plan));
        $matched = 0;

        if ($errors === []) {
            foreach ($this->planVariants($plan) as $row) {
                /** @var ProductVariant $variant */
                $variant = $catalogue['variants_by_external_id'][$row['external_variant_id']];

                if (! $this->variantMatchesPlan($variant, $row)) {
                    $errors[] = sprintf(
                        'Price mismatch for %s: expected net=%d gross=%d VAT=%d currency=PLN; actual net=%s gross=%s VAT=%s currency=%s.',
                        $row['external_variant_id'],
                        $row['selling_net_minor'],
                        $row['selling_gross_minor'],
                        $row['vat_rate'],
                        $variant->price_net_amount === null ? 'NULL' : (string) $variant->price_net_amount,
                        $variant->price_gross_amount === null ? 'NULL' : (string) $variant->price_gross_amount,
                        $variant->vat_rate?->value === null ? 'NULL' : (string) $variant->vat_rate->value,
                        $variant->currency?->value ?? 'NULL',
                    );
                    continue;
                }

                $matched++;
            }
        }

        $unmatchedSnapshot = $this->unmatchedSnapshot();

        if ($expectedUnmatchedSnapshot !== null && $unmatchedSnapshot !== $expectedUnmatchedSnapshot) {
            $errors[] = 'One or more explicitly unmatched Sigvaris variants changed during the price write.';
        }

        return [
            'passed' => $errors === [],
            'errors' => $errors,
            'metrics' => [
                'planned_variants_verified' => $matched,
                'planned_variants_expected' => self::EXPECTED_MATCHED_VARIANT_COUNT,
                'unmatched_variants_verified_unchanged' => $expectedUnmatchedSnapshot === null
                    ? null
                    : count($unmatchedSnapshot),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    private function validatePlan(array $plan): array
    {
        $errors = [];

        if (($plan['version'] ?? null) !== 1) {
            $errors[] = 'Price plan version must be 1.';
        }

        if (($plan['source'] ?? null) !== 'sigvaris') {
            $errors[] = 'Price plan source must be sigvaris.';
        }

        if (($plan['price_list_effective_date'] ?? null) !== '2026-01-21') {
            $errors[] = 'Price plan effective date must be 2026-01-21.';
        }

        if ((int) ($plan['markup_percent'] ?? -1) !== SigvarisOfficialPricePlanner::MARKUP_PERCENT) {
            $errors[] = 'Price plan markup must be 20 percent.';
        }

        if (($plan['database_writes'] ?? null) !== false) {
            $errors[] = 'Price plan must originate from the read-only planner.';
        }

        if (($plan['import_map_sha256'] ?? null) !== SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256) {
            $errors[] = 'Price plan import-map SHA-256 does not match the approved Sigvaris map.';
        }

        if (($plan['source_fingerprints']['import_map'] ?? null) !== SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256) {
            $errors[] = 'Price plan source fingerprint does not match the approved Sigvaris map.';
        }

        if (($plan['ready_for_price_write_implementation'] ?? null) !== false) {
            $errors[] = 'This partial price plan must explicitly remain not-ready for a full-catalogue price write.';
        }

        foreach (self::EXPECTED_SOURCE_FINGERPRINTS as $file => $expectedFingerprint) {
            if (($plan['source_fingerprints'][$file] ?? null) !== $expectedFingerprint) {
                $errors[] = 'Price plan source fingerprint mismatch for '.$file.'.';
            }
        }

        $expectedCounts = [
            'matched_product_count' => self::EXPECTED_MATCHED_PRODUCT_COUNT,
            'matched_variant_count' => self::EXPECTED_MATCHED_VARIANT_COUNT,
            'unmatched_product_count' => self::EXPECTED_UNMATCHED_PRODUCT_COUNT,
            'unmatched_variant_count' => self::EXPECTED_UNMATCHED_VARIANT_COUNT,
            'error_count' => 0,
        ];

        foreach ($expectedCounts as $key => $expected) {
            if ((int) ($plan[$key] ?? -1) !== $expected) {
                $errors[] = sprintf('Price plan %s must be %d.', $key, $expected);
            }
        }

        $unmatchedIds = [];

        foreach (($plan['unmatched_products'] ?? []) as $row) {
            if (is_array($row) && isset($row['external_id'])) {
                $unmatchedIds[] = (string) $row['external_id'];
            }
        }

        sort($unmatchedIds);
        $expectedUnmatchedIds = self::UNMATCHED_PRODUCT_IDS;
        sort($expectedUnmatchedIds);

        if ($unmatchedIds !== $expectedUnmatchedIds) {
            $errors[] = 'Price plan unmatched-product set is not the explicitly reviewed five-product exclusion set.';
        }

        $variants = $this->planVariants($plan, false);

        if (count($variants) !== self::EXPECTED_MATCHED_VARIANT_COUNT) {
            $errors[] = 'Price plan must contain exactly '.self::EXPECTED_MATCHED_VARIANT_COUNT.' variant rows.';
        }

        $externalVariantIds = [];
        $skus = [];

        foreach ($variants as $index => $row) {
            foreach (['product_external_id', 'external_variant_id', 'sku', 'base_net_minor', 'selling_net_minor', 'selling_gross_minor', 'vat_rate', 'currency', 'source_file', 'source_label'] as $required) {
                if (! array_key_exists($required, $row)) {
                    $errors[] = sprintf('Price plan variant row %d is missing %s.', $index, $required);
                    continue 2;
                }
            }

            $externalVariantId = trim((string) $row['external_variant_id']);
            $sku = trim((string) $row['sku']);
            $productExternalId = trim((string) $row['product_external_id']);
            $baseNet = filter_var($row['base_net_minor'], FILTER_VALIDATE_INT);
            $net = filter_var($row['selling_net_minor'], FILTER_VALIDATE_INT);
            $gross = filter_var($row['selling_gross_minor'], FILTER_VALIDATE_INT);
            $vat = filter_var($row['vat_rate'], FILTER_VALIDATE_INT);

            if ($externalVariantId === '' || $sku === '' || $productExternalId === '') {
                $errors[] = sprintf('Price plan variant row %d has blank identity fields.', $index);
            }

            if (! is_int($baseNet) || $baseNet <= 0 || ! is_int($net) || $net <= 0 || ! is_int($gross) || $gross <= 0) {
                $errors[] = sprintf('Price plan variant %s has an invalid base/selling price.', $externalVariantId);
            }

            if (! is_int($vat) || ! in_array($vat, [8, 23], true)) {
                $errors[] = sprintf('Price plan variant %s has unsupported VAT %s.', $externalVariantId, (string) $row['vat_rate']);
            }

            if (($row['currency'] ?? null) !== Currency::PLN->value) {
                $errors[] = sprintf('Price plan variant %s must use PLN.', $externalVariantId);
            }

            if (is_int($baseNet) && is_int($net) && is_int($gross) && is_int($vat)) {
                $expectedNet = (int) round($baseNet * 1.20);
                $expectedGross = (int) round($baseNet * (1 + ($vat / 100)) * 1.20);

                if ($net !== $expectedNet || $gross !== $expectedGross) {
                    $errors[] = sprintf(
                        'Price plan variant %s does not satisfy the approved formula: expected net=%d gross=%d; found net=%d gross=%d.',
                        $externalVariantId,
                        $expectedNet,
                        $expectedGross,
                        $net,
                        $gross,
                    );
                }
            }

            $sourceFile = trim((string) ($row['source_file'] ?? ''));
            if ($sourceFile === '' || trim((string) ($row['source_label'] ?? '')) === '') {
                $errors[] = sprintf('Price plan variant %s must identify its official price-list source.', $externalVariantId);
            } elseif (! array_key_exists($sourceFile, self::EXPECTED_SOURCE_FINGERPRINTS)) {
                $errors[] = sprintf('Price plan variant %s references an unapproved source file: %s.', $externalVariantId, $sourceFile);
            }

            if (isset($externalVariantIds[$externalVariantId])) {
                $errors[] = 'Duplicate external variant ID in price plan: '.$externalVariantId.'.';
            }

            if (isset($skus[$sku])) {
                $errors[] = 'Duplicate SKU in price plan: '.$sku.'.';
            }

            $externalVariantIds[$externalVariantId] = true;
            $skus[$sku] = true;
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $catalogue
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    private function validateCatalogue(array $catalogue, array $plan): array
    {
        $errors = [];

        if ($catalogue['product_count'] !== self::EXPECTED_PRODUCT_COUNT) {
            $errors[] = sprintf('Expected %d Sigvaris products; found %d.', self::EXPECTED_PRODUCT_COUNT, $catalogue['product_count']);
        }

        if ($catalogue['variant_count'] !== self::EXPECTED_VARIANT_COUNT) {
            $errors[] = sprintf('Expected %d Sigvaris variants; found %d.', self::EXPECTED_VARIANT_COUNT, $catalogue['variant_count']);
        }

        foreach ($catalogue['products'] as $product) {
            if ($product->status !== ProductStatus::DRAFT || $product->published_at !== null) {
                $errors[] = 'Sigvaris product '.$product->external_id.' is not an unpublished draft.';
            }
        }

        $plannedIds = [];

        foreach ($this->planVariants($plan, false) as $row) {
            $externalVariantId = trim((string) ($row['external_variant_id'] ?? ''));
            $productExternalId = trim((string) ($row['product_external_id'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? ''));
            $plannedIds[$externalVariantId] = true;
            $variant = $catalogue['variants_by_external_id'][$externalVariantId] ?? null;

            if (! $variant instanceof ProductVariant) {
                $errors[] = 'Planned Sigvaris variant is missing from the database: '.$externalVariantId.'.';
                continue;
            }

            if ((string) $variant->product->external_id !== $productExternalId) {
                $errors[] = 'Planned variant '.$externalVariantId.' belongs to unexpected product '.$variant->product->external_id.'.';
            }

            if ((string) $variant->sku !== $sku) {
                $errors[] = 'SKU mismatch for '.$externalVariantId.': expected '.$sku.', found '.($variant->sku ?? 'NULL').'.';
            }
        }

        $unplannedIds = [];
        foreach ($catalogue['variants_by_external_id'] as $externalVariantId => $variant) {
            if (isset($plannedIds[$externalVariantId])) {
                continue;
            }

            $productExternalId = (string) $variant->product->external_id;
            if (! in_array($productExternalId, self::UNMATCHED_PRODUCT_IDS, true)) {
                $errors[] = 'Database variant '.$externalVariantId.' is not in the price plan and does not belong to an explicitly unmatched product.';
                continue;
            }

            $unplannedIds[] = $externalVariantId;
        }

        if (count($unplannedIds) !== self::EXPECTED_UNMATCHED_VARIANT_COUNT) {
            $errors[] = sprintf('Expected exactly %d explicitly unmatched database variants; found %d.', self::EXPECTED_UNMATCHED_VARIANT_COUNT, count($unplannedIds));
        }

        foreach (self::UNMATCHED_PRODUCT_IDS as $externalId) {
            if (! $catalogue['products']->contains(fn (Product $product): bool => (string) $product->external_id === $externalId)) {
                $errors[] = 'Explicitly unmatched Sigvaris product is missing from the database: '.$externalId.'.';
            }
        }

        return $errors;
    }

    /**
     * @return array{products:\Illuminate\Support\Collection<int, Product>,product_count:int,variant_count:int,variants_by_external_id:array<string, ProductVariant>}
     */
    private function catalogueSnapshot(): array
    {
        $products = Product::query()
            ->where('external_source', 'sigvaris')
            ->with(['variants', 'variants.product'])
            ->orderBy('id')
            ->get();
        $variantsByExternalId = [];
        $variantCount = 0;

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $variantCount++;
                $externalVariantId = trim((string) ($variant->external_variant_id ?? ''));

                if ($externalVariantId !== '') {
                    $variantsByExternalId[$externalVariantId] = $variant;
                }
            }
        }

        return [
            'products' => $products,
            'product_count' => $products->count(),
            'variant_count' => $variantCount,
            'variants_by_external_id' => $variantsByExternalId,
        ];
    }

    /**
     * @return array<string, array{price_net_amount:?int,price_gross_amount:?int,vat_rate:?int,currency:?string}>
     */
    private function unmatchedSnapshot(): array
    {
        $rows = ProductVariant::query()
            ->whereHas('product', fn ($query) => $query
                ->where('external_source', 'sigvaris')
                ->whereIn('external_id', self::UNMATCHED_PRODUCT_IDS))
            ->orderBy('external_variant_id')
            ->get();
        $snapshot = [];

        foreach ($rows as $variant) {
            $snapshot[(string) $variant->external_variant_id] = [
                'price_net_amount' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                'price_gross_amount' => $variant->price_gross_amount === null ? null : (int) $variant->price_gross_amount,
                'vat_rate' => $variant->vat_rate?->value,
                'currency' => $variant->currency?->value,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<array{product_external_id:string,external_variant_id:string,sku:string,base_net_minor:int,selling_net_minor:int,selling_gross_minor:int,vat_rate:int,currency:string,source_file:string,source_label:string}>
     */
    private function planVariants(array $plan, bool $strict = true): array
    {
        $rows = [];

        foreach (($plan['variants'] ?? []) as $row) {
            if (! is_array($row)) {
                if ($strict) {
                    throw new InvalidArgumentException('Price plan variants must contain only objects.');
                }
                continue;
            }

            $rows[] = [
                'product_external_id' => trim((string) ($row['product_external_id'] ?? '')),
                'external_variant_id' => trim((string) ($row['external_variant_id'] ?? '')),
                'sku' => trim((string) ($row['sku'] ?? '')),
                'base_net_minor' => (int) ($row['base_net_minor'] ?? 0),
                'selling_net_minor' => (int) ($row['selling_net_minor'] ?? 0),
                'selling_gross_minor' => (int) ($row['selling_gross_minor'] ?? 0),
                'vat_rate' => (int) ($row['vat_rate'] ?? 0),
                'currency' => (string) ($row['currency'] ?? ''),
                'source_file' => (string) ($row['source_file'] ?? ''),
                'source_label' => (string) ($row['source_label'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array{selling_net_minor:int,selling_gross_minor:int,vat_rate:int,currency:string} $row
     */
    private function variantMatchesPlan(ProductVariant $variant, array $row): bool
    {
        return (int) $variant->price_net_amount === $row['selling_net_minor']
            && (int) $variant->price_gross_amount === $row['selling_gross_minor']
            && $variant->vat_rate?->value === $row['vat_rate']
            && $variant->currency?->value === $row['currency'];
    }
}
