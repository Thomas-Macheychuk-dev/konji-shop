<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\MedReha\MedRehaCategoryUrlScraper;
use App\Services\MedReha\MedRehaProductDataCrawler;
use App\Services\MedReha\MedRehaProductImporter;
use App\Services\MedReha\MedRehaProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class RunMedRehaPipelineCommand extends Command
{
    protected $signature = 'medreha:pipeline
        {--categories-path=scrapers/medreha/categories.json : Category discovery JSON path under storage/app.}
        {--product-links-path=scrapers/medreha/product-links.json : Product-link discovery JSON path under storage/app.}
        {--product-data-path=scrapers/medreha/full-product-data.json : Full product-data JSON path under storage/app.}
        {--resume : Reuse the latest available MedReha JSON stage instead of starting from category discovery.}
        {--category-limit= : Maximum number of product-scraping categories to scrape. Useful while testing.}
        {--page-limit= : Maximum number of paginated pages to scrape per category.}
        {--limit= : Maximum number of product URLs to crawl or products to import when resuming from product-data JSON.}
        {--offset=0 : Number of product URLs/products to skip.}
        {--status=draft : Product status to assign during import: draft, active, or archived.}
        {--dry-run : Run discovery/crawl stages and summarize the import without database writes or image downloads.}
        {--no-import : Stop after saving full product-data JSON.}
        {--no-images : Do not download or sync product images during import.}
        {--image-limit=10 : Maximum number of images to import per product. Use 0 for no limit.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each MedReha HTTP request.}
        {--show-failures : Print failed MedReha URLs/imports at the end.}';

    protected $description = 'Run the MedReha category discovery, product URL discovery, product data crawl, and optional product import pipeline.';

    public function __construct(
        private readonly MedRehaCategoryUrlScraper $categoryScraper,
        private readonly MedRehaProductUrlScraper $productUrlScraper,
        private readonly MedRehaProductDataCrawler $productDataCrawler,
        private readonly MedRehaProductImporter $productImporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $categoriesPath = $this->relativePathOption('categories-path', 'scrapers/medreha/categories.json');
        $productLinksPath = $this->relativePathOption('product-links-path', 'scrapers/medreha/product-links.json');
        $productDataPath = $this->relativePathOption('product-data-path', 'scrapers/medreha/full-product-data.json');
        $resume = (bool) $this->option('resume');
        $dryRun = (bool) $this->option('dry-run');
        $noImport = (bool) $this->option('no-import');

        $this->configureScrapers();

        $this->info('Running MedReha full pipeline...');
        $this->line('Resume: '.($resume ? 'yes' : 'no'));
        $this->line('Categories JSON: storage/app/'.$categoriesPath);
        $this->line('Product links JSON: storage/app/'.$productLinksPath);
        $this->line('Product data JSON: storage/app/'.$productDataPath);
        $this->line('Import mode: '.($noImport ? 'disabled' : ($dryRun ? 'dry-run' : 'database import')));

        $categoryDiscovery = null;
        $productLinkDiscovery = null;
        $productData = null;
        $productDataLoadedFromResume = false;

        if ($resume && $this->jsonExists($productDataPath)) {
            $this->info('Resuming from full product-data JSON. Discovery and crawl stages will be skipped.');
            $productData = $this->loadJson($productDataPath, 'full product-data JSON');
            $productDataLoadedFromResume = true;
        } else {
            if ($resume && $this->jsonExists($productLinksPath)) {
                $this->info('Resuming from product-link discovery JSON. Category discovery will be skipped.');
                $productLinkDiscovery = $this->loadJson($productLinksPath, 'product-link discovery JSON');
            } else {
                if ($resume && $this->jsonExists($categoriesPath)) {
                    $this->info('Resuming from category discovery JSON.');
                    $categoryDiscovery = $this->loadJson($categoriesPath, 'category discovery JSON');
                } else {
                    $this->info('Step 1/4: discovering MedReha categories...');
                    $categoryDiscovery = $this->categoryScraper->scrape([MedRehaCategoryUrlScraper::DEFAULT_URL]);
                    $this->saveJson($categoriesPath, $categoryDiscovery);
                }

                if (($categoryDiscovery['product_category_urls'] ?? []) === []) {
                    $this->error('No MedReha product-scraping category URLs were discovered.');
                    $this->printFailures('Category discovery failures', $categoryDiscovery['failed_urls'] ?? []);

                    return self::FAILURE;
                }

                $this->info('Category URLs discovered: '.count($categoryDiscovery['category_urls'] ?? []));
                $this->info('Product-scraping category URLs: '.count($categoryDiscovery['product_category_urls'] ?? []));

                $this->info('Step 2/4: discovering MedReha product URLs...');
                $productLinkDiscovery = $this->productUrlScraper->scrapeFromDiscoveredCategories(
                    $categoryDiscovery,
                    $this->nullablePositiveIntOption('page-limit'),
                    $this->nullablePositiveIntOption('category-limit'),
                );
                $this->saveJson($productLinksPath, $productLinkDiscovery);
            }

            if (($productLinkDiscovery['product_urls'] ?? []) === []) {
                $this->error('No MedReha product URLs were discovered.');
                $this->printFailures('Product-link discovery failures', $productLinkDiscovery['failed_urls'] ?? []);

                return self::FAILURE;
            }

            $this->info('Product URLs discovered: '.count($productLinkDiscovery['product_urls'] ?? []));

            $this->info('Step 3/4: crawling MedReha product data...');
            $productData = $this->productDataCrawler->crawlFromProductLinkDiscovery(
                $productLinkDiscovery,
                $this->nullablePositiveIntOption('limit'),
                $this->nonNegativeIntOption('offset', 0),
            );
            $this->saveJson($productDataPath, $productData);
        }

        if (! is_array($productData) || ($productData['products'] ?? []) === []) {
            $this->error('No MedReha product data is available for import.');
            $this->printFailures('Product data crawl failures', is_array($productData) ? ($productData['failed_urls'] ?? []) : []);

            return self::FAILURE;
        }

        $this->info('Products crawled: '.count($productData['products'] ?? []));
        $this->line('Product data failed URLs: '.count($productData['failed_urls'] ?? []));

        if ((bool) ($productData['stopped_early'] ?? false)) {
            $this->warn('Product data crawl stopped early: '.($productData['stop_reason'] ?? 'unknown reason'));
        }

        if ($noImport) {
            $this->info('MedReha pipeline stopped before import because --no-import was used.');
            $this->maybePrintAllFailures($categoryDiscovery, $productLinkDiscovery, $productData);

            return self::SUCCESS;
        }

        $productsForImport = $this->productsForImport($productData, $productDataLoadedFromResume);

        if ($productsForImport === []) {
            $this->error('No MedReha products selected for import.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->printDryRunSummary($productsForImport);
            $this->maybePrintAllFailures($categoryDiscovery, $productLinkDiscovery, $productData);

            return self::SUCCESS;
        }

        $status = $this->productStatusOption();
        $importImages = ! (bool) $this->option('no-images');
        $imageLimit = $this->imageLimitOption();

        $this->info('Step 4/4: importing MedReha products...');
        $this->line('Selected products for import: '.count($productsForImport));
        $this->line('Status: '.$status->value);
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));

        $imported = 0;
        $warnings = [];
        $failures = [];
        $total = count($productsForImport);

        foreach ($productsForImport as $index => $productData) {
            $name = $this->productName($productData);
            $sourceUrl = $this->productUrl($productData);

            $this->line(sprintf('Importing product %d/%d: %s', $index + 1, $total, $name));

            if ($sourceUrl !== null) {
                $this->line('  '.$sourceUrl);
            }

            try {
                $result = $this->productImporter->import($productData, $status, $importImages, $imageLimit);
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
        $this->line('Import failures: '.count($failures));

        $this->maybePrintAllFailures($categoryDiscovery, $productLinkDiscovery, $productData);
        $this->printImportFailures($failures);

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function configureScrapers(): void
    {
        $timeout = $this->positiveIntOption('timeout', 15);
        $delay = $this->nonNegativeIntOption('request-delay-ms', 500);

        $this->categoryScraper
            ->withTimeout($timeout)
            ->withRequestDelayMilliseconds($delay)
            ->withProgressCallback(fn (string $message): null => $this->line($message));

        $this->productUrlScraper
            ->withTimeout($timeout)
            ->withRequestDelayMilliseconds($delay)
            ->withProgressCallback(fn (string $message): null => $this->line($message));

        $this->productDataCrawler
            ->withTimeout($timeout)
            ->withRequestDelayMilliseconds($delay)
            ->withProgressCallback(fn (string $message): null => $this->line($message));
    }

    /**
     * @param  array<string, mixed>  $productData
     * @return list<array<string, mixed>>
     */
    private function productsForImport(array $productData, bool $productDataLoadedFromResume): array
    {
        $products = [];

        foreach (($productData['products'] ?? []) as $product) {
            if (is_array($product)) {
                $products[] = $product;
            }
        }

        if (! $productDataLoadedFromResume) {
            return $products;
        }

        return array_slice(
            $products,
            $this->nonNegativeIntOption('offset', 0),
            $this->nullablePositiveIntOption('limit'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function printDryRunSummary(array $products): void
    {
        $categoryKeys = [];
        $imageCount = 0;
        $variantCount = 0;
        $medicalDeviceCount = 0;

        foreach ($products as $product) {
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
            $variantCount += is_array($product['variant_candidates'] ?? null) && count($product['variant_candidates']) > 0
                ? count($product['variant_candidates'])
                : 1;

            if (filter_var($product['is_medical_device'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $medicalDeviceCount++;
            }
        }

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to import/update: '.count($products));
        $this->line('Categories to create/update: '.count($categoryKeys));
        $this->line('Variants to create/update: '.$variantCount);
        $this->line('Product images discovered: '.$imageCount);
        $this->line('Medical device products: '.$medicalDeviceCount);
    }

    private function jsonExists(string $relativePath): bool
    {
        return Storage::disk('local')->exists($relativePath) || is_file(storage_path('app/'.$relativePath));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $relativePath, string $label): array
    {
        if (Storage::disk('local')->exists($relativePath)) {
            $contents = Storage::disk('local')->get($relativePath);
        } else {
            $path = storage_path('app/'.$relativePath);
            $contents = is_file($path) ? file_get_contents($path) : false;
        }

        if (! is_string($contents)) {
            throw new JsonException('Unable to read MedReha '.$label.': storage/app/'.$relativePath);
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('MedReha '.$label.' does not contain a JSON object: storage/app/'.$relativePath);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveJson(string $relativePath, array $payload): void
    {
        Storage::disk('local')->put(
            $relativePath,
            json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ),
        );

        $this->line('Saved storage/app/'.$relativePath);
    }

    /**
     * @param  array<string, mixed>|null  $categoryDiscovery
     * @param  array<string, mixed>|null  $productLinkDiscovery
     * @param  array<string, mixed>|null  $productData
     */
    private function maybePrintAllFailures(?array $categoryDiscovery, ?array $productLinkDiscovery, ?array $productData): void
    {
        if (! (bool) $this->option('show-failures')) {
            return;
        }

        $this->printFailures('Category discovery failures', $categoryDiscovery['failed_urls'] ?? []);
        $this->printFailures('Product-link discovery failures', $productLinkDiscovery['failed_urls'] ?? []);
        $this->printFailures('Product data crawl failures', $productData['failed_urls'] ?? []);
    }

    /**
     * @param  mixed  $failures
     */
    private function printFailures(string $title, mixed $failures): void
    {
        if (! is_array($failures) || $failures === []) {
            return;
        }

        $this->newLine();
        $this->warn($title.':');

        foreach ($failures as $url => $reason) {
            $this->line($url.' - '.$reason);
        }
    }

    /**
     * @param  list<array{name: string, url: string|null, error: string}>  $failures
     */
    private function printImportFailures(array $failures): void
    {
        if (! (bool) $this->option('show-failures') || $failures === []) {
            return;
        }

        $this->newLine();
        $this->warn('Import failures:');

        foreach ($failures as $failure) {
            $this->line('- '.$failure['name'].' — '.($failure['url'] ?? '[missing url]').' — '.$failure['error']);
        }
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function productName(array $product): string
    {
        return is_string($product['name'] ?? null) && trim($product['name']) !== ''
            ? trim($product['name'])
            : '[unnamed MedReha product]';
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function productUrl(array $product): ?string
    {
        if (is_string($product['canonical_url'] ?? null) && trim($product['canonical_url']) !== '') {
            return trim($product['canonical_url']);
        }

        if (is_string($product['source_url'] ?? null) && trim($product['source_url']) !== '') {
            return trim($product['source_url']);
        }

        return null;
    }

    private function productStatusOption(): ProductStatus
    {
        $value = trim((string) $this->option('status'));
        $status = ProductStatus::tryFrom($value !== '' ? $value : ProductStatus::DRAFT->value);

        if (! $status instanceof ProductStatus) {
            throw new \InvalidArgumentException('Invalid --status value. Use draft, active, or archived.');
        }

        return $status;
    }

    private function relativePathOption(string $name, string $default): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return ltrim($value, '/');
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : $default;
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
