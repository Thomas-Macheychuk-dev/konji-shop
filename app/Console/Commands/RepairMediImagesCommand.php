<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Medi\MediImageRepairService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class RepairMediImagesCommand extends Command
{
    protected $signature = 'medi:repair-images
        {--runtime-dir=scrapers/medi/runtime : Medi runtime directory under storage/app.}
        {--external-id=* : Limit repair to one or more Medi external product IDs.}
        {--image-limit=5 : Maximum usable images to retain per product. Use 0 for no limit.}
        {--dry-run : Audit image defects without downloading, deleting, or changing database rows.}
        {--keep-invalid : Do not delete invalid or placeholder image rows/files.}
        {--report=scrapers/medi/image-repair-report.json : JSON report path under storage/app.}
        {--show-failures : Print unresolved image URLs and all attempted fallback URLs.}';

    protected $description = 'Audit and repair missing, placeholder, or failed Medi product images from saved runtime product data.';

    public function __construct(
        private readonly MediImageRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $runtimeDirectory = $this->resolvePath((string) $this->option('runtime-dir'));
            $reportPath = $this->resolvePath((string) $this->option('report'));
            $selectedExternalIds = $this->selectedExternalIds();
            $imageLimit = $this->imageLimit();
            $products = $this->loadRuntimeProducts($runtimeDirectory);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $removeInvalid = ! (bool) $this->option('keep-invalid');

        if ($selectedExternalIds !== []) {
            $products = array_values(array_filter(
                $products,
                static fn (array $product): bool => isset($selectedExternalIds[(string) ($product['external_product_id'] ?? '')]),
            ));
        }

        if ($products === []) {
            $this->error('No Medi product data matched the requested image repair selection.');

            return self::FAILURE;
        }

        $this->info('Repairing Medi product images...');
        $this->line('Runtime: '.$runtimeDirectory);
        $this->line('Products selected: '.count($products));
        $this->line('Mode: '.($dryRun ? 'dry-run audit' : 'database and filesystem repair'));
        $this->line('Invalid images: '.($removeInvalid ? 'remove' : 'retain'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('Report: '.$reportPath);

        $results = [];
        $failures = [];
        $totals = [
            'products_scanned' => 0,
            'products_with_usable_images' => 0,
            'products_without_usable_images' => 0,
            'invalid_detected' => 0,
            'invalid_removed' => 0,
            'images_imported' => 0,
            'unresolved_sources' => 0,
        ];

        foreach ($products as $index => $productData) {
            $externalId = (string) ($productData['external_product_id'] ?? '[missing]');
            $name = is_string($productData['name'] ?? null) ? $productData['name'] : '[unnamed Medi product]';

            $this->line(sprintf('%d/%d %s | %s', $index + 1, count($products), $externalId, $name));

            try {
                $result = $this->repairService->repair(
                    $productData,
                    $dryRun,
                    $imageLimit,
                    $removeInvalid,
                );
                $results[] = $result;
                $totals['products_scanned']++;
                $totals['invalid_detected'] += $result['invalid_detected'];
                $totals['invalid_removed'] += $result['invalid_removed'];
                $totals['images_imported'] += $result['images_imported'];
                $totals['unresolved_sources'] += count($result['unresolved']);

                if ($result['has_usable_image']) {
                    $totals['products_with_usable_images']++;
                } else {
                    $totals['products_without_usable_images']++;
                }

                $this->line(sprintf(
                    '  valid %d -> %d, invalid %d, removed %d, imported %d, unresolved %d',
                    $result['valid_before'],
                    $result['valid_after'],
                    $result['invalid_detected'],
                    $result['invalid_removed'],
                    $result['images_imported'],
                    count($result['unresolved']),
                ));

                if (! $dryRun && ! $result['has_usable_image']) {
                    $failures[] = [
                        'external_id' => $result['external_id'],
                        'name' => $result['name'],
                        'error' => 'Product has no usable image after repair',
                    ];
                }
            } catch (Throwable $exception) {
                $failures[] = [
                    'external_id' => $externalId,
                    'name' => $name,
                    'error' => $exception->getMessage(),
                ];
                $this->error('  Failed: '.$exception->getMessage());
            }
        }

        $report = [
            'source' => 'medi',
            'generated_at' => now()->toIso8601String(),
            'runtime_directory' => $runtimeDirectory,
            'dry_run' => $dryRun,
            'remove_invalid' => $removeInvalid,
            'image_limit' => $imageLimit,
            'totals' => $totals,
            'results' => $results,
            'failures' => $failures,
        ];

        try {
            $this->writeReport($reportPath, $report);
        } catch (Throwable $exception) {
            $this->error('Unable to write Medi image repair report: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($dryRun ? 'Medi image audit completed.' : 'Medi image repair completed.');

        foreach ($totals as $label => $value) {
            $this->line(str_replace('_', ' ', ucfirst($label)).': '.$value);
        }

        $this->line('Failures: '.count($failures));

        if ((bool) $this->option('show-failures')) {
            $this->printFailureDetails($results, $failures);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRuntimeProducts(string $runtimeDirectory): array
    {
        $productDataDirectory = rtrim($runtimeDirectory, '/').'/product-data';

        if (! is_dir($productDataDirectory)) {
            throw new RuntimeException('Medi runtime product-data directory not found: '.$productDataDirectory);
        }

        $paths = glob($productDataDirectory.'/*.json') ?: [];
        sort($paths, SORT_STRING);

        if ($paths === []) {
            throw new RuntimeException('No Medi runtime product-data JSON files found in: '.$productDataDirectory);
        }

        $products = [];

        foreach ($paths as $path) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid Medi product-data JSON ['.$path.']: '.$exception->getMessage(), previous: $exception);
            }

            foreach (($decoded['products'] ?? []) as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $externalId = $product['external_product_id'] ?? null;

                if (is_int($externalId)) {
                    $externalId = (string) $externalId;
                }

                if (! is_string($externalId) || trim($externalId) === '') {
                    continue;
                }

                $products[trim($externalId)] = $product;
            }
        }

        return array_values($products);
    }

    /**
     * @return array<string, true>
     */
    private function selectedExternalIds(): array
    {
        $selected = [];

        foreach ((array) $this->option('external-id') as $externalId) {
            if (! is_string($externalId) || trim($externalId) === '') {
                continue;
            }

            $selected[trim($externalId)] = true;
        }

        return $selected;
    }

    private function imageLimit(): ?int
    {
        $value = filter_var($this->option('image-limit'), FILTER_VALIDATE_INT);

        if ($value === false || $value < 0) {
            throw new RuntimeException('--image-limit must be a non-negative integer');
        }

        return $value === 0 ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $path, array $report): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create report directory: '.$directory);
        }

        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(6));
        $contents = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary report file: '.$temporaryPath);
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to atomically replace report file: '.$path);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array{external_id: string, name: string, error: string}>  $failures
     */
    private function printFailureDetails(array $results, array $failures): void
    {
        foreach ($results as $result) {
            foreach (($result['unresolved'] ?? []) as $unresolved) {
                $this->warn('Unresolved image for Medi product '.$result['external_id'].': '.$unresolved['source_url']);

                foreach ($unresolved['attempts'] as $attempt) {
                    $this->line('  - '.$attempt['url'].' — '.$attempt['error']);
                }
            }
        }

        foreach ($failures as $failure) {
            $this->error('FAILED '.$failure['external_id'].' | '.$failure['name'].' — '.$failure['error']);
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('Storage path cannot be empty');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return storage_path('app/'.ltrim($path, '/'));
    }
}
