<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Vermeiren\VermeirenProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ImportVermeirenProductsCommand extends Command
{
    protected $signature = 'vermeiren:import
        {--from=scrapers/vermeiren/product-data.json : Vermeiren product-data JSON path. Relative paths are resolved under storage/app.}
        {--dry-run : Validate and summarize the import without database writes or asset downloads.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--status=draft : Product status to assign: draft, active, or archived.}
        {--no-images : Do not download or sync product and color images.}
        {--no-color-images : Import product images but skip Vermeiren color swatches.}
        {--no-documents : Do not download documents; keep source links in descriptions.}
        {--image-limit=50 : Maximum product gallery images per product. Use 0 for no limit.}
        {--asset-timeout=30 : Timeout in seconds for each Vermeiren image or document request.}
        {--asset-attempts=5 : Maximum attempts for each Vermeiren asset request.}
        {--asset-retry-delay-ms=5000 : Milliseconds between Vermeiren asset retry attempts.}
        {--asset-request-delay-ms=250 : Milliseconds between Vermeiren asset downloads.}
        {--insecure : Disable TLS certificate verification for Vermeiren asset downloads.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import Vermeiren scraped JSON into Konji Shop products.';

    public function __construct(
        private readonly VermeirenProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('from'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Vermeiren products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $status = $this->productStatusOption();
        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $importColorImages = $importImages && ! (bool) $this->option('no-color-images');
        $importDocuments = ! $dryRun && ! (bool) $this->option('no-documents');
        $imageLimit = $this->imageLimitOption();
        $assetTimeout = $this->positiveIntOption('asset-timeout', 30);
        $assetAttempts = $this->positiveIntOption('asset-attempts', 5);
        $assetRetryDelay = $this->nonNegativeIntOption('asset-retry-delay-ms', 5000);
        $assetRequestDelay = $this->nonNegativeIntOption('asset-request-delay-ms', 250);
        $verifyTls = ! (bool) $this->option('insecure');

        $this->info('Importing Vermeiren products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Status: '.$status->value);
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Product images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Color swatches: '.($importColorImages ? 'download and sync' : 'skipped'));
        $this->line('Documents: '.($importDocuments ? 'download and localize' : 'source links only'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('TLS verification: '.($verifyTls ? 'enabled' : 'disabled'));

        if ($dryRun) {
            $this->printDryRunSummary($selectedProducts);

            return self::SUCCESS;
        }

        $imported = 0;
        $warnings = [];
        $failures = [];
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
                    importColorImages: $importColorImages,
                    assetTimeoutSeconds: $assetTimeout,
                    assetAttempts: $assetAttempts,
                    assetRetryDelayMs: $assetRetryDelay,
                    assetRequestDelayMs: $assetRequestDelay,
                    verifyTls: $verifyTls,
                );
                $product = $result['product'];
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = ['product' => $name, 'warning' => $warning];
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf(
                    '  Imported product ID %d, variants: %d, images: %d, color images: %d, categories: %d',
                    $product->id,
                    $product->variants->count(),
                    $product->images->count(),
                    $product->attributeValueImages->count(),
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
            $this->warn('Failed Vermeiren imports:');

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
        $colorCount = 0;
        $optionCount = 0;
        $documentCount = 0;
        $specificationCount = 0;
        $medicalDeviceCount = 0;

        foreach ($products as $product) {
            foreach (($product['category_paths'] ?? []) as $path) {
                if (! is_array($path)) {
                    continue;
                }

                $segments = array_values(array_filter(array_map(
                    static fn (mixed $segment): string => is_scalar($segment) ? trim((string) $segment) : '',
                    $path,
                ), static fn (string $segment): bool => $segment !== ''));

                if ($segments !== []) {
                    $categoryPaths[implode(' > ', $segments)] = true;
                }
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $colorCount += is_array($product['colors'] ?? null) ? count($product['colors']) : 0;
            $optionCount += is_array($product['options'] ?? null) ? count($product['options']) : 0;
            $documentCount += is_array($product['documents'] ?? null) ? count($product['documents']) : 0;
            $specificationCount += is_array($product['technical_specifications'] ?? null)
                ? count($product['technical_specifications'])
                : 0;

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }
        }

        $this->info('Dry-run summary. No database writes were made. No images or documents were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Distinct category paths: '.count($categoryPaths));
        $this->line('Default variants to create/update: '.count($products));
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Color swatches discovered: '.$colorCount);
        $this->line('Technical specifications discovered: '.$specificationCount);
        $this->line('Options discovered: '.$optionCount);
        $this->line('Documents discovered: '.$documentCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Vermeiren product-data file not found: '.$path);

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Vermeiren product-data file is not valid JSON: '.$exception->getMessage());

            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Vermeiren product-data file does not contain a products array: '.$path);

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
            $path = 'scrapers/vermeiren/product-data.json';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $relativePath = ltrim($path, '/');
        $localDiskPath = Storage::disk('local')->path($relativePath);

        if (is_file($localDiskPath)) {
            return $localDiskPath;
        }

        $storagePath = storage_path('app/'.$relativePath);

        return is_file($storagePath) ? $storagePath : $localDiskPath;
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
        return max(1, $this->nonNegativeIntOption($name, $default));
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
        $name = $product['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : '[unnamed Vermeiren product]';
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function sourceUrl(array $product): ?string
    {
        $url = $product['canonical_url'] ?? $product['source_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }
}
