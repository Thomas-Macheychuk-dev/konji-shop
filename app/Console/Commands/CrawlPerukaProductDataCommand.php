<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Peruka\PerukaCategoryUrlScraper;
use App\Services\Peruka\PerukaProductDataCrawler;
use Illuminate\Console\Command;

final class CrawlPerukaProductDataCommand extends Command
{
    protected $signature = 'peruka:crawl-product-data
        {--product=* : Peruka product URL to scrape. Can be used multiple times.}
        {--category=* : Peruka category URL to scan for product URLs. If omitted with no --product, categories are discovered from --url pages.}
        {--url=* : Peruka page to scan for category navigation when --category and --product are omitted. Defaults to https://www.peruka.pl/.}
        {--limit= : Stop after scraping this many unique products.}
        {--max-pages= : Stop after visiting this many paginated pages per category during product URL discovery.}
        {--category-limit= : Stop after scanning this many category URLs during product URL discovery. Useful for smoke tests.}
        {--json : Print the crawl result as JSON.}
        {--save= : Save the crawl result as JSON under storage/app.}
        {--show-failures : Print failed Peruka product URLs.}
        {--request-delay-ms=500 : Milliseconds to pause before each Peruka HTTP request.}';

    protected $description = 'Scrape Peruka product data and enqueue colour links as independent products with URL and external-ID deduplication.';

    public function __construct(
        private readonly PerukaProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $productUrls = $this->option('product') ?: [];
        $categoryUrls = $this->option('category') ?: [];
        $startUrls = $this->option('url') ?: [PerukaCategoryUrlScraper::DEFAULT_URL];
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $maxPages = $this->option('max-pages') !== null ? max(1, (int) $this->option('max-pages')) : null;
        $categoryLimit = $this->option('category-limit') !== null ? max(1, (int) $this->option('category-limit')) : null;
        $shouldDiscoverCategories = $productUrls === [] && $categoryUrls === [];

        $this->crawler
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Crawling Peruka product data...');

        $result = $this->crawler->crawl(
            productUrls: $productUrls,
            categoryUrls: $categoryUrls,
            startUrls: $startUrls,
            discoverCategories: $shouldDiscoverCategories,
            limit: $limit,
            maxPages: $maxPages,
            categoryLimit: $categoryLimit,
        );

        $this->info('Initial product URLs: '.count($result['initial_product_urls']));
        $this->info('Discovered variant product URLs: '.count($result['variant_product_urls']));
        $this->info('Scraped unique products: '.$result['product_count']);
        $this->info('Skipped duplicate URLs: '.count($result['skipped_duplicate_urls']));
        $this->info('Skipped duplicate external IDs: '.count($result['skipped_duplicate_external_ids']));
        $this->info('Failed URLs: '.count($result['failed_urls']));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['products'] as $product) {
                $this->line(($product['external_product_id'] ?? 'no-id').' - '.($product['name'] ?? 'Unnamed product').' - '.($product['source_url'] ?? ''));
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Peruka product URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        if (! is_string($value) || trim($value) === '') {
            return 500;
        }

        return max(0, (int) $value);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Saved Peruka product-data crawl result to storage/app/'.$relativePath);
    }
}
