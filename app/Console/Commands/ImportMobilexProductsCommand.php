<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mobilex\MobilexProductImporter;
use Illuminate\Console\Command;

final class ImportMobilexProductsCommand extends Command
{
    protected $signature = 'mobilex:import
        {--data=mobilex/product-data.json : Inspected Mobilex product-data JSON path. Relative paths are resolved under storage/app.}
        {--dry-run : Validate and summarize the import without writing to the database or downloading images.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--no-images : Do not download or sync product images.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import Mobilex products from inspected JSON into active Konji Shop products.';

    public function __construct(
        private readonly MobilexProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('data'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Mobilex products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');

        $this->info('Importing Mobilex products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));

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

            $name = is_string($productData['name'] ?? null) ? $productData['name'] : '[unnamed Mobilex product]';
            $sourceUrl = is_string($productData['source_url'] ?? null) ? $productData['source_url'] : null;

            $this->line(sprintf('Importing product %d/%d: %s', $index + 1, $total, $name));

            if ($sourceUrl !== null) {
                $this->line('  '.$sourceUrl);
            }

            try {
                $result = $this->importer->import($productData, $importImages);
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
                    '  Imported product ID %d, variants: %d, images: %d, product attributes: %d',
                    $product->id,
                    $product->variants->count(),
                    $product->images->count(),
                    $product->attributeValues->count(),
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
            $this->warn('Failed Mobilex imports:');

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
        $attributeKeys = [];
        $attributeValueKeys = [];
        $imageCount = 0;
        $variantCount = 0;

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $category = is_array($product['category'] ?? null) ? $product['category'] : [];

            foreach (['top_name', 'name'] as $key) {
                if (is_string($category[$key] ?? null) && trim($category[$key]) !== '') {
                    $categoryKeys[trim($category[$key])] = true;
                }
            }

            foreach (($product['attributes'] ?? []) as $attribute) {
                if (! is_array($attribute) || ! $this->isImportableProductAttribute($attribute)) {
                    continue;
                }

                $label = is_string($attribute['label'] ?? null) ? trim($attribute['label']) : '';
                $value = is_string($attribute['value'] ?? null) ? trim($attribute['value']) : '';

                if ($label !== '') {
                    $attributeKeys[$label] = true;
                }

                if ($label !== '' && $value !== '') {
                    $attributeValueKeys[$label.'|'.$value] = true;
                }
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $variantCandidates = is_array($product['variant_candidates'] ?? null) ? count($product['variant_candidates']) : 0;
            $variantCount += max(1, $variantCandidates);
        }

        $this->info('Dry-run summary. No database writes were made.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Categories to create/update: '.count($categoryKeys));
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Attributes to create/update: '.count($attributeKeys));
        $this->line('Attribute values to create/update: '.count($attributeValueKeys));
        $this->line('Variants to create/update: '.$variantCount);
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    private function isImportableProductAttribute(array $attribute): bool
    {
        $label = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) ($attribute['label'] ?? $attribute['name'] ?? '')) ?: ''));
        $code = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) ($attribute['code'] ?? '')) ?: ''));

        return in_array($code, ['producent', 'producer', 'manufacturer'], true)
            || in_array($label, ['producent', 'producer', 'manufacturer'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Mobilex product-data file not found: '.$path);

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Mobilex product-data file does not contain a products array: '.$path);

            return [];
        }

        $products = [];
        $seen = [];

        foreach ($decoded['products'] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $dedupeKey = is_string($product['source_url'] ?? null)
                ? $product['source_url']
                : (is_string($product['external_product_id'] ?? null) ? $product['external_product_id'] : null);

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
            $path = 'mobilex/product-data.json';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return storage_path('app/'.ltrim($path, '/'));
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
}
