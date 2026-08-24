<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisProductionPreflight;
use Illuminate\Console\Command;
use JsonException;

final class PreflightSigvarisProductionCommand extends Command
{
    private const APPROVED_IMPORT_MAP_SHA256 = '7f270865aebbab63c441f82c63d4075451f5c13fdbd49d735f43f00b427635aa';
    private const APPROVED_PRODUCT_DATA_SHA256 = '6d35626f3013e229e60b03910dc9e5a1807d006ad87f366862f36e4759c76df4';
    private const APPROVED_COMBINATIONS_SHA256 = '25f6bdc91f26cd0eb80e1d9b3146e2958ed28817f78e7747c320836c0f176ba0';

    protected $signature = 'sigvaris:production-preflight
        {--from=scrapers/sigvaris/import-map.json : Approved Sigvaris import-map JSON. Relative paths resolve under storage/app.}
        {--expected-products=226}
        {--expected-variants=14991}
        {--expected-images=849}
        {--expected-category-paths=71}
        {--expected-downloads=208}
        {--expected-stable-default-variants=1}
        {--expected-vat-8-products=216}
        {--expected-vat-23-products=10}
        {--expected-review-items=2}
        {--expected-existing-products=0}
        {--expected-existing-variants=0}
        {--expected-existing-images=0}
        {--expected-sha256='.self::APPROVED_IMPORT_MAP_SHA256.' : Exact approved import-map SHA-256.}
        {--expected-product-data-sha256='.self::APPROVED_PRODUCT_DATA_SHA256.' : Frozen product-data SHA-256 embedded in the map.}
        {--expected-combinations-sha256='.self::APPROVED_COMBINATIONS_SHA256.' : Frozen combination-catalogue SHA-256 embedded in the map.}
        {--minimum-free-mib=500 : Minimum free space required on the production public storage volume.}
        {--probe-images=3 : Number of mapped source image URLs to fetch into memory. Use 0 to skip.}
        {--probe-timeout=20 : Timeout in seconds for each image probe.}
        {--allow-non-production : Permit a local/staging rehearsal.}
        {--save= : Optional evidence JSON path under storage/app. No report is written unless supplied.}
        {--show-checks : Print every preflight check.}
        {--show-review : Print non-blocking mapping review items.}';

    protected $description = 'Run a read-only production readiness preflight for the approved Sigvaris catalogue. Performs no catalogue or image writes.';

    public function __construct(private readonly SigvarisProductionPreflight $preflight)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: sigvaris:production-preflight is intended for production. Use --allow-non-production only for a rehearsal.');

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->option('from'));
        $map = $this->loadMap($path);
        if ($map === null) {
            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256) || $sha256 === '') {
            $this->error('Unable to calculate import-map SHA-256: '.$path);

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
            'existing_products' => $this->nonNegativeInt('expected-existing-products', 0),
            'existing_variants' => $this->nonNegativeInt('expected-existing-variants', 0),
            'existing_images' => $this->nonNegativeInt('expected-existing-images', 0),
            'sha256' => $this->nullableString($this->option('expected-sha256')),
            'product_data_sha256' => $this->nullableString($this->option('expected-product-data-sha256')),
            'combinations_sha256' => $this->nullableString($this->option('expected-combinations-sha256')),
        ];

        $report = $this->preflight->inspect(
            map: $map,
            expected: $expected,
            mapSha256: $sha256,
            minimumFreeMiB: $this->nonNegativeInt('minimum-free-mib', 500),
            probeImageCount: $this->nonNegativeInt('probe-images', 3),
            probeTimeoutSeconds: max(1, $this->nonNegativeInt('probe-timeout', 20)),
        );

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $errors = array_values(array_filter($report['errors'] ?? [], 'is_string'));
        $review = array_values(array_filter($report['review_items'] ?? [], 'is_string'));

        $this->info('Sigvaris production readiness preflight');
        $this->line('Environment: '.app()->environment());
        $this->line('Source: '.$path);
        $this->line('Import-map SHA-256: '.$sha256);
        $this->line('Database writes: NO');
        $this->line('Product image writes: NO');
        $this->line('Report file writes: '.($this->nullableString($this->option('save')) !== null ? 'REQUESTED' : 'NO'));
        $this->newLine();
        $this->line('Products: '.($metrics['products'] ?? 0));
        $this->line('Variants: '.($metrics['variants'] ?? 0));
        $this->line('Images: '.($metrics['images'] ?? 0));
        $this->line('Distinct category paths: '.($metrics['category_paths'] ?? 0));
        $this->line('Downloads: '.($metrics['downloads'] ?? 0));
        $this->line('Stable default variants: '.($metrics['stable_default_variants'] ?? 0));
        $this->line('8% VAT products: '.($metrics['vat_8_products'] ?? 0));
        $this->line('23% VAT products: '.($metrics['vat_23_products'] ?? 0));
        $this->line('Hard preflight errors: '.count($errors));
        $this->line('Mapping review items: '.count($review));

        if ((bool) $this->option('show-checks') || $errors !== []) {
            $this->newLine();
            $this->info('Preflight checks:');
            foreach (($report['checks'] ?? []) as $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->line(sprintf(
                    '- [%s] %s | %s',
                    $check['status'] ?? '?',
                    $check['name'] ?? '?',
                    $check['message'] ?? '',
                ));
            }
        }

        if ((bool) $this->option('show-review') && $review !== []) {
            $this->newLine();
            $this->warn('Review items:');
            foreach ($review as $item) {
                $this->line('- '.$item);
            }
        }

        $save = $this->nullableString($this->option('save'));
        if ($save !== null) {
            $savePath = str_starts_with($save, '/') ? $save : storage_path('app/'.ltrim($save, '/'));
            $directory = dirname($savePath);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error('Unable to create evidence-report directory: '.$directory);

                return self::FAILURE;
            }
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($savePath, $payload) === false) {
                $this->error('Unable to save evidence report: '.$savePath);

                return self::FAILURE;
            }
            $this->line('Saved evidence report to '.$savePath);
        }

        if ($errors === []) {
            $this->info('PASS: Sigvaris production preflight is ready for production execution. No catalogue writes were performed.');

            return self::SUCCESS;
        }

        $this->error('FAIL: Sigvaris production preflight has hard errors. Do not perform production catalogue writes.');

        return self::FAILURE;
    }

    /** @return array<string,mixed>|null */
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
        if (! is_array($decoded)) {
            $this->error('Import-map root must be an object.');

            return null;
        }

        return $decoded;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return storage_path('app/scrapers/sigvaris/import-map.json');
        }

        return str_starts_with($value, '/') ? $value : storage_path('app/'.ltrim($value, '/'));
    }

    private function nonNegativeInt(string $name, int $default): int
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
}
