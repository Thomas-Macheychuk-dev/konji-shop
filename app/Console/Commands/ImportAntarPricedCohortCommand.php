<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Antar\AntarProductImporter;
use App\Services\Antar\AntarSelectivePricedImportPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class ImportAntarPricedCohortCommand extends Command
{
    private const APPROVED_SOURCE_PRODUCT_DATA_SHA256 = '8c8859c3c795ab811d1e90f2162f18f2d8069ac027458c6ba3a112f49676b76d';

    private const APPROVED_SOURCE_PRODUCTS = 625;

    private const APPROVED_ELIGIBLE_PRODUCTS = 519;

    private const APPROVED_MANUAL_REVIEW_PRODUCTS = 9;

    private const APPROVED_EXCLUDED_PRODUCTS = 97;

    protected $signature = 'antar:import-priced
        {--from=scrapers/antar/product-data.json : Frozen Antar product-data JSON on the Laravel local disk.}
        {--reconciliation=scrapers/antar/price-reconciliation-2026-09-01.json : Frozen price reconciliation JSON on the Laravel local disk.}
        {--execute : Write the validated eligible priced cohort as draft products. Without this flag the command is read-only.}
        {--limit= : Maximum number of eligible products to import in this execution.}
        {--offset=0 : Number of eligible products to skip before importing.}
        {--no-images : Do not download or sync product images.}
        {--no-documents : Do not download Antar product documents; render document labels as plain text.}
        {--image-limit=10 : Maximum number of images to import per product. Use 0 for no limit.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Preflight or locally import only the frozen deterministic Antar priced cohort as draft products.';

    public function __construct(
        private readonly AntarSelectivePricedImportPlan $planner,
        private readonly AntarProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $this->info('Antar selective priced draft import');
        $this->line('Database writes: '.($execute ? 'REQUESTED' : 'NO'));
        $this->line('Product status: draft (forced)');
        $this->line('Full raw-catalogue import: DISABLED');

        try {
            $sourcePath = $this->relativePath((string) $this->option('from'));
            $reconciliationPath = $this->relativePath((string) $this->option('reconciliation'));
            $disk = Storage::disk('local');

            if (! $disk->exists($sourcePath)) {
                throw new RuntimeException('Antar product-data file not found on local disk: '.$sourcePath);
            }

            if (! $disk->exists($reconciliationPath)) {
                throw new RuntimeException('Antar price reconciliation file not found on local disk: '.$reconciliationPath);
            }

            $sourceRaw = $disk->get($sourcePath);
            $reconciliationRaw = $disk->get($reconciliationPath);
            $sourceSha256 = hash('sha256', $sourceRaw);
            $reconciliationSha256 = hash('sha256', $reconciliationRaw);
            if (! app()->environment('testing') && ! hash_equals(self::APPROVED_SOURCE_PRODUCT_DATA_SHA256, $sourceSha256)) {
                throw new RuntimeException(
                    'Antar product-data SHA-256 is not the approved 625-product crawl for this import patch. Expected '.self::APPROVED_SOURCE_PRODUCT_DATA_SHA256.', actual '.$sourceSha256.'.',
                );
            }

            $source = $this->decodeJsonObject($sourceRaw, 'Antar product-data');
            $savedReconciliation = $this->decodeJsonObject($reconciliationRaw, 'Antar price reconciliation');
            $plan = $this->planner->build($source, $sourceSha256, $savedReconciliation, $reconciliationSha256);

            if (! app()->environment('testing')) {
                $this->assertApprovedBatchSummary((array) ($plan['summary'] ?? []));
            }
        } catch (\Throwable $exception) {
            $this->error('Unable to build Antar selective priced import plan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = (array) ($plan['summary'] ?? []);
        $supplier = (array) ($plan['supplier_price_list'] ?? []);

        $this->line('Source product data: '.$sourcePath);
        $this->line('Source product-data SHA-256: '.$sourceSha256);
        $this->line('Price reconciliation: '.$reconciliationPath);
        $this->line('Price reconciliation SHA-256: '.$reconciliationSha256);
        $this->line('Supplier source: '.($supplier['source_file'] ?? 'unknown'));
        $this->line('Supplier effective from: '.($supplier['effective_from'] ?? 'unknown'));
        $this->line('Source products: '.($summary['source_products'] ?? 0));
        $this->line('Eligible priced products: '.($summary['eligible_priced_products'] ?? 0));
        $this->line('Manual price review retained outside import: '.($summary['manual_price_review'] ?? 0));
        $this->line('Excluded unpriced products retained outside import: '.($summary['excluded_unpriced_products'] ?? 0));
        $this->line('Eligible VAT breakdown: '.$this->vatBreakdown((array) ($summary['eligible_vat_breakdown'] ?? [])));
        $this->line('Existing Antar products: '.($summary['existing_antar_products'] ?? 0));
        $this->line('Existing Antar products outside eligible cohort: '.($summary['existing_antar_products_outside_eligible_cohort'] ?? 0));
        $this->line('Eligible source-SKU collisions with other sources: '.($summary['stored_sku_collisions_with_other_sources'] ?? 0).' (resolved with Antar-namespaced storage SKUs)');
        $this->line('Duplicate source-SKU groups resolved: '.($summary['duplicate_source_sku_groups_resolved'] ?? 0));
        $this->line('Namespaced storage SKUs: '.($summary['namespaced_storage_skus'] ?? 0));
        $this->line('Hard preflight errors: '.($summary['hard_errors'] ?? 0));
        $this->line('Ready for local draft import: '.(($plan['ready_for_local_draft_import'] ?? false) ? 'YES' : 'NO'));

        if (($plan['hard_errors'] ?? []) !== []) {
            $this->newLine();
            $this->error('Hard preflight errors:');

            foreach ((array) $plan['hard_errors'] as $error) {
                $this->line('- '.(string) $error);
            }
        }

        if (! ($plan['ready_for_local_draft_import'] ?? false)) {
            $this->error('FAIL: selective Antar import is not ready. No database writes were performed.');

            return self::FAILURE;
        }

        $rows = array_values(array_filter((array) ($plan['eligible_import_rows'] ?? []), 'is_array'));
        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selected = array_slice($rows, $offset, $limit);

        $this->line('Offset within eligible cohort: '.$offset);
        $this->line('Selected eligible products: '.count($selected));

        if (! $execute) {
            $this->line('Network requests: NO');
            $this->info('PASS: selective priced import preflight succeeded. No database writes or network requests were performed.');

            return self::SUCCESS;
        }

        if (! app()->environment('local', 'testing')) {
            $this->error('FAIL: --execute is restricted to local/testing environments. Use a separate reviewed production-import stage for production.');

            return self::FAILURE;
        }

        $importImages = ! (bool) $this->option('no-images');
        $importDocuments = ! (bool) $this->option('no-documents');
        $imageLimit = $this->imageLimitOption();
        $networkRequests = $importImages || $importDocuments;

        $this->line('Database writes: YES — eligible priced cohort only');
        $this->line('Network requests: '.($networkRequests ? 'YES — approved Antar assets only' : 'NO'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Documents: '.($importDocuments ? 'download and localize' : 'skipped / plain text'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));

        $imported = 0;
        $failures = [];
        $warnings = [];
        $total = count($selected);

        foreach ($selected as $index => $row) {
            $productData = $row['product'] ?? null;
            $approvedPricing = $row['approved_pricing'] ?? null;

            if (! is_array($productData) || ! is_array($approvedPricing)) {
                $failures[] = [
                    'name' => (string) ($row['name'] ?? '[unknown Antar product]'),
                    'url' => $row['source_url'] ?? null,
                    'error' => 'Validated import row lost its source product or approved pricing payload.',
                ];

                continue;
            }

            $name = (string) ($row['name'] ?? $productData['name'] ?? '[unnamed Antar product]');
            $sourceUrl = is_string($row['source_url'] ?? null) ? $row['source_url'] : null;
            $supplierSku = (string) ($row['supplier_price_code'] ?? '');
            $catalogueSku = (string) ($row['approved_catalogue_sku'] ?? '');
            $storageSku = (string) ($row['storage_sku'] ?? '');

            $this->line(sprintf('Importing eligible product %d/%d: %s', $index + 1, $total, $name));
            $this->line(sprintf(
                '  Price code %s | catalogue SKU %s | storage SKU %s | net %s | gross %s | VAT %s%%',
                $supplierSku,
                $catalogueSku,
                $storageSku,
                $this->money($approvedPricing['price_net_amount'] ?? null),
                $this->money($approvedPricing['price_gross_amount'] ?? null),
                (string) ($approvedPricing['vat_rate'] ?? ''),
            ));

            try {
                $result = $this->importer->import(
                    $productData,
                    ProductStatus::DRAFT,
                    $importImages,
                    $imageLimit,
                    $importDocuments,
                    $approvedPricing,
                    $catalogueSku,
                    $storageSku,
                );
                $product = $result['product'];
                $variant = $product->variants->first();
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = ['product' => $name, 'warning' => $warning];
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf(
                    '  Imported product ID %d | variant ID %s | SKU %s | gross %s PLN',
                    $product->id,
                    $variant?->id === null ? 'none' : (string) $variant->id,
                    $variant?->sku ?? 'NO-SKU',
                    $this->money($variant?->price_gross_amount),
                ));
            } catch (\Throwable $exception) {
                $failures[] = [
                    'name' => $name,
                    'url' => $sourceUrl,
                    'error' => $exception->getMessage(),
                ];
                $this->error('  Failed: '.$exception->getMessage());
            }
        }

        $this->info('Imported eligible Antar products: '.$imported);
        $this->line('Warnings: '.count($warnings));
        $this->line('Failures: '.count($failures));

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->newLine();
            $this->warn('Failed eligible Antar imports:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' — '.($failure['url'] ?? '[missing url]').' — '.$failure['error']);
            }
        }

        if ($failures !== []) {
            $this->error('FAIL: one or more eligible Antar draft imports failed.');

            return self::FAILURE;
        }

        $this->info('PASS: selected eligible Antar products were imported as drafts with frozen approved supplier pricing.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function assertApprovedBatchSummary(array $summary): void
    {
        $checks = [
            'source_products' => self::APPROVED_SOURCE_PRODUCTS,
            'eligible_priced_products' => self::APPROVED_ELIGIBLE_PRODUCTS,
            'manual_price_review' => self::APPROVED_MANUAL_REVIEW_PRODUCTS,
            'excluded_unpriced_products' => self::APPROVED_EXCLUDED_PRODUCTS,
        ];

        foreach ($checks as $key => $expected) {
            if ((int) ($summary[$key] ?? -1) !== $expected) {
                throw new RuntimeException(sprintf(
                    'Antar approved batch invariant mismatch for %s: expected %d, actual %d.',
                    $key,
                    $expected,
                    (int) ($summary[$key] ?? -1),
                ));
            }
        }
    }

    private function relativePath(string $value): string
    {
        $value = ltrim(trim(str_replace('\\', '/', $value)), '/');

        if ($value === '' || str_contains($value, '../') || str_starts_with($value, '..')) {
            throw new RuntimeException('Path must be a safe relative path on the Laravel local disk.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $raw, string $label): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label.' JSON is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($label.' JSON must decode to an object.');
        }

        return $decoded;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function nullablePositiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function imageLimitOption(): ?int
    {
        $value = $this->option('image-limit');

        if (! is_string($value) || trim($value) === '') {
            return 10;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    /** @param array<int|string, mixed> $breakdown */
    private function vatBreakdown(array $breakdown): string
    {
        if ($breakdown === []) {
            return 'none';
        }

        $parts = [];

        foreach ($breakdown as $rate => $count) {
            $parts[] = $rate.'%='.((int) $count);
        }

        return implode(', ', $parts);
    }

    private function money(mixed $minor): string
    {
        if (! is_numeric($minor)) {
            return 'null';
        }

        return number_format(((int) $minor) / 100, 2, '.', '');
    }
}
