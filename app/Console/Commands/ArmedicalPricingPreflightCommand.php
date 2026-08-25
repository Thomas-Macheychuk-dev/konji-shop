<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Armedical\ArmedicalPricingPreflight;
use App\Services\Armedical\ArmedicalSupplierPriceList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class ArmedicalPricingPreflightCommand extends Command
{
    private const FROZEN_V3_SHA256 = '05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8';

    protected $signature = 'armedical:pricing-preflight
        {--from=scrapers/armedical/import-map.json : ARmedical import-map JSON on the local filesystem disk.}
        {--price-list-json= : Optional canonical supplier price reference JSON path; default uses the bundled frozen 2026 reference.}
        {--expected-catalogue-sha256=05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8 : Expected frozen v3 catalogue SHA-256; empty disables this gate.}
        {--expected-supplier-xls-sha256=ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893 : Expected supplier XLS SHA-256; empty disables this gate.}
        {--expected-products=200 : Expected mapped product count.}
        {--expected-variants=506 : Expected planned variant count.}
        {--expected-matched=459 : Expected matched price/VAT variant count.}
        {--expected-unmatched=47 : Expected unmatched price/VAT variant count.}
        {--save=scrapers/armedical/import-map-priced.json : Save priced mapping JSON on the local filesystem disk.}
        {--json : Print the complete priced mapping as JSON.}
        {--show-unmatched : Print products/variants that remain without a deterministic supplier price code.}
        {--show-review : Print review and blocking-review items.}';

    protected $description = 'Apply frozen ARmedical 2026 supplier net prices and per-code VAT to the import map without database writes.';

    public function __construct(
        private readonly ArmedicalPricingPreflight $preflight,
        private readonly ArmedicalSupplierPriceList $supplierPriceList,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $relativePath = ltrim(trim((string) $this->option('from')), '/');
        $raw = $this->loadRawJson($relativePath);
        $importMap = $this->decodeImportMap($raw, $relativePath);
        $priceListPath = $this->priceListPath();
        $priceList = $this->supplierPriceList->load($priceListPath);
        $result = $this->preflight->apply($importMap, $priceList);

        $mapSha = hash('sha256', $raw);
        $expectedCatalogueSha = strtolower(trim((string) $this->option('expected-catalogue-sha256')));
        $catalogueSha = strtolower(trim((string) ($importMap['input_fingerprint']['sha256'] ?? '')));
        $expectedSupplierSha = strtolower(trim((string) $this->option('expected-supplier-xls-sha256')));
        $supplierSha = strtolower(trim((string) ($priceList['metadata']['source_sha256'] ?? '')));

        $result['pricing_input_fingerprint'] = [
            'import_map_sha256' => $mapSha,
            'catalogue_sha256' => $catalogueSha !== '' ? $catalogueSha : null,
            'expected_catalogue_sha256' => $expectedCatalogueSha !== '' ? $expectedCatalogueSha : null,
            'catalogue_matches_expected' => $expectedCatalogueSha === '' || hash_equals($expectedCatalogueSha, $catalogueSha),
            'supplier_xls_sha256' => $supplierSha !== '' ? $supplierSha : null,
            'expected_supplier_xls_sha256' => $expectedSupplierSha !== '' ? $expectedSupplierSha : null,
            'supplier_xls_matches_expected' => $expectedSupplierSha === '' || hash_equals($expectedSupplierSha, $supplierSha),
            'frozen_v3_sha256' => self::FROZEN_V3_SHA256,
        ];

        $this->applyGate(
            $result,
            'mapped products',
            (int) ($result['mapped_product_count'] ?? 0),
            max(0, (int) $this->option('expected-products')),
        );
        $this->applyGate(
            $result,
            'planned variants',
            (int) ($result['pricing_summary']['planned_variants'] ?? 0),
            max(0, (int) $this->option('expected-variants')),
        );
        $this->applyGate(
            $result,
            'matched price/VAT variants',
            (int) ($result['pricing_summary']['matched_variants'] ?? 0),
            max(0, (int) $this->option('expected-matched')),
        );
        $this->applyGate(
            $result,
            'unmatched price/VAT variants',
            (int) ($result['pricing_summary']['unmatched_variants'] ?? 0),
            max(0, (int) $this->option('expected-unmatched')),
        );

        if ($expectedCatalogueSha !== '' && ! hash_equals($expectedCatalogueSha, $catalogueSha)) {
            $result['errors'][] = 'Frozen catalogue SHA-256 mismatch: expected '.$expectedCatalogueSha.', actual '.($catalogueSha ?: 'missing').'.';
        }

        if ($expectedSupplierSha !== '' && ! hash_equals($expectedSupplierSha, $supplierSha)) {
            $result['errors'][] = 'Supplier XLS SHA-256 mismatch: expected '.$expectedSupplierSha.', actual '.($supplierSha ?: 'missing').'.';
        }

        $result['errors'] = array_values(array_unique($result['errors'] ?? []));
        $result['pricing_structurally_valid'] = ($result['mapped_product_count'] ?? 0) > 0 && $result['errors'] === [];
        $result['ready_for_local_import_implementation'] = ($result['pricing_structurally_valid'] ?? false) === true
            && ($result['blocking_review_items'] ?? []) === []
            && (($result['pricing_summary']['unmatched_variants'] ?? 0) === 0);
        $result['ready_for_database_write'] = $result['ready_for_local_import_implementation'];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result, Storage::disk('local')->path($relativePath));
        }

        $save = trim((string) $this->option('save'));
        if ($save !== '') {
            $this->saveJson($save, $result, (bool) $this->option('json'));
        }

        return ($result['pricing_structurally_valid'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function priceListPath(): ?string
    {
        $path = trim((string) $this->option('price-list-json'));

        return $path === '' ? null : $path;
    }

    private function loadRawJson(string $relativePath): string
    {
        if ($relativePath === '' || ! Storage::disk('local')->exists($relativePath)) {
            throw new RuntimeException('ARmedical import-map JSON not found on local filesystem disk: '.$relativePath);
        }

        return Storage::disk('local')->get($relativePath);
    }

    /** @return array<string, mixed> */
    private function decodeImportMap(string $raw, string $relativePath): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid ARmedical import-map JSON at '.$relativePath.': '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'armedical' || ! is_array($decoded['products'] ?? null)) {
            throw new RuntimeException('ARmedical import-map JSON must contain source="armedical" and a products array.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private function applyGate(array &$result, string $label, int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            $result['errors'][] = 'Frozen '.$label.' mismatch: expected '.$expected.', actual '.$actual.'.';
        }
    }

    /** @param array<string, mixed> $result */
    private function printSummary(array $result, string $sourcePath): void
    {
        $pricing = is_array($result['pricing_summary'] ?? null) ? $result['pricing_summary'] : [];
        $priceList = is_array($result['supplier_price_list'] ?? null) ? $result['supplier_price_list'] : [];
        $priceMeta = is_array($priceList['metadata'] ?? null) ? $priceList['metadata'] : [];
        $priceSummary = is_array($priceList['summary'] ?? null) ? $priceList['summary'] : [];
        $fingerprint = is_array($result['pricing_input_fingerprint'] ?? null) ? $result['pricing_input_fingerprint'] : [];
        $vat = is_array($pricing['vat_variant_breakdown'] ?? null) ? $pricing['vat_variant_breakdown'] : [];
        $strategies = is_array($pricing['match_strategy_breakdown'] ?? null) ? $pricing['match_strategy_breakdown'] : [];

        $this->info('ARmedical price/VAT mapping preflight');
        $this->line('Import map: '.$sourcePath);
        $this->line('Import map SHA-256: '.($fingerprint['import_map_sha256'] ?? '?'));
        $this->line('Frozen catalogue SHA gate: '.(($fingerprint['catalogue_matches_expected'] ?? false) ? 'PASS' : 'FAIL'));
        $this->line('Supplier price source: '.($priceMeta['source_file'] ?? '?'));
        $this->line('Supplier price effective from: '.($priceMeta['effective_from'] ?? '?'));
        $this->line('Supplier XLS SHA-256: '.($priceMeta['source_sha256'] ?? '?'));
        $this->line('Supplier XLS SHA gate: '.(($fingerprint['supplier_xls_matches_expected'] ?? false) ? 'PASS' : 'FAIL'));
        $this->line('Supplier price column: '.($priceMeta['price_column'] ?? '?'));
        $this->line('Supplier VAT column: '.($priceMeta['vat_column'] ?? '?'));
        $this->line('Promotional price column used: NO');
        $this->line('Supplier price rows: '.($priceSummary['rows'] ?? 0));
        $this->line('Supplier unique price codes: '.($priceSummary['unique_codes'] ?? 0));
        $this->line('Supplier VAT rows: 8%='.(($priceSummary['vat_row_breakdown'][8] ?? 0)).', 23%='.(($priceSummary['vat_row_breakdown'][23] ?? 0)));
        $this->line('Database writes: NO');
        $this->line('Images downloaded: NO');
        $this->line('Mapped products: '.($result['mapped_product_count'] ?? 0));
        $this->line('Planned variants: '.($pricing['planned_variants'] ?? 0));
        $this->line('Price/VAT matched variants: '.($pricing['matched_variants'] ?? 0));
        $this->line('Price/VAT unmatched variants: '.($pricing['unmatched_variants'] ?? 0));
        $this->line('Fully priced products: '.($pricing['fully_priced_products'] ?? 0));
        $this->line('Products with unresolved pricing: '.($pricing['unpriced_products'] ?? 0));
        $this->line('Matched variants at 8% VAT: '.($vat[8] ?? 0));
        $this->line('Matched variants at 23% VAT: '.($vat[23] ?? 0));
        $this->line('Variant-code matches: '.($strategies['variant_code'] ?? 0));
        $this->line('Parent-code matches: '.($strategies['parent_code'] ?? 0));
        $this->line('Explicit alias matches: '.count($pricing['explicit_alias_matches'] ?? []));
        $this->line('Hard pricing errors: '.count($result['errors'] ?? []));
        $this->line('Existing blocking review items: '.count($result['blocking_review_items'] ?? []));
        $this->line('Ready for database write: '.(($result['ready_for_database_write'] ?? false) ? 'YES' : 'NO'));

        if ((bool) $this->option('show-unmatched') && ($result['unmatched_products'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Unmatched supplier pricing:');

            foreach ($result['unmatched_products'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $candidates = is_array($item['candidate_price_codes'] ?? null) ? $item['candidate_price_codes'] : [];
                $this->line(sprintf(
                    '- %s | %s | unmatched variants=%d%s',
                    $item['catalogue_number'] ?? 'missing-code',
                    $item['name'] ?? 'Unnamed ARmedical product',
                    (int) ($item['unmatched_variant_count'] ?? 0),
                    $candidates !== [] ? ' | related supplier codes='.implode(', ', $candidates) : '',
                ));
            }
        }

        if ((bool) $this->option('show-review')) {
            if (($result['blocking_review_items'] ?? []) !== []) {
                $this->newLine();
                $this->warn('Blocking review items:');
                foreach ($result['blocking_review_items'] as $item) {
                    $this->line('- '.$item);
                }
            }

            if (($result['review_items'] ?? []) !== []) {
                $this->newLine();
                $this->warn('Review items:');
                foreach ($result['review_items'] as $item) {
                    $this->line('- '.$item);
                }
            }
        }

        $this->newLine();
        if (($result['pricing_structurally_valid'] ?? false) !== true) {
            $this->error('FAIL: resolve hard price/VAT mapping errors before continuing.');
        } elseif (($result['ready_for_database_write'] ?? false) === true) {
            $this->info('PASS: supplier price/VAT mapping is complete and no blocking review items remain.');
        } else {
            $this->warn('PASS WITH REVIEW: deterministic supplier prices/VAT were applied where possible; database writes remain disabled and unresolved rows are preserved for review.');
        }
    }

    private function saveJson(string $relativePath, array $result, bool $quiet): void
    {
        $relativePath = ltrim(trim($relativePath), '/');

        if ($relativePath === '') {
            throw new RuntimeException('ARmedical priced import-map save path cannot be empty.');
        }

        Storage::disk('local')->put($relativePath, $this->encode($result));

        if (! $quiet) {
            $this->info('Saved priced import mapping to '.Storage::disk('local')->path($relativePath));
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode ARmedical priced import mapping JSON.');
        }

        return $encoded;
    }
}
