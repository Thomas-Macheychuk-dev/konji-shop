<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VatRate;
use App\Services\DrSapporo\DrSapporoProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class ImportDrSapporoProductsCommand extends Command
{
    protected $signature = 'drsapporo:import
        {--from=scrapers/drsapporo/products-full-v2.json : Dr Sapporo full product-data JSON path. Relative paths are resolved under the local disk.}
        {--dry-run : Validate and summarize the import without writing to the database or downloading images.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--vat-rate= : Explicit fallback VAT rate to use when product data and VAT map have no vat_rate. Allowed: 0, 5, 8, 23.}
        {--vat-map= : Optional JSON map of external_product_id to VAT rate (0, 5, 8, or 23).}
        {--no-images : Do not download or sync product images.}
        {--image-limit=10 : Maximum number of images to import per product. Use 0 for no limit.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import Dr Sapporo scraped JSON into Konji Shop as draft products.';

    public function __construct(
        private readonly DrSapporoProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('from'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Dr Sapporo products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $vatRate = $this->vatRateOption();
        $vatMap = $this->vatMapOption();
        $selectedProducts = $this->applyVatMap($selectedProducts, $vatMap);
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $imageLimit = $this->imageLimitOption();

        $this->info('Importing Dr Sapporo products from: '.$dataPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('VAT map entries: '.count($vatMap));
        $this->line('VAT fallback override: '.($vatRate?->value !== null ? (string) $vatRate->value.'%' : 'none'));

        if ($dryRun) {
            $this->printDryRunSummary($selectedProducts, $vatRate);

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
                : '[unnamed Dr Sapporo product]';
            $sourceUrl = is_string($productData['canonical_url'] ?? null)
                ? $productData['canonical_url']
                : (is_string($productData['source_url'] ?? null) ? $productData['source_url'] : null);

            $this->line(sprintf('Importing product %d/%d: %s', $index + 1, $total, $name));

            if ($sourceUrl !== null) {
                $this->line('  '.$sourceUrl);
            }

            try {
                $result = $this->importer->import($productData, $vatRate, $importImages, $imageLimit);
                $product = $result['product'];
                $imported++;

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = ['product' => $name, 'warning' => $warning];
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
            $this->warn('Failed Dr Sapporo imports:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' — '.($failure['url'] ?? '[missing url]').' — '.$failure['error']);
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, mixed>  $products
     */
    private function printDryRunSummary(array $products, ?VatRate $vatRate): void
    {
        $categoryKeys = [];
        $imageCount = 0;
        $variantCount = 0;
        $medicalDeviceCount = 0;
        $productsMissingVat = 0;
        $missingVatProducts = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $category = $product['category'] ?? $product['source_category_name'] ?? null;

            if (is_string($category) && trim($category) !== '') {
                $categoryKeys[trim($category)] = true;
            }

            $imageCount += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $variantCount += is_array($product['variant_candidates'] ?? null) && count($product['variant_candidates']) > 0
                ? count($product['variant_candidates'])
                : 1;

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }

            if (! isset($product['vat_rate']) && $vatRate === null) {
                $productsMissingVat++;
                $missingVatProducts[] = [
                    'external_id' => is_string($product['external_product_id'] ?? null)
                        ? $product['external_product_id']
                        : '[missing external_product_id]',
                    'name' => is_string($product['name'] ?? null)
                        ? $product['name']
                        : '[unnamed product]',
                    'category' => is_string($category ?? null)
                        ? $category
                        : '[missing category]',
                ];
            }
        }

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Categories referenced: '.count($categoryKeys));
        $this->line('Variants to create/update: '.$variantCount);
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
        $this->line('Products without explicit VAT after override: '.$productsMissingVat);

        if ($missingVatProducts !== []) {
            $this->warn('Products still missing explicit VAT:');

            foreach ($missingVatProducts as $product) {
                $this->line(
                    '- '.$product['external_id']
                    .' | '.$product['name']
                    .' | '.$product['category']
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Dr Sapporo product-data file not found: '.$path);

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Dr Sapporo product-data file is not valid JSON: '.$exception->getMessage());

            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Dr Sapporo product-data file does not contain a products array: '.$path);

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
            $path = 'scrapers/drsapporo/products-full-v2.json';
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

        return $storagePath;
    }

    /**
     * @return array<string, int>
     */
    private function vatMapOption(): array
    {
        $value = $this->option('vat-map');

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $path = $this->resolvePath(trim($value));

        if (! is_file($path)) {
            throw new \InvalidArgumentException('Dr Sapporo VAT map file not found: '.$path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException(
                'Dr Sapporo VAT map is not valid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Dr Sapporo VAT map must be a JSON object.');
        }

        $map = [];

        foreach ($decoded as $externalId => $rateValue) {
            if (! is_string($externalId) || trim($externalId) === '') {
                continue;
            }

            if (is_string($rateValue) && ctype_digit(trim($rateValue))) {
                $rateValue = (int) trim($rateValue);
            }

            if (! is_int($rateValue) || VatRate::tryFrom($rateValue) === null) {
                throw new \InvalidArgumentException(
                    'Invalid VAT rate for Dr Sapporo product '.$externalId.'. Use 0, 5, 8, or 23.'
                );
            }

            $map[trim($externalId)] = $rateValue;
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $products
     * @param  array<string, int>  $vatMap
     * @return array<int, mixed>
     */
    private function applyVatMap(array $products, array $vatMap): array
    {
        if ($vatMap === []) {
            return $products;
        }

        foreach ($products as $index => $product) {
            if (! is_array($product)) {
                continue;
            }

            $externalId = $product['external_product_id'] ?? null;

            if (! is_string($externalId) || ! array_key_exists($externalId, $vatMap)) {
                continue;
            }

            $product['vat_rate'] = $vatMap[$externalId];
            $products[$index] = $product;
        }

        return $products;
    }

    private function vatRateOption(): ?VatRate
    {
        $value = $this->option('vat-rate');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (! ctype_digit(trim($value))) {
            throw new \InvalidArgumentException('Invalid --vat-rate value. Use 0, 5, 8, or 23.');
        }

        $rate = VatRate::tryFrom((int) trim($value));

        if ($rate === null) {
            throw new \InvalidArgumentException('Invalid --vat-rate value. Use 0, 5, 8, or 23.');
        }

        return $rate;
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
