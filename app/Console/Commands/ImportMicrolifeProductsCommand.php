<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Microlife\MicrolifeProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ImportMicrolifeProductsCommand extends Command
{
    protected $signature = 'microlife:import
        {--from=scrapers/microlife/product-data.json : Microlife product-data JSON path. Relative paths are resolved under storage/app.}
        {--dry-run : Validate and summarize the import without database writes or asset downloads.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--status=draft : Product status to assign: draft, active, or archived.}
        {--no-images : Do not download or sync product images.}
        {--no-documents : Do not download PDF/ZIP documents; keep source links in descriptions.}
        {--image-limit=50 : Maximum number of product images per product. Use 0 for no limit.}
        {--asset-timeout=30 : Timeout in seconds for each Microlife image or document request.}
        {--asset-attempts=5 : Maximum attempts for each Microlife asset request.}
        {--asset-retry-delay-ms=5000 : Milliseconds to wait between Microlife asset retry attempts.}
        {--asset-request-delay-ms=250 : Milliseconds to pause between Microlife asset downloads.}
        {--insecure : Disable TLS certificate verification for Microlife document downloads.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import Microlife scraped JSON into Konji Shop products.';

    public function __construct(
        private readonly MicrolifeProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('from'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Microlife products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $status = $this->productStatusOption();
        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $importDocuments = ! $dryRun && ! (bool) $this->option('no-documents');
        $imageLimit = $this->imageLimitOption();
        $assetTimeoutSeconds = $this->positiveIntOption('asset-timeout', 30);
        $assetAttempts = $this->positiveIntOption('asset-attempts', 5);
        $assetRetryDelayMs = $this->nonNegativeIntOption('asset-retry-delay-ms', 5000);
        $assetRequestDelayMs = $this->nonNegativeIntOption('asset-request-delay-ms', 250);
        $verifyTls = ! (bool) $this->option('insecure');

        $this->info('Importing Microlife products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Status: '.$status->value);
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Documents: '.($importDocuments ? 'download and localize' : 'source links only'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('TLS verification: '.($verifyTls ? 'enabled' : 'disabled'));

        if ($importImages || $importDocuments) {
            $this->line('Asset timeout: '.$assetTimeoutSeconds.' second(s)');
            $this->line('Asset attempts: '.$assetAttempts);
            $this->line('Asset retry delay: '.$assetRetryDelayMs.' ms');
            $this->line('Asset request delay: '.$assetRequestDelayMs.' ms');
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
                    importDocuments: $importDocuments,
                    assetTimeoutSeconds: $assetTimeoutSeconds,
                    assetAttempts: $assetAttempts,
                    assetRetryDelayMs: $assetRetryDelayMs,
                    assetRequestDelayMs: $assetRequestDelayMs,
                    verifyTls: $verifyTls,
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
            $this->warn('Failed Microlife imports:');

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
        $documentCount = 0;
        $variantCount = 0;
        $medicalDeviceCount = 0;
        $consumerCount = 0;
        $professionalCount = 0;
        $sourceWarningCount = 0;

        foreach ($products as $product) {
            foreach ($this->categoryPaths($product) as $path) {
                $categoryPaths[implode(' > ', $path)] = true;
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $documentCount += is_array($product['downloads'] ?? null) ? count($product['downloads']) : 0;
            $variantCount += is_array($product['variant_candidates'] ?? null) && $product['variant_candidates'] !== []
                ? count($product['variant_candidates'])
                : 1;

            if (($product['catalogue_type'] ?? null) === 'professional') {
                $professionalCount++;
            } else {
                $consumerCount++;
            }

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }

            $sourceWarningCount += is_array($product['warnings'] ?? null)
                ? count(array_filter($product['warnings'], 'is_string'))
                : 0;
        }

        $this->info('Dry-run summary. No database writes were made. No images or documents were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('- Consumer products: '.$consumerCount);
        $this->line('- Professional products: '.$professionalCount);
        $this->line('Distinct category paths: '.count($categoryPaths));
        $this->line('Source brand: Microlife');
        $this->line('Variants to create/update: '.$variantCount);
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Downloads discovered: '.$documentCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
        $this->line('Source crawl warnings: '.$sourceWarningCount);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Microlife product-data file not found: '.$path);

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Microlife product-data file is not valid JSON: '.$exception->getMessage());

            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Microlife product-data file does not contain a products array: '.$path);

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
            $path = 'scrapers/microlife/product-data.json';
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
            : '[unnamed Microlife product]';
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
