<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VatRate;
use App\Services\Seni24\Seni24ProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;

final class ImportSeni24ProductsCommand extends Command
{
    protected $signature = 'seni24:import
        {--from=scrapers/seni24/products-full-v1.json : Frozen Seni24 full product-data JSON path. Relative paths are resolved under the local disk.}
        {--dry-run : Validate and summarize without writing to the database or downloading images.}
        {--limit= : Maximum number of products to import.}
        {--offset=0 : Number of products to skip before importing.}
        {--vat-rate= : Reviewed fallback VAT rate when neither variant nor product data contains explicit VAT. Allowed: 0, 5, 8, 23.}
        {--vat-map= : Optional JSON map of external_product_id to reviewed fallback VAT rate.}
        {--no-images : Do not download or sync product images.}
        {--image-limit=10 : Maximum number of images to import per product. Use 0 for no limit.}
        {--show-failures : Print failed product imports at the end.}';

    protected $description = 'Import fully resolved Seni24 scraped JSON into Konji Shop as draft products.';

    public function __construct(
        private readonly Seni24ProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataPath = $this->resolvePath((string) $this->option('from'));
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Seni24 products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $fallbackVatRate = $this->vatRateOption() ?? $this->configuredDefaultVatRate();
        $vatMap = $this->configuredVatMap();
        $overrideVatMap = $this->vatMapOption();

        if ($overrideVatMap !== []) {
            $vatMap = array_replace($vatMap, $overrideVatMap);
        }

        $selectedProducts = $this->applyVatMap($selectedProducts, $vatMap);
        $blockers = $this->preflightBlockers($selectedProducts, $fallbackVatRate);
        $importImages = ! $dryRun && ! (bool) $this->option('no-images');
        $imageLimit = $this->imageLimitOption();

        $this->info('Importing Seni24 products from: '.$dataPath);
        $this->line('Source products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database import'));
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('VAT map entries: '.count($vatMap));
        $this->line('VAT fallback rate: '.($fallbackVatRate?->value !== null
            ? (string) $fallbackVatRate->value.'%'
            : 'none'));
        $this->line('Preflight blockers: '.count($blockers));

        if ($dryRun) {
            $this->printDryRunSummary($selectedProducts, $fallbackVatRate, $blockers);

            return $blockers === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($blockers !== []) {
            $this->error(
                'Seni24 import blocked before database writes: '
                .count($blockers)
                .' selected products have incomplete or unsafe commerce data.'
            );

            foreach ($blockers as $blocker) {
                $this->line(
                    '- '.$blocker['external_id']
                    .' | '.$blocker['name']
                    .' | '.$blocker['reason']
                );
            }

            return self::FAILURE;
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
                : '[unnamed Seni24 product]';
            $sourceUrl = is_string($productData['canonical_url'] ?? null)
                ? $productData['canonical_url']
                : (is_string($productData['source_url'] ?? null) ? $productData['source_url'] : null);

            $this->line(sprintf('Importing product %d/%d: %s', $index + 1, $total, $name));

            if ($sourceUrl !== null) {
                $this->line('  '.$sourceUrl);
            }

            try {
                $result = $this->importer->import(
                    $productData,
                    $fallbackVatRate,
                    $importImages,
                    $imageLimit,
                );
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
            $this->warn('Failed Seni24 imports:');

            foreach ($failures as $failure) {
                $this->line(
                    '- '.$failure['name']
                    .' — '.($failure['url'] ?? '[missing url]')
                    .' — '.$failure['error']
                );
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  list<array{external_id: string, name: string, reason: string}>  $blockers
     */
    private function printDryRunSummary(
        array $products,
        ?VatRate $fallbackVatRate,
        array $blockers,
    ): void {
        $categoryKeys = [];
        $imageCount = 0;
        $variantCount = 0;
        $medicalDeviceCount = 0;
        $refundableCount = 0;
        $variantVatCounts = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            foreach (($product['source_category_path'] ?? []) as $category) {
                if (is_string($category) && trim($category) !== '') {
                    $categoryKeys[trim($category)] = true;
                }
            }

            $imageCount += is_array($product['images'] ?? null)
                ? count($product['images'])
                : 0;

            foreach (($product['variant_candidates'] ?? []) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $variantCount++;
                $rate = $this->resolveVatRateValue(
                    $candidate['vat_rate'] ?? null,
                    $product['vat_rate'] ?? null,
                    $fallbackVatRate,
                );

                $key = $rate?->value !== null ? (string) $rate->value : 'missing';
                $variantVatCounts[$key] = ($variantVatCounts[$key] ?? 0) + 1;
            }

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }

            if (filter_var($product['is_refundable'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $refundableCount++;
            }
        }

        ksort($variantVatCounts);

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Categories referenced: '.count($categoryKeys));
        $this->line('Variants to create/update: '.$variantCount);
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
        $this->line('Refundable products: '.$refundableCount);
        $this->line('Variant VAT distribution: '.json_encode($variantVatCounts, JSON_UNESCAPED_SLASHES));

        if ($blockers !== []) {
            $this->warn('Import blockers:');

            foreach ($blockers as $blocker) {
                $this->line(
                    '- '.$blocker['external_id']
                    .' | '.$blocker['name']
                    .' | '.$blocker['reason']
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return list<array{external_id: string, name: string, reason: string}>
     */
    private function preflightBlockers(array $products, ?VatRate $fallbackVatRate): array
    {
        $blockers = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $externalId = $this->productIdentifier($product);
            $name = is_string($product['name'] ?? null) && trim($product['name']) !== ''
                ? trim($product['name'])
                : '[unnamed product]';

            if (! ctype_digit($externalId)) {
                $blockers[] = [
                    'external_id' => $externalId,
                    'name' => $name,
                    'reason' => 'missing stable numeric product ID',
                ];
                continue;
            }

            if (! filter_var(
                $product['variant_resolution_complete'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            )) {
                $blockers[] = [
                    'external_id' => $externalId,
                    'name' => $name,
                    'reason' => 'variant matrix is incomplete',
                ];
                continue;
            }

            $candidates = is_array($product['variant_candidates'] ?? null)
                ? $product['variant_candidates']
                : [];

            if ($candidates === []) {
                $blockers[] = [
                    'external_id' => $externalId,
                    'name' => $name,
                    'reason' => 'no resolved variants',
                ];
                continue;
            }

            $seenVariantIds = [];

            foreach ($candidates as $candidate) {
                if (! is_array($candidate)) {
                    $blockers[] = [
                        'external_id' => $externalId,
                        'name' => $name,
                        'reason' => 'invalid variant row',
                    ];
                    break;
                }

                $variantId = $this->scalarString($candidate['external_variant_id'] ?? null);

                if ($variantId === null) {
                    $blockers[] = [
                        'external_id' => $externalId,
                        'name' => $name,
                        'reason' => 'variant missing stable source ID',
                    ];
                    break;
                }

                if (isset($seenVariantIds[$variantId])) {
                    $blockers[] = [
                        'external_id' => $externalId,
                        'name' => $name,
                        'reason' => 'duplicate variant ID '.$variantId,
                    ];
                    break;
                }

                $seenVariantIds[$variantId] = true;

                if (! $this->hasNumericPrice($candidate['price_gross_amount'] ?? null)) {
                    $blockers[] = [
                        'external_id' => $externalId,
                        'name' => $name,
                        'reason' => 'variant '.$variantId.' missing gross price',
                    ];
                    break;
                }

                if ($this->resolveVatRateValue(
                    $candidate['vat_rate'] ?? null,
                    $product['vat_rate'] ?? null,
                    $fallbackVatRate,
                ) === null) {
                    $blockers[] = [
                        'external_id' => $externalId,
                        'name' => $name,
                        'reason' => 'variant '.$variantId.' missing explicit VAT',
                    ];
                    break;
                }
            }
        }

        return $blockers;
    }

    private function resolveVatRateValue(
        mixed $candidateValue,
        mixed $productValue,
        ?VatRate $fallback,
    ): ?VatRate {
        foreach ([$candidateValue, $productValue] as $value) {
            if (is_string($value) && ctype_digit(trim($value))) {
                $value = (int) trim($value);
            }

            if (is_int($value)) {
                $rate = VatRate::tryFrom($value);

                if ($rate !== null) {
                    return $rate;
                }
            }
        }

        return $fallback;
    }

    private function hasNumericPrice(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0;
        }

        return is_string($value)
            && trim($value) !== ''
            && is_numeric(str_replace(',', '.', str_replace(' ', '', trim($value))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Seni24 product-data file not found: '.$path);

            return [];
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->error('Seni24 product-data file is not valid JSON: '.$exception->getMessage());

            return [];
        }

        if (! is_array($decoded)
            || ! isset($decoded['products'])
            || ! is_array($decoded['products'])) {
            $this->error('Seni24 product-data file does not contain a products array: '.$path);

            return [];
        }

        $products = [];
        $seen = [];

        foreach ($decoded['products'] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $externalId = $this->scalarString($product['external_product_id'] ?? null);
            $canonicalUrl = $this->scalarString($product['canonical_url'] ?? null)
                ?? $this->scalarString($product['source_url'] ?? null);
            $dedupeKey = $externalId !== null
                ? 'id:'.$externalId
                : ($canonicalUrl !== null ? 'url:'.$canonicalUrl : null);

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
            $path = 'scrapers/seni24/products-full-v1.json';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $relativePath = ltrim($path, '/');
        $localDiskPath = Storage::disk('local')->path($relativePath);

        if (is_file($localDiskPath)) {
            return $localDiskPath;
        }

        return storage_path('app/'.$relativePath);
    }

    /**
     * @return array<string, int>
     */
    private function configuredVatMap(): array
    {
        return $this->normalizeVatMap(
            config('seni24.vat_rates', []),
            'Seni24 VAT configuration',
        );
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
            throw new InvalidArgumentException('Seni24 VAT map file not found: '.$path);
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Seni24 VAT map is not valid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return $this->normalizeVatMap($decoded, 'Seni24 VAT map');
    }

    /**
     * @return array<string, int>
     */
    private function normalizeVatMap(mixed $value, string $label): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException($label.' must be an array/object.');
        }

        $map = [];

        foreach ($value as $externalId => $rateValue) {
            if (! is_string($externalId) || trim($externalId) === '') {
                continue;
            }

            if (is_string($rateValue) && ctype_digit(trim($rateValue))) {
                $rateValue = (int) trim($rateValue);
            }

            if (! is_int($rateValue) || VatRate::tryFrom($rateValue) === null) {
                throw new InvalidArgumentException(
                    'Invalid VAT rate for Seni24 product '.$externalId.'. Use 0, 5, 8, or 23.'
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

            $externalId = $this->scalarString($product['external_product_id'] ?? null);

            if ($externalId === null || ! array_key_exists($externalId, $vatMap)) {
                continue;
            }

            /*
             * The reviewed map is a fallback only. Explicit variant VAT parsed
             * from Seni24 still takes precedence inside the importer.
             */
            $product['vat_rate'] = $vatMap[$externalId];
            $products[$index] = $product;
        }

        return $products;
    }

    private function configuredDefaultVatRate(): ?VatRate
    {
        $value = config('seni24.default_vat_rate');

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                'Invalid configured default Seni24 VAT rate. Use 0, 5, 8, or 23.'
            );
        }

        $rate = VatRate::tryFrom($value);

        if ($rate === null) {
            throw new InvalidArgumentException(
                'Invalid configured default Seni24 VAT rate. Use 0, 5, 8, or 23.'
            );
        }

        return $rate;
    }

    private function vatRateOption(): ?VatRate
    {
        $value = $this->option('vat-rate');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (! ctype_digit(trim($value))) {
            throw new InvalidArgumentException(
                'Invalid --vat-rate value. Use 0, 5, 8, or 23.'
            );
        }

        $rate = VatRate::tryFrom((int) trim($value));

        if ($rate === null) {
            throw new InvalidArgumentException(
                'Invalid --vat-rate value. Use 0, 5, 8, or 23.'
            );
        }

        return $rate;
    }

    private function productIdentifier(array $product): string
    {
        return $this->scalarString($product['external_product_id'] ?? null)
            ?? '[missing external_product_id]';
    }

    private function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== ''
            ? max(0, (int) $value)
            : $default;
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
