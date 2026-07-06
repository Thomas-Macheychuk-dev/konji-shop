<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Antar\AntarProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class ImportAntarProductsCommand extends Command
{
    protected $signature = 'antar:import
        {--from=scrapers/antar/product-data.json : Antar product-data JSON path. Relative paths are resolved under storage/app or the local disk.}
        {--dry-run : Validate and summarize the import without writing to the database or downloading files.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--status=draft : Product status to assign: draft, active, or archived.}
        {--no-images : Do not download or sync product images.}
        {--no-documents : Do not download Antar product documents; render document labels as plain text.}
        {--image-limit=10 : Maximum number of images to import per product. Use 0 for no limit.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import Antar scraped JSON into Konji Shop products.';

    public function __construct(
        private readonly AntarProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('from'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Antar products found in data file: '.$dataPath);

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

        $this->info('Importing Antar products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Status: '.$status->value);
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Documents: '.($importDocuments ? 'download and localize' : 'skipped / plain text'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));

        if ($dryRun) {
            $this->printDryRunSummary($selectedProducts);

            return self::SUCCESS;
        }

        $imported = 0;
        $failures = [];
        $warnings = [];
        $total = count($selectedProducts);

        foreach ($selectedProducts as $index => $productData) {
            if (! is_array($productData)) {
                continue;
            }

            $name = is_string($productData['name'] ?? null) && trim($productData['name']) !== ''
                ? $productData['name']
                : '[unnamed Antar product]';
            $sourceUrl = is_string($productData['canonical_url'] ?? null)
                ? $productData['canonical_url']
                : (is_string($productData['source_url'] ?? null) ? $productData['source_url'] : null);

            $this->line(sprintf('Importing product %d/%d: %s', $index + 1, $total, $name));

            if ($sourceUrl !== null) {
                $this->line('  '.$sourceUrl);
            }

            try {
                $result = $this->importer->import($productData, $status, $importImages, $imageLimit, $importDocuments);
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
            } catch (\Throwable $exception) {
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
            $this->warn('Failed Antar imports:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' — '.($failure['url'] ?? '[missing url]').' — '.$failure['error']);
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, mixed>  $products
     */
    private function printDryRunSummary(array $products): void
    {
        $categoryKeys = [];
        $imageCount = 0;
        $documentCount = 0;
        $medicalDeviceCount = 0;
        $missingSkuCount = 0;

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            foreach (($product['source_category_path'] ?? []) as $categoryName) {
                if (is_string($categoryName) && trim($categoryName) !== '') {
                    $categoryKeys[trim($categoryName)] = true;
                }
            }

            foreach (($product['categories'] ?? []) as $categoryName) {
                if (is_string($categoryName) && trim($categoryName) !== '') {
                    $categoryKeys[trim($categoryName)] = true;
                }
            }

            if (($product['categories'] ?? []) === [] && is_string($product['category'] ?? null) && trim($product['category']) !== '') {
                $categoryKeys[trim((string) $product['category'])] = true;
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $documentCount += is_array($product['documents'] ?? null) ? count($product['documents']) : 0;

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }

            if (! is_string($product['sku'] ?? null) || trim((string) $product['sku']) === '') {
                $missingSkuCount++;
            }
        }

        $this->info('Dry-run summary. No database writes were made. No images or documents were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Categories to create/update: '.count($categoryKeys));
        $this->line('Default variants to create/update: '.count($products));
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Documents discovered: '.$documentCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
        $this->line('Products without SKU: '.$missingSkuCount);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Antar product-data file not found: '.$path);

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Antar product-data file is not valid JSON: '.$exception->getMessage());

            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Antar product-data file does not contain a products array: '.$path);

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

            /** @var array<string, mixed> $product */
            $products[] = $product;
        }

        return $products;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = 'scrapers/antar/product-data.json';
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

        if (is_file($storagePath)) {
            return $storagePath;
        }

        return $localDiskPath;
    }

    private function productStatusOption(): ProductStatus
    {
        $value = (string) $this->option('status');
        $value = trim($value) !== '' ? trim($value) : ProductStatus::DRAFT->value;

        $status = ProductStatus::tryFrom($value);

        if ($status === null) {
            throw new \InvalidArgumentException('Invalid --status value. Use draft, active, or archived.');
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
}
