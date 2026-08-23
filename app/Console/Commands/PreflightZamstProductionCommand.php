<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zamst\ZamstProductionPreflight;
use Illuminate\Console\Command;
use JsonException;

final class PreflightZamstProductionCommand extends Command
{
    protected $signature = 'zamst:production-preflight
        {--from=scrapers/zamst/import-map.json : Approved Zamst import-map JSON path. Relative paths resolve under storage/app.}
        {--expected-products=24}
        {--expected-variants=108}
        {--expected-images=294}
        {--expected-category-paths=21}
        {--expected-downloads=23}
        {--expected-videos=29}
        {--expected-vat-review=24}
        {--expected-existing-products=0}
        {--expected-existing-variants=0}
        {--expected-existing-images=0}
        {--expected-sha256= : Optional exact approved import-map SHA-256 fingerprint.}
        {--minimum-free-mib=100 : Minimum free space required on the production public storage volume.}
        {--probe-images=1 : Number of mapped image URLs to fetch into memory as a production egress check. Use 0 to skip.}
        {--probe-timeout=20 : Timeout in seconds for each image probe.}
        {--allow-non-production : Permit a local/staging rehearsal. Production remains the intended execution environment.}
        {--save= : Optional JSON evidence path under storage/app. No report file is written unless explicitly supplied.}
        {--show-checks : Print every preflight check.}
        {--show-review : Print mapping review items.}';

    protected $description = 'Run a read-only production readiness preflight for the approved Zamst catalogue. Performs no database or product-image writes.';

    public function __construct(
        private readonly ZamstProductionPreflight $preflight,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: zamst:production-preflight is intended for production. Use --allow-non-production only for a rehearsal.');

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
            'products' => $this->nonNegativeInt('expected-products', 24),
            'variants' => $this->nonNegativeInt('expected-variants', 108),
            'images' => $this->nonNegativeInt('expected-images', 294),
            'category_paths' => $this->nonNegativeInt('expected-category-paths', 21),
            'downloads' => $this->nonNegativeInt('expected-downloads', 23),
            'videos' => $this->nonNegativeInt('expected-videos', 29),
            'vat_review_products' => $this->nonNegativeInt('expected-vat-review', 24),
            'existing_products' => $this->nonNegativeInt('expected-existing-products', 0),
            'existing_variants' => $this->nonNegativeInt('expected-existing-variants', 0),
            'existing_images' => $this->nonNegativeInt('expected-existing-images', 0),
            'sha256' => $this->nullableString($this->option('expected-sha256')),
        ];

        $report = $this->preflight->inspect(
            map: $map,
            expected: $expected,
            mapSha256: $sha256,
            minimumFreeMiB: $this->nonNegativeInt('minimum-free-mib', 100),
            probeImageCount: $this->nonNegativeInt('probe-images', 1),
            probeTimeoutSeconds: max(1, $this->nonNegativeInt('probe-timeout', 20)),
        );

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $errors = array_values(array_filter($report['errors'] ?? [], 'is_string'));
        $review = array_values(array_filter($report['review_items'] ?? [], 'is_string'));

        $this->info('Zamst production readiness preflight');
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
        $this->line('Product videos: '.($metrics['videos'] ?? 0));
        $this->line('VAT review products: '.($metrics['vat_review_products'] ?? 0));
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
            $savePath = str_starts_with($save, '/')
                ? $save
                : storage_path('app/'.ltrim($save, '/'));
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
            $this->info('PASS: Zamst production preflight is ready for the production execution patch. No catalogue writes were performed.');

            return self::SUCCESS;
        }

        $this->error('FAIL: Zamst production preflight has hard errors. Do not enable production catalogue writes.');

        return self::FAILURE;
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
            return storage_path('app/scrapers/zamst/import-map.json');
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        return storage_path('app/'.ltrim($value, '/'));
    }

    private function nonNegativeInt(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
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
