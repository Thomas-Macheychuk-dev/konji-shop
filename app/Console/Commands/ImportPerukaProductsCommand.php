<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Peruka\PerukaProductImporter;
use Illuminate\Console\Command;

final class ImportPerukaProductsCommand extends Command
{
    protected $signature = 'peruka:import
        {--data=scrapers/peruka/full-peruka-product-data.json : Peruka product-data JSON path. Relative paths are resolved under storage/app.}
        {--from= : Alias for --data.}
        {--dry-run : Validate and summarize the import without writing to the database or downloading images.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--no-images : Do not download or sync product images.}
        {--status=draft : Product status for imported products: draft, active, or archived.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import scraped Peruka product-data JSON into Konji Shop products. Colour links are imported as independent products.';

    public function __construct(
        private readonly PerukaProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataOption = is_string($this->option('from')) && trim((string) $this->option('from')) !== ''
            ? (string) $this->option('from')
            : (string) $this->option('data');

        $dataPath = $this->resolvePath($dataOption);
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Peruka products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $productStatus = $this->productStatusOption();

        $this->info('Importing Peruka products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Product status: '.$productStatus->value);
        $this->line('Images: '.($importImages ? 'download and sync' : 'skip'));

        if ($selectedProducts === []) {
            $this->warn('No products selected after offset/limit.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->printDryRunSummary($selectedProducts);

            return self::SUCCESS;
        }

        $imported = 0;
        $warnings = [];
        $failures = [];

        foreach ($selectedProducts as $index => $productData) {
            if (! is_array($productData)) {
                continue;
            }

            $externalId = $this->stringOrNull($productData['external_product_id'] ?? null) ?: '[missing external ID]';
            $name = $this->stringOrNull($productData['name'] ?? null) ?: '[unnamed Peruka product]';
            $sourceUrl = $this->stringOrNull($productData['source_url'] ?? null);

            $this->line(sprintf(
                '[%d/%d] Importing Peruka product %s: %s',
                $index + 1,
                count($selectedProducts),
                $externalId,
                $name,
            ));

            try {
                $result = $this->importer->import(
                    productData: $productData,
                    productStatus: $productStatus,
                    importImages: $importImages,
                );

                $product = $result['product'];
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $name.' — '.$warning;
                    $this->warn('  Warning: '.$warning);
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
            $this->newLine();
            $this->warn('Failed Peruka imports:');

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
        $inStockCount = 0;
        $outOfStockCount = 0;
        $missingExternalIdCount = 0;
        $missingPriceCount = 0;

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            if ($this->stringOrNull($product['external_product_id'] ?? null) === null) {
                $missingExternalIdCount++;
            }

            if ($this->stringOrNull($product['price_gross_amount'] ?? null) === null && ! is_int($product['price_gross_amount'] ?? null) && ! is_float($product['price_gross_amount'] ?? null)) {
                $missingPriceCount++;
            }

            foreach ((is_array($product['categories'] ?? null) ? $product['categories'] : []) as $category) {
                $category = $this->stringOrNull($category);

                if ($category !== null) {
                    $categoryKeys[$category] = true;
                }
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;

            $availability = $this->stringOrNull($product['availability'] ?? null);

            if ($availability === 'out_of_stock') {
                $outOfStockCount++;
            } else {
                $inStockCount++;
            }
        }

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to create/update: '.count($products));
        $this->line('Categories to create/update: '.count($categoryKeys));
        $this->line('Default variants to create/update: '.count($products));
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Products marked in stock/preorder/unknown: '.$inStockCount);
        $this->line('Products marked out of stock: '.$outOfStockCount);
        $this->line('Products missing external ID: '.$missingExternalIdCount);
        $this->line('Products missing gross price: '.$missingPriceCount);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Peruka product-data file not found: '.$path);

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Peruka product-data file does not contain a products array: '.$path);

            return [];
        }

        $products = [];
        $seen = [];

        foreach ($decoded['products'] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $dedupeKey = $this->stringOrNull($product['external_product_id'] ?? null)
                ?: $this->stringOrNull($product['source_url'] ?? null)
                    ?: $this->stringOrNull($product['canonical_url'] ?? null);

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
            return storage_path('app/scrapers/peruka/full-peruka-product-data.json');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return storage_path('app/'.ltrim($path, '/'));
    }

    private function nonNegativeIntOption(string $option, int $default): int
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function nullablePositiveIntOption(string $option): ?int
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function productStatusOption(): ProductStatus
    {
        $value = strtolower(trim((string) $this->option('status')));

        return ProductStatus::tryFrom($value) ?? ProductStatus::DRAFT;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
