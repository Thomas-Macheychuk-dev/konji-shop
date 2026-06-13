<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mobilex\MobilexCategoryUrlScraper;
use App\Services\Mobilex\MobilexProductUrlScraper;
use Illuminate\Console\Command;

final class DiscoverMobilexProductLinksCommand extends Command
{
    protected $signature = 'mobilex:product-links
        {--url=* : Mobilex products/category index URL to scan. Defaults to https://mobilex.pl/produkty/.}
        {--category-url=* : Explicit Mobilex category URL to scrape. When used, hierarchy discovery is skipped.}
        {--page-limit= : Maximum number of paginated pages to scrape per category.}
        {--category-limit= : Maximum number of product-scraping categories to scrape. Useful while testing.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Mobilex HTTP request.}
        {--no-progress : Do not print per-category and per-page progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Mobilex URLs.}';

    protected $description = 'Discover Mobilex product URLs from product-scraping categories.';

    public function __construct(
        private readonly MobilexProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pageLimit = $this->pageLimit();
        $categoryLimit = $this->categoryLimit();
        $timeoutSeconds = $this->timeoutSeconds();
        $categoryUrls = $this->option('category-url');

        $this->scraper
            ->withTimeout($timeoutSeconds)
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! (bool) $this->option('json') && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if ($categoryUrls !== []) {
            $this->info('Discovering Mobilex product URLs from explicit category URLs...');

            $result = $this->scraper->scrapeCategories($categoryUrls, $pageLimit, $categoryLimit);
        } else {
            $urls = $this->option('url') ?: [MobilexCategoryUrlScraper::DEFAULT_PRODUCTS_URL];

            $this->info('Discovering Mobilex product URLs from category hierarchy...');

            $result = $this->scraper->scrape($urls, $pageLimit, $categoryLimit);
        }

        $productUrls = $result['product_urls'];

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Source product categories: '.count($result['source_categories']));
        $this->info('Discovered product URLs: '.count($productUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printResult($result);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Mobilex URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $productUrls === [] ? self::FAILURE : self::SUCCESS;
    }

    private function pageLimit(): ?int
    {
        $value = $this->option('page-limit');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }


    private function categoryLimit(): ?int
    {
        $value = $this->option('category-limit');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');

        if (! is_string($value) || trim($value) === '') {
            return 15;
        }

        $timeout = (int) $value;

        return $timeout > 0 ? $timeout : 15;
    }


    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        if (! is_string($value) || trim($value) === '') {
            return 500;
        }

        $milliseconds = (int) $value;

        return max(0, $milliseconds);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printResult(array $result): void
    {
        $this->newLine();
        $this->line('Products by product-scraping category:');

        foreach ($result['category_results'] as $categoryResult) {
            $this->line('- '.$categoryResult['name']);
            $this->line('  '.$categoryResult['url']);
            $this->line('  Products: '.$categoryResult['product_count']);

            foreach ($categoryResult['product_urls'] as $productUrl) {
                $this->line('  - '.$productUrl);
            }
        }

        $this->newLine();
        $this->line('Product URLs:');

        foreach ($result['product_urls'] as $productUrl) {
            $this->line($productUrl);
        }
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

        $this->info('Saved product link discovery result to storage/app/'.$relativePath);
    }
}
