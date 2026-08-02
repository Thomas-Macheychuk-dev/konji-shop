<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Novicare\NovicareProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ImportNovicareProductsCommand extends Command
{
    protected $signature = 'novicare:import
        {--from=scrapers/novicare/product-data.json : Novicare product-data JSON path. Relative paths are resolved under storage/app.}
        {--dry-run : Validate and summarize the import without writing to the database or downloading images.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--status=draft : Product status to assign: draft, active, or archived.}
        {--no-images : Do not download or sync product images.}
        {--image-limit=50 : Maximum number of images to import per product. Use 0 for no limit.}
        {--image-timeout=30 : Timeout in seconds for each Novicare image request.}
        {--image-attempts=5 : Maximum attempts for each Novicare image request.}
        {--image-retry-delay-ms=5000 : Milliseconds to wait between Novicare image retry attempts.}
        {--image-request-delay-ms=250 : Milliseconds to pause between Novicare image downloads.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import Novicare scraped JSON into Konji Shop products.';

    public function __construct(
        private readonly NovicareProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('from'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Novicare products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $status = $this->productStatusOption();
        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $imageLimit = $this->imageLimitOption();
        $imageTimeoutSeconds = $this->positiveIntOption('image-timeout', 30);
        $imageAttempts = $this->positiveIntOption('image-attempts', 5);
        $imageRetryDelayMs = $this->nonNegativeIntOption('image-retry-delay-ms', 5000);
        $imageRequestDelayMs = $this->nonNegativeIntOption('image-request-delay-ms', 250);

        $this->info('Importing Novicare products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Status: '.$status->value);
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));

        if ($importImages) {
            $this->line('Image timeout: '.$imageTimeoutSeconds.' second(s)');
            $this->line('Image attempts: '.$imageAttempts);
            $this->line('Image retry delay: '.$imageRetryDelayMs.' ms');
            $this->line('Image request delay: '.$imageRequestDelayMs.' ms');
        }

        if ($dryRun) {
            $this->printDryRunSummary($selectedProducts);

            return self::SUCCESS;
        }

        $imported = 0;
        $failures = [];
        $warnings = [];
        $total = count($selectedProducts);

        foreach ($selectedProducts as $index => $productData) {
            $name = $this->productName($productData);
            $sourceUrl = $this->sourceUrl($productData);

            $this->line(sprintf('Importing product %d/%d: %s', $index + 1, $total, $name));

            if ($sourceUrl !== null) {
                $this->line('  '.$sourceUrl);
            }

            try {
                $result = $this->importer->import(
                    scraped: $productData,
                    status: $status,
                    importImages: $importImages,
                    imageLimit: $imageLimit,
                    imageTimeoutSeconds: $imageTimeoutSeconds,
                    imageAttempts: $imageAttempts,
                    imageRetryDelayMs: $imageRetryDelayMs,
                    imageRequestDelayMs: $imageRequestDelayMs,
                );
                $product = $result['product'];
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = [
                        'product' => $name,
                        'warning' => $warning,
                    ];
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf(
                    '  Imported product ID %d, variants: %d, images: %d, categories: %d',
                    $product->id,
                    $product->variants->count(),
                    $product->images->count(),
                    $product->categories->count(),
                ));
            } catch (Throwable $exception) {
                $failures[] = [
                    'name' => $name,
                    'url' => $sourceUrl,
                    'error' => $exception->getMessage(),
                ];

                $this->error('  Failed: '.$exception->getMessage());
            }
        }

        $this->info('Imported products: '.$imported);
        $this->line('Warnings: '.count($warnings));
        $this->line('Failures: '.count($failures));

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->warn('Failed Novicare imports:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' — '.($failure['url'] ?? '[missing url]').' — '.$failure['error']);
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function printDryRunSummary(array $products): void
    {
        $categoryPaths = [];
        $imageCount = 0;
        $variantCount = 0;
        $medicalDeviceCount = 0;
        $sourceWarningCount = 0;

        foreach ($products as $product) {
            foreach ($this->categoryPaths($product) as $path) {
                $categoryPaths[implode(' > ', $path)] = true;
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $variantCount += is_array($product['variant_candidates'] ?? null) && $product['variant_candidates'] !== []
                ? count($product['variant_candidates'])
                : 1;

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }

            $sourceWarningCount += is_array($product['warnings'] ?? null)
                ? count(array_filter($product['warnings'], 'is_string'))
                : 0;
        }

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Distinct category paths: '.count($categoryPaths));
        $this->line('Source brand: Novicare');
        $this->line('Variants to create/update: '.$variantCount);
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
        $this->line('Source crawl warnings: '.$sourceWarningCount);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Novicare product-data file not found: '.$path);

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Novicare product-data file is not valid JSON: '.$exception->getMessage());

            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Novicare product-data file does not contain a products array: '.$path);

            return [];
        }

        $products = [];
        $seen = [];

        foreach ($decoded['products'] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $dedupeKey = is_string($product['external_product_id'] ?? null)
                ? 'id:'.$product['external_product_id']
                : (is_string($product['canonical_url'] ?? null)
                    ? 'url:'.$product['canonical_url']
                    : (is_string($product['source_url'] ?? null) ? 'url:'.$product['source_url'] : null));

            if ($dedupeKey !== null && isset($seen[$dedupeKey])) {
                continue;
            }

            if ($dedupeKey !== null) {
                $seen[$dedupeKey] = true;
            }

            $products[] = $product;
        }

        return $products;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = 'scrapers/novicare/product-data.json';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $relativePath = ltrim($path, '/');
        $storagePath = storage_path('app/'.$relativePath);

        if (is_file($storagePath)) {
            return $storagePath;
        }

        $localDiskPath = Storage::disk('local')->path($relativePath);

        if (is_file($localDiskPath)) {
            return $localDiskPath;
        }

        return $storagePath;
    }

    private function productStatusOption(): ProductStatus
    {
        $value = trim((string) $this->option('status'));
        $value = $value !== '' ? $value : ProductStatus::DRAFT->value;
        $status = ProductStatus::tryFrom($value);

        if ($status === null) {
            throw new InvalidArgumentException('Invalid --status value. Use draft, active, or archived.');
        }

        return $status;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(1, (int) $value);
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
            return 50;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function productName(array $product): string
    {
        return is_string($product['name'] ?? null) && trim($product['name']) !== ''
            ? trim($product['name'])
            : '[unnamed Novicare product]';
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function sourceUrl(array $product): ?string
    {
        if (is_string($product['canonical_url'] ?? null)) {
            return $product['canonical_url'];
        }

        return is_string($product['source_url'] ?? null) ? $product['source_url'] : null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<list<string>>
     */
    private function categoryPaths(array $product): array
    {
        $paths = [];

        foreach (($product['category_paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }

            $segments = array_values(array_filter(
                array_map(
                    static fn (mixed $segment): string => is_string($segment) ? trim($segment) : '',
                    $path,
                ),
                static fn (string $segment): bool => $segment !== '',
            ));

            if ($segments !== []) {
                $paths[] = $segments;
            }
        }

        if ($paths === [] && is_array($product['categories'] ?? null)) {
            $segments = array_values(array_filter(
                array_map(
                    static fn (mixed $segment): string => is_string($segment) ? trim($segment) : '',
                    $product['categories'],
                ),
                static fn (string $segment): bool => $segment !== '',
            ));

            if ($segments !== []) {
                $paths[] = $segments;
            }
        }

        if ($paths === [] && is_string($product['category'] ?? null) && trim($product['category']) !== '') {
            $paths[] = [trim($product['category'])];
        }

        return $paths;
    }
}
