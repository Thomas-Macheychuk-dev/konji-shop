<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zamst\ZamstProductImporter;
use App\Services\Zamst\ZamstProductionImportAudit;
use App\Services\Zamst\ZamstProductionPreflight;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class ExecuteZamstProductionImportCommand extends Command
{
    private const CONFIRMATION = 'IMPORT-ZAMST-DRAFTS';

    protected $signature = 'zamst:production-import
        {--from=scrapers/zamst/import-map.json : Approved Zamst import-map JSON path. Relative paths resolve under storage/app.}
        {--expected-sha256= : REQUIRED approved import-map SHA-256 fingerprint.}
        {--expected-products=24}
        {--expected-variants=108}
        {--expected-images=294}
        {--expected-category-paths=21}
        {--expected-downloads=23}
        {--expected-videos=29}
        {--expected-vat-review=24}
        {--expected-existing-products= : Exact Zamst product rows expected before a write.}
        {--expected-existing-variants= : Exact Zamst variant rows expected before a write.}
        {--expected-existing-images= : Exact Zamst image rows expected before a write.}
        {--expected-post-products= : Exact Zamst product rows required after a write.}
        {--expected-post-variants= : Exact Zamst variant rows required after a write.}
        {--expected-post-images= : Exact Zamst image rows required after a write.}
        {--limit= : Maximum number of mapped products to import.}
        {--offset=0 : Number of mapped products to skip before importing.}
        {--write : Execute production draft writes. Without this flag the command performs preflight/dry-run only.}
        {--confirm-production-write= : Must equal IMPORT-ZAMST-DRAFTS when --write is used.}
        {--allow-unverified-vat : Explicitly acknowledge the approved VAT-review fallback state.}
        {--refresh-images : Re-download approved Zamst images even if a local copy exists.}
        {--image-timeout=30}
        {--image-attempts=5}
        {--image-retry-delay-ms=3000}
        {--image-request-delay-ms=500}
        {--minimum-free-mib=100}
        {--probe-images=3}
        {--probe-timeout=20}
        {--allow-non-production : Permit local/staging rehearsal. Production is required otherwise.}
        {--save-preflight= : Optional preflight evidence JSON path under storage/app.}
        {--save-audit= : Optional post-write audit JSON path under storage/app.}
        {--show-checks : Print every inline preflight check.}
        {--show-review : Print review items for selected products.}
        {--show-failures : Print import/audit failures.}';

    protected $description = 'Execute the SHA-pinned Zamst production draft import only after an inline production preflight passes; dry-run by default.';

    public function __construct(
        private readonly ZamstProductionPreflight $preflight,
        private readonly ZamstProductImporter $importer,
        private readonly ZamstProductionImportAudit $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: zamst:production-import is intended for production. Use --allow-non-production only for a rehearsal.');

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
            $this->error('No Zamst products selected after offset/limit.');

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

        $minimumFreeMiB = $this->nonNegativeInt('minimum-free-mib', 100);
        $probeImageCount = $this->nonNegativeInt('probe-images', 3);

        if ($write && app()->environment('production') && ($minimumFreeMiB < 100 || $probeImageCount < 1)) {
            $this->error('BLOCKED: production writes require --minimum-free-mib>=100 and at least one --probe-images request.');

            return self::FAILURE;
        }

        $expected = [
            'products' => $this->nonNegativeInt('expected-products', 24),
            'variants' => $this->nonNegativeInt('expected-variants', 108),
            'images' => $this->nonNegativeInt('expected-images', 294),
            'category_paths' => $this->nonNegativeInt('expected-category-paths', 21),
            'downloads' => $this->nonNegativeInt('expected-downloads', 23),
            'videos' => $this->nonNegativeInt('expected-videos', 29),
            'vat_review_products' => $this->nonNegativeInt('expected-vat-review', 24),
            'existing_products' => $existingProducts ?? 0,
            'existing_variants' => $existingVariants ?? 0,
            'existing_images' => $existingImages ?? 0,
            'sha256' => strtolower($expectedSha256),
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
        $selectedVatReview = count(array_filter(
            $selected,
            static fn (array $product): bool => ($product['tax']['requires_review'] ?? false) === true,
        ));

        $this->info('Zamst production draft import');
        $this->line('Environment: '.app()->environment());
        $this->line('Source: '.$path);
        $this->line('Approved SHA-256: '.strtolower($expectedSha256));
        $this->line('Actual SHA-256: '.strtolower($actualSha256));
        $this->line('Available mapped products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selected));
        $this->line('Product status: draft (forced)');
        $this->line('Database writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Selected products requiring VAT review: '.$selectedVatReview);
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
            $this->error('BLOCKED: inline production preflight failed. No Zamst catalogue writes were performed.');

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

        if ($selectedVatReview > 0 && ! (bool) $this->option('allow-unverified-vat')) {
            $this->error('BLOCKED: selected products contain the approved but unverified VAT fallback. Add --allow-unverified-vat only after acknowledging that review state.');

            return self::FAILURE;
        }

        $this->warn('WRITE GATE PASSED: importing selected Zamst products as DRAFTS only.');

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
        $this->info('=== ZAMST PRODUCTION WRITE RESULT ===');
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
        $this->line('Global Zamst products: '.($metrics['global_products'] ?? 0));
        $this->line('Global Zamst variants: '.($metrics['global_variants'] ?? 0));
        $this->line('Global Zamst images: '.($metrics['global_images'] ?? 0));
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
            $this->info('PASS: selected Zamst products were written to production as drafts and passed the post-write audit.');

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
        return $this->nullableString($mapped['product']['name'] ?? null) ?? '[unnamed Zamst product]';
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

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'zamst') {
            $this->error('Import-map root/source is invalid.');

            return null;
        }

        return $decoded;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            $value = 'scrapers/zamst/import-map.json';
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
