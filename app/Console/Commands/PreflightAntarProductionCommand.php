<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Antar\AntarProductionPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class PreflightAntarProductionCommand extends Command
{
    private const APPROVED_SOURCE_SHA256 = '8c8859c3c795ab811d1e90f2162f18f2d8069ac027458c6ba3a112f49676b76d';

    private const APPROVED_RECONCILIATION_SHA256 = 'a817d425e06771174aad7c7ac1da4179390232a93f05ff07bd08de60279207ee';

    protected $signature = 'antar:production-preflight
        {--from=scrapers/antar/product-data.json : Approved frozen 625-product Antar crawl on the Laravel local disk.}
        {--reconciliation=scrapers/antar/price-reconciliation-2026-09-01.json : Approved frozen 519-product pricing reconciliation on the Laravel local disk.}
        {--minimum-free-mib=512 : Minimum free space required on public storage.}
        {--allow-non-production : Permit a local/staging rehearsal against a matching production-topology fixture.}
        {--save= : Optional JSON evidence path on the Laravel local disk. No report is written unless supplied.}
        {--show-checks : Print every production topology check.}';

    protected $description = 'Run the SHA-pinned, read-only Antar production reconciliation preflight. Performs no catalogue, filesystem, or network writes.';

    public function __construct(
        private readonly AntarProductionPreflight $preflight,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: antar:production-preflight is intended for production. Use --allow-non-production only for a controlled rehearsal.');

            return self::FAILURE;
        }

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

            if (! hash_equals(self::APPROVED_SOURCE_SHA256, $sourceSha256)) {
                throw new RuntimeException('Antar product-data SHA-256 mismatch. Expected '.self::APPROVED_SOURCE_SHA256.', actual '.$sourceSha256.'.');
            }

            if (! hash_equals(self::APPROVED_RECONCILIATION_SHA256, $reconciliationSha256)) {
                throw new RuntimeException('Antar reconciliation SHA-256 mismatch. Expected '.self::APPROVED_RECONCILIATION_SHA256.', actual '.$reconciliationSha256.'.');
            }

            $source = $this->decodeJsonObject($sourceRaw, 'Antar product-data');
            $reconciliation = $this->decodeJsonObject($reconciliationRaw, 'Antar price reconciliation');
            $report = $this->preflight->inspect(
                source: $source,
                sourceSha256: $sourceSha256,
                savedReconciliation: $reconciliation,
                savedReconciliationSha256: $reconciliationSha256,
                expected: $this->expectedTopology(),
            );
        } catch (\Throwable $exception) {
            $this->error('Unable to run Antar production preflight: '.$exception->getMessage());

            return self::FAILURE;
        }

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $errors = array_values(array_filter($report['errors'] ?? [], 'is_string'));

        $this->info('Antar production reconciliation preflight');
        $this->line('Environment: '.app()->environment());
        $this->line('Source product data: '.$sourcePath);
        $this->line('Source product-data SHA-256: '.$sourceSha256);
        $this->line('Price reconciliation: '.$reconciliationPath);
        $this->line('Price reconciliation SHA-256: '.$reconciliationSha256);
        $this->line('Database writes: NO');
        $this->line('Catalogue/filesystem asset writes: NO');
        $this->line('Network requests: NO');
        $this->line('Evidence report write: '.($this->nullableString($this->option('save')) === null ? 'NO' : 'REQUESTED'));
        $this->newLine();
        $this->line('Current source products: '.($metrics['source_products'] ?? 0));
        $this->line('Approved priced products: '.($metrics['approved_products'] ?? 0));
        $this->line('Production Antar products: '.($metrics['production_products'] ?? 0));
        $this->line('Production Antar variants: '.($metrics['production_variants'] ?? 0));
        $this->line('Production live Antar products: '.($metrics['production_live_products'] ?? 0));
        $this->line('Production live Antar variants: '.($metrics['production_live_variants'] ?? 0));
        $this->line('Production Antar drafts: '.($metrics['production_drafts'] ?? 0));
        $this->line('Production Antar variant drafts: '.($metrics['production_variant_drafts'] ?? 0));
        $this->line('Baseline unpriced variants: '.($metrics['production_unpriced_variants'] ?? 0));
        $this->line('Approved exact-ID updates: '.($metrics['approved_exact_existing'] ?? 0));
        $this->line('Approved current IDs absent before reconciliation: '.($metrics['approved_missing_current_ids'] ?? 0));
        $this->line('Current non-approved products already present: '.($metrics['current_non_approved_existing'] ?? 0));
        $this->line('Existing Antar rows outside approved cohort: '.($metrics['existing_outside_approved'] ?? 0));
        $this->line('Cross-source base-SKU collisions: '.($metrics['cross_source_base_sku_collisions'] ?? 0));
        $this->line('Duplicate Antar source-SKU groups: '.($metrics['duplicate_source_sku_groups'] ?? 0));
        $this->line('Resolved namespaced storage SKUs: '.($metrics['namespaced_storage_skus'] ?? 0));
        $this->line('Reviewed rescue files: '.($metrics['reviewed_rescue_files'] ?? 0));
        $this->line('Missing reviewed rescue files: '.($metrics['missing_reviewed_rescue_files'] ?? 0));
        $this->line('Public storage free MiB: '.(($metrics['public_storage_free_mib'] ?? null) === null ? 'unknown' : (string) $metrics['public_storage_free_mib']));
        $this->line('Hard preflight errors: '.count($errors));

        if ((bool) $this->option('show-checks') || $errors !== []) {
            $this->newLine();
            $this->info('Production topology checks:');
            foreach (($report['checks'] ?? []) as $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->line(sprintf(
                    '- [%s] %s | expected=%s actual=%s',
                    (string) ($check['status'] ?? '?'),
                    (string) ($check['name'] ?? '?'),
                    $this->displayValue($check['expected'] ?? null),
                    $this->displayValue($check['actual'] ?? null),
                ));
            }
        }

        if ($errors !== []) {
            $this->newLine();
            $this->error('Hard preflight errors:');
            foreach ($errors as $error) {
                $this->line('- '.$error);
            }
        }

        if (($save = $this->nullableString($this->option('save'))) !== null) {
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $disk->put($this->relativePath($save), $payload);
            $this->line('Saved preflight evidence: '.$save);
        }

        if (($report['ready_for_production_reconciliation'] ?? false) === true && $errors === []) {
            $this->info('PASS: Antar production reconciliation topology is exactly approved. No writes or network requests were performed.');

            return self::SUCCESS;
        }

        $this->error('FAIL: Antar production topology has drifted. Do not enable production catalogue writes.');

        return self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function expectedTopology(): array
    {
        return [
            'source_products' => 625,
            'approved_products' => 519,
            'production_products' => 622,
            'production_variants' => 622,
            'production_live_products' => 622,
            'production_live_variants' => 622,
            'production_drafts' => 622,
            'production_variant_drafts' => 622,
            'production_unpriced_variants' => 622,
            'approved_exact_existing' => 514,
            'approved_missing_current_ids' => 5,
            'current_source_missing_from_production' => 5,
            'production_not_in_current_source' => 2,
            'current_non_approved' => 106,
            'current_non_approved_existing' => 106,
            'existing_outside_approved' => 108,
            'cross_source_base_sku_collisions' => 4,
            'duplicate_source_sku_groups' => 2,
            'namespaced_storage_skus' => 8,
            'approved_missing_external_ids' => [
                'orteza-tulowia-oppo-2356',
                'wozek-elektryczny-z-funkcja-chodzika-at52334',
                'wozek-inwalidzki-elektryczny-at52304-3',
                'wozek-inwalidzki-elektryczny-zewnetrzny-i-skuter-inwalidzki-at52339',
                'wozek-inwalidzki-elektryczny-zewnetrzny-skuter-inwalidzki-at52338',
            ],
            'current_missing_external_ids' => [
                'orteza-tulowia-oppo-2356',
                'wozek-elektryczny-z-funkcja-chodzika-at52334',
                'wozek-inwalidzki-elektryczny-at52304-3',
                'wozek-inwalidzki-elektryczny-zewnetrzny-i-skuter-inwalidzki-at52339',
                'wozek-inwalidzki-elektryczny-zewnetrzny-skuter-inwalidzki-at52338',
            ],
            'production_not_current_external_ids' => [
                'opaska-kompresyjna-na-twarz-at04702',
                'wozek-inwalidzki-elektryczny-at52334',
            ],
            'create_external_ids' => [
                'orteza-tulowia-oppo-2356',
                'wozek-inwalidzki-elektryczny-at52304-3',
                'wozek-inwalidzki-elektryczny-zewnetrzny-i-skuter-inwalidzki-at52339',
                'wozek-inwalidzki-elektryczny-zewnetrzny-skuter-inwalidzki-at52338',
            ],
            'legacy_aliases' => [
                'wozek-elektryczny-z-funkcja-chodzika-at52334' => [
                    'external_id' => 'wozek-inwalidzki-elektryczny-at52334',
                    'product_id' => 11447,
                    'sku' => 'AT52334',
                ],
            ],
            'obsolete_external_ids' => [
                'opaska-kompresyjna-na-twarz-at04702',
            ],
            'obsolete_rows' => [
                'opaska-kompresyjna-na-twarz-at04702' => [
                    'product_id' => 11040,
                ],
            ],
            'rescue_files' => [
                'import-data/antar/media-rescue/AT51053-1.jpg',
                'import-data/antar/media-rescue/AT51053-2.jpg',
                'import-data/antar/media-rescue/AT51053-3.jpg',
                'import-data/antar/media-rescue/AT51125-4.jpg',
                'import-data/antar/media-rescue/AT51125-unnumbered.jpg',
                'import-data/antar/media-rescue/AT51049-ZESTAW.jpg',
            ],
            'minimum_free_mib' => $this->nonNegativeIntOption('minimum-free-mib', 512),
        ];
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

    private function relativePath(string $value): string
    {
        $value = ltrim(trim(str_replace('\\', '/', $value)), '/');
        if ($value === '' || str_contains($value, '../') || str_starts_with($value, '..')) {
            throw new RuntimeException('Path must be a safe relative path on the Laravel local disk.');
        }

        return $value;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return $value === null ? 'null' : (string) $value;
    }
}
