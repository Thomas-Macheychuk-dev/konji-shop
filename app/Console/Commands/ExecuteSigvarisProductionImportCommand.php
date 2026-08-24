<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisProductImporter;
use App\Services\Sigvaris\SigvarisProductionImportAudit;
use App\Services\Sigvaris\SigvarisProductionPreflight;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class ExecuteSigvarisProductionImportCommand extends Command
{
    private const CONFIRMATION = 'IMPORT-SIGVARIS-DRAFTS';

    protected $signature = 'sigvaris:production-import
        {--from=scrapers/sigvaris/import-map.json : Approved Sigvaris import-map JSON path. Relative paths resolve under storage/app.}
        {--expected-sha256= : REQUIRED approved import-map SHA-256 fingerprint.}
        {--expected-product-data-sha256=6d35626f3013e229e60b03910dc9e5a1807d006ad87f366862f36e4759c76df4}
        {--expected-combinations-sha256=25f6bdc91f26cd0eb80e1d9b3146e2958ed28817f78e7747c320836c0f176ba0}
        {--expected-products=226}
        {--expected-variants=14991}
        {--expected-images=849}
        {--expected-category-paths=71}
        {--expected-downloads=208}
        {--expected-stable-default-variants=1}
        {--expected-vat-8-products=216}
        {--expected-vat-23-products=10}
        {--expected-review-items=2}
        {--expected-existing-products= : Exact Sigvaris product rows expected before a write.}
        {--expected-existing-variants= : Exact Sigvaris variant rows expected before a write.}
        {--expected-existing-images= : Exact Sigvaris image rows expected before a write.}
        {--expected-post-products= : Exact Sigvaris product rows required after a write.}
        {--expected-post-variants= : Exact Sigvaris variant rows required after a write.}
        {--expected-post-images= : Exact Sigvaris image rows required after a write.}
        {--limit= : Maximum number of mapped products to import.}
        {--offset=0 : Number of mapped products to skip before importing.}
        {--write : Execute production draft writes. Without this flag the command performs preflight/dry-run only.}
        {--confirm-production-write= : Must equal IMPORT-SIGVARIS-DRAFTS when --write is used.}
        {--acknowledge-review-items : Explicitly acknowledge any selected non-blocking mapping review items.}
        {--refresh-images : Re-download approved Sigvaris images even if a local copy exists.}
        {--image-timeout=30}
        {--image-attempts=5}
        {--image-retry-delay-ms=3000}
        {--image-request-delay-ms=500}
        {--minimum-free-mib=500}
        {--probe-images=3}
        {--probe-timeout=20}
        {--allow-non-production : Permit local/staging rehearsal. Production is required otherwise.}
        {--save-preflight= : Optional preflight evidence JSON path under storage/app.}
        {--save-audit= : Optional post-write audit JSON path under storage/app.}
        {--show-checks : Print every inline preflight check.}
        {--show-review : Print review items for selected products.}
        {--show-failures : Print import/audit failures.}';

    protected $description = 'Execute the frozen-fingerprint Sigvaris production draft import only after an inline production preflight passes; dry-run by default.';

    public function __construct(
        private readonly SigvarisProductionPreflight $preflight,
        private readonly SigvarisProductImporter $importer,
        private readonly SigvarisProductionImportAudit $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: sigvaris:production-import is intended for production. Use --allow-non-production only for a rehearsal.');

            return self::FAILURE;
        }

        $expectedSha256 = $this->nullableString($this->option('expected-sha256'));

        if ($expectedSha256 === null || preg_match('/^[a-f0-9]{64}$/i', $expectedSha256) !== 1) {
            $this->error('BLOCKED: --expected-sha256=<64 hex characters> is required, including for production dry-runs.');

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->option('from'));
        $map = $this->loadMap($path);

        if ($map === null) {
            return self::FAILURE;
        }

        $actualSha256 = hash_file('sha256', $path);

        if (! is_string($actualSha256) || $actualSha256 === '') {
            $this->error('Unable to calculate import-map SHA-256: '.$path);

            return self::FAILURE;
        }

        $products = array_values(array_filter($map['products'] ?? [], 'is_array'));
        $offset = $this->nonNegativeInt('offset', 0);
        $limit = $this->nullablePositiveInt('limit');
        $selected = array_slice($products, $offset, $limit);
        $write = (bool) $this->option('write');

        if ($selected === []) {
            $this->error('No Sigvaris products selected after offset/limit.');

            return self::FAILURE;
        }

        $existingProducts = $this->nullableNonNegativeInt('expected-existing-products');
        $existingVariants = $this->nullableNonNegativeInt('expected-existing-variants');
        $existingImages = $this->nullableNonNegativeInt('expected-existing-images');
        $postProducts = $this->nullableNonNegativeInt('expected-post-products');
        $postVariants = $this->nullableNonNegativeInt('expected-post-variants');
        $postImages = $this->nullableNonNegativeInt('expected-post-images');

        if ($write && in_array(null, [$existingProducts, $existingVariants, $existingImages, $postProducts, $postVariants, $postImages], true)) {
            $this->error('BLOCKED: production writes require explicit --expected-existing-products/variants/images and --expected-post-products/variants/images values.');

            return self::FAILURE;
        }

        $minimumFreeMiB = $this->nonNegativeInt('minimum-free-mib', 500);
        $probeImageCount = $this->nonNegativeInt('probe-images', 3);

        if ($write && app()->environment('production') && ($minimumFreeMiB < 500 || $probeImageCount < 1)) {
            $this->error('BLOCKED: production writes require --minimum-free-mib>=500 and at least one --probe-images request.');

            return self::FAILURE;
        }

        $expected = [
            'products' => $this->nonNegativeInt('expected-products', 226),
            'variants' => $this->nonNegativeInt('expected-variants', 14991),
            'images' => $this->nonNegativeInt('expected-images', 849),
            'category_paths' => $this->nonNegativeInt('expected-category-paths', 71),
            'downloads' => $this->nonNegativeInt('expected-downloads', 208),
            'stable_default_variants' => $this->nonNegativeInt('expected-stable-default-variants', 1),
            'vat_8_products' => $this->nonNegativeInt('expected-vat-8-products', 216),
            'vat_23_products' => $this->nonNegativeInt('expected-vat-23-products', 10),
            'review_items' => $this->nonNegativeInt('expected-review-items', 2),
            'existing_products' => $existingProducts ?? 0,
            'existing_variants' => $existingVariants ?? 0,
            'existing_images' => $existingImages ?? 0,
            'sha256' => strtolower($expectedSha256),
            'product_data_sha256' => $this->nullableString($this->option('expected-product-data-sha256')),
            'combinations_sha256' => $this->nullableString($this->option('expected-combinations-sha256')),
        ];

        $preflight = $this->preflight->inspect(
            map: $map,
            expected: $expected,
            mapSha256: $actualSha256,
            minimumFreeMiB: $minimumFreeMiB,
            probeImageCount: $probeImageCount,
            probeTimeoutSeconds: max(1, $this->nonNegativeInt('probe-timeout', 20)),
        );

        $preflightErrors = array_values(array_filter($preflight['errors'] ?? [], 'is_string'));
        $selectedReviewItems = [];

        foreach ($selected as $mapped) {
            $name = $this->mappedName($mapped);

            foreach (array_values(array_filter($mapped['review_items'] ?? [], 'is_string')) as $item) {
                $selectedReviewItems[] = $name.': '.trim($item);
            }
        }

        $selectedReviewItems = array_values(array_unique(array_filter($selectedReviewItems)));

        $this->info('Sigvaris production draft import');
        $this->line('Environment: '.app()->environment());
        $this->line('Source: '.$path);
        $this->line('Approved SHA-256: '.strtolower($expectedSha256));
        $this->line('Actual SHA-256: '.strtolower($actualSha256));
        $this->line('Available mapped products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selected));
        $this->line('Product status: draft (forced)');
        $this->line('Database writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Selected mapping review items: '.count($selectedReviewItems));
        $this->line('Inline preflight errors: '.count($preflightErrors));

        if ((bool) $this->option('show-checks') || $preflightErrors !== []) {
            $this->printChecks($preflight['checks'] ?? []);
        }

        if (($save = $this->nullableString($this->option('save-preflight'))) !== null) {
            if (! $this->saveJson($save, $preflight)) {
                return self::FAILURE;
            }
        }

        if ($preflightErrors !== []) {
            $this->error('BLOCKED: inline production preflight failed. No Sigvaris catalogue writes were performed.');

            return self::FAILURE;
        }

        if (! $write) {
            $this->printSelectedSummary($selected);
            $this->printReview($selected);
            $this->info('PASS: SHA-pinned production dry-run passed. No catalogue writes or product-image writes were performed.');

            return self::SUCCESS;
        }

        if ($this->nullableString($this->option('confirm-production-write')) !== self::CONFIRMATION) {
            $this->error('BLOCKED: --confirm-production-write='.self::CONFIRMATION.' is required for production writes.');

            return self::FAILURE;
        }

        if ($selectedReviewItems !== [] && ! (bool) $this->option('acknowledge-review-items')) {
            $this->error('BLOCKED: selected products contain non-blocking mapping review items. Add --acknowledge-review-items only after reviewing them.');

            return self::FAILURE;
        }

        $this->warn('WRITE GATE PASSED: importing selected Sigvaris products as DRAFTS only.');

        $stats = $this->emptyStats();
        $created = 0;
        $updated = 0;
        $warnings = [];
        $failures = [];
        $total = count($selected);

        foreach ($selected as $index => $mapped) {
            $name = $this->mappedName($mapped);
            $externalId = $this->mappedExternalId($mapped);
            $this->line(sprintf('Importing %d/%d: %s | external_id=%s', $index + 1, $total, $name, $externalId ?? '?'));

            try {
                $result = $this->importer->import(
                    mapped: $mapped,
                    importImages: true,
                    imageLimit: null,
                    refreshImages: (bool) $this->option('refresh-images'),
                    imageTimeoutSeconds: $this->positiveInt('image-timeout', 30),
                    imageAttempts: $this->positiveInt('image-attempts', 5),
                    imageRetryDelayMs: $this->nonNegativeInt('image-retry-delay-ms', 3000),
                    imageRequestDelayMs: $this->nonNegativeInt('image-request-delay-ms', 500),
                    importDocuments: true,
                );

                if ($result['action'] === 'created') {
                    $created++;
                } else {
                    $updated++;
                }

                foreach ($result['stats'] as $key => $value) {
                    $stats[$key] = ($stats[$key] ?? 0) + $value;
                }

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $name.': '.$warning;
                }

                $this->info(sprintf(
                    '  %s product ID %d | variants=%d | images=%d | categories=%d',
                    strtoupper($result['action']),
                    $result['product']->id,
                    $result['product']->variants->count(),
                    $result['product']->images->count(),
                    $result['product']->categories->count(),
                ));

                if ($result['warnings'] !== [] || ($result['stats']['images_failed'] ?? 0) > 0) {
                    $failures[] = $name.': production importer returned warnings/image failures; remaining products were not attempted.';
                    break;
                }
            } catch (Throwable $exception) {
                $failures[] = $name.': '.$exception->getMessage();
                $this->error('  FAILED: '.$exception->getMessage());
                break;
            }
        }

        $this->newLine();
        $this->info('=== SIGVARIS PRODUCTION WRITE RESULT ===');
        $this->line('Products created: '.$created);
        $this->line('Products updated: '.$updated);
        $this->line('Warnings: '.count($warnings));
        $this->line('Failures: '.count($failures));
        $this->line('Variants created: '.$stats['variants_created']);
        $this->line('Variants updated: '.$stats['variants_updated']);
        $this->line('Variants archived: '.$stats['variants_archived']);
        $this->line('Images created: '.$stats['images_created']);
        $this->line('Images updated: '.$stats['images_updated']);
        $this->line('Images reused without download: '.$stats['images_reused']);
        $this->line('Images deleted as stale: '.$stats['images_deleted']);
        $this->line('Image failures: '.$stats['images_failed']);
        $this->line('Documents created: '.$stats['documents_created']);
        $this->line('Documents reused without download: '.$stats['documents_reused']);

        $audit = $this->audit->inspect($selected, [
            'products' => $postProducts ?? 0,
            'variants' => $postVariants ?? 0,
            'images' => $postImages ?? 0,
        ]);
        $auditErrors = array_values(array_filter($audit['errors'] ?? [], 'is_string'));
        $metrics = is_array($audit['metrics'] ?? null) ? $audit['metrics'] : [];

        $this->newLine();
        $this->info('=== PRODUCTION POST-WRITE AUDIT ===');
        $this->line('Selected products found: '.($metrics['selected_products_found'] ?? 0).'/'.($metrics['selected_products_expected'] ?? 0));
        $this->line('Selected variants: '.($metrics['selected_variants_actual'] ?? 0).'/'.($metrics['selected_variants_expected'] ?? 0));
        $this->line('Selected images: '.($metrics['selected_images_actual'] ?? 0).'/'.($metrics['selected_images_expected'] ?? 0));
        $this->line('Selected local documents: '.($metrics['selected_documents_actual'] ?? 0).'/'.($metrics['selected_documents_expected'] ?? 0));
        $this->line('Global Sigvaris products: '.($metrics['global_products'] ?? 0));
        $this->line('Global Sigvaris variants: '.($metrics['global_variants'] ?? 0));
        $this->line('Global Sigvaris images: '.($metrics['global_images'] ?? 0));
        $this->line('Audit errors: '.count($auditErrors));

        if (($save = $this->nullableString($this->option('save-audit'))) !== null) {
            if (! $this->saveJson($save, $audit)) {
                return self::FAILURE;
            }
        }

        if ((bool) $this->option('show-failures') && ($failures !== [] || $warnings !== [] || $auditErrors !== [])) {
            foreach (array_merge($failures, $warnings, $auditErrors) as $failure) {
                $this->line('- '.$failure);
            }
        }

        $this->printReview($selected);

        if ($failures === [] && $warnings === [] && $auditErrors === [] && $stats['images_failed'] === 0) {
            $this->info('PASS: selected Sigvaris products were written to production as drafts and passed the post-write audit.');

            return self::SUCCESS;
        }

        $this->error('FAIL: production import/audit did not complete cleanly. Products remain draft; do not publish them.');

        return self::FAILURE;
    }

    /** @param list<array<string, mixed>> $selected */
    private function printSelectedSummary(array $selected): void
    {
        $variants = 0;
        $images = 0;

        foreach ($selected as $mapped) {
            $variants += is_array($mapped['variants'] ?? null) ? count($mapped['variants']) : 0;
            $images += is_array($mapped['images'] ?? null) ? count($mapped['images']) : 0;
        }

        $this->line('Selected planned variants: '.$variants);
        $this->line('Selected mapped images: '.$images);
    }

    /** @param mixed $checks */
    private function printChecks(mixed $checks): void
    {
        if (! is_array($checks)) {
            return;
        }

        $this->newLine();
        $this->info('Inline preflight checks:');

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $this->line(sprintf('- [%s] %s | %s', $check['status'] ?? '?', $check['name'] ?? '?', $check['message'] ?? ''));
        }
    }

    /** @param list<array<string, mixed>> $selected */
    private function printReview(array $selected): void
    {
        if (! (bool) $this->option('show-review')) {
            return;
        }

        $items = [];

        foreach ($selected as $mapped) {
            $name = $this->mappedName($mapped);

            foreach (array_values(array_filter($mapped['review_items'] ?? [], 'is_string')) as $item) {
                $items[] = $name.': '.trim($item);
            }
        }

        $items = array_values(array_unique(array_filter($items)));

        if ($items === []) {
            return;
        }

        $this->newLine();
        $this->warn('Selected mapping review items: '.count($items));

        foreach ($items as $item) {
            $this->line('- '.$item);
        }
    }

    /** @param array<string, mixed> $mapped */
    private function mappedName(array $mapped): string
    {
        return $this->nullableString($mapped['product']['name'] ?? null) ?? '[unnamed Sigvaris product]';
    }

    /** @param array<string, mixed> $mapped */
    private function mappedExternalId(array $mapped): ?string
    {
        return $this->nullableString($mapped['product']['external_id'] ?? null);
    }

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'categories_created' => 0,
            'categories_reused' => 0,
            'variants_created' => 0,
            'variants_updated' => 0,
            'variants_archived' => 0,
            'images_created' => 0,
            'images_updated' => 0,
            'images_reused' => 0,
            'images_deleted' => 0,
            'images_failed' => 0,
            'documents_created' => 0,
            'documents_reused' => 0,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadMap(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('Import-map JSON not found: '.$path);

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid import-map JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'sigvaris') {
            $this->error('Import-map root/source is invalid.');

            return null;
        }

        return $decoded;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            $value = 'scrapers/sigvaris/import-map.json';
        }

        return str_starts_with($value, '/') ? $value : storage_path('app/'.ltrim($value, '/'));
    }

    /** @param array<string, mixed> $payload */
    private function saveJson(string $value, array $payload): bool
    {
        $path = str_starts_with($value, '/') ? $value : storage_path('app/'.ltrim($value, '/'));
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Unable to create evidence directory: '.$directory);

            return false;
        }

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (JsonException $exception) {
            $this->error('Unable to encode evidence JSON: '.$exception->getMessage());

            return false;
        }

        if (file_put_contents($path, $json) === false) {
            $this->error('Unable to save evidence JSON: '.$path);

            return false;
        }

        $this->line('Saved evidence JSON to '.$path);

        return true;
    }

    private function nonNegativeInt(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function positiveInt(string $name, int $default): int
    {
        return max(1, $this->nonNegativeInt($name, $default));
    }

    private function nullablePositiveInt(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nullableNonNegativeInt(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
