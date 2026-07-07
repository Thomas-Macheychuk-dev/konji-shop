<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Apolonia\ApoloniaCategoryUrlScraper;
use App\Services\Apolonia\ApoloniaProductUrlScraper;
use Illuminate\Console\Command;
use JsonException;

final class DiscoverApoloniaProductLinksCommand extends Command
{
    protected $signature = 'apolonia:product-links
        {--url=* : Apolonia category/menu page to scan. Defaults to https://www.apolonia.com.pl/.}
        {--categories-from= : Use an existing category discovery JSON file under storage/app instead of discovering categories again.}
        {--category-url=* : Explicit Apolonia category URL to scrape. When used, hierarchy discovery is skipped.}
        {--page-limit= : Maximum number of paginated pages to scrape per category.}
        {--category-limit= : Maximum number of product-scraping categories to scrape. Useful while testing.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Apolonia HTTP request.}
        {--no-progress : Do not print per-category and per-page progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Apolonia URLs.}';

    protected $description = 'Discover Apolonia product URLs from product-scraping categories.';

    public function __construct(
        private readonly ApoloniaProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $categoryUrls = $this->option('category-url');

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if ($categoryUrls !== []) {
            if (! $json) {
                $this->info('Discovering Apolonia product URLs from explicit category URLs...');
            }

            $result = $this->scraper->scrapeCategories($categoryUrls, $this->pageLimit(), $this->categoryLimit());
        } elseif (is_string($this->option('categories-from')) && trim((string) $this->option('categories-from')) !== '') {
            if (! $json) {
                $this->info('Discovering Apolonia product URLs from saved category discovery JSON...');
            }

            $result = $this->scraper->scrapeFromDiscoveredCategories(
                $this->loadCategoryDiscoveryJson((string) $this->option('categories-from')),
                $this->pageLimit(),
                $this->categoryLimit(),
            );
        } else {
            if (! $json) {
                $this->info('Discovering Apolonia product URLs from category hierarchy...');
            }

            $urls = $this->option('url') ?: [ApoloniaCategoryUrlScraper::DEFAULT_URL];
            $result = $this->scraper->scrape($urls, $this->pageLimit(), $this->categoryLimit());
        }

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Source categories: '.count($result['source_categories'] ?? []));
            $this->info('Discovered product URLs: '.count($result['product_urls'] ?? []));

            foreach ($result['category_results'] ?? [] as $categoryResult) {
                $this->line(sprintf(
                    '- %s: %d products, %d page(s)',
                    (string) ($categoryResult['name'] ?? $categoryResult['url'] ?? 'Unknown category'),
                    count($categoryResult['product_urls'] ?? []),
                    (int) ($categoryResult['pages_scraped'] ?? 0),
                ));
            }
        }

        if ((bool) $this->option('show-failures') && ($result['failed_urls'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Failed Apolonia URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return ($result['product_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) $this->option('timeout'));
    }

    private function requestDelayMilliseconds(): int
    {
        return max(0, (int) $this->option('request-delay-ms'));
    }

    private function pageLimit(): ?int
    {
        $value = $this->option('page-limit');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function categoryLimit(): ?int
    {
        $value = $this->option('category-limit');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCategoryDiscoveryJson(string $path): array
    {
        $path = ltrim(trim($path), '/');
        $fullPath = storage_path('app/'.$path);

        if (! is_file($fullPath)) {
            $alternatePath = 'private/'.$path;
            $alternateFullPath = storage_path('app/'.$alternatePath);

            if (is_file($alternateFullPath)) {
                $path = $alternatePath;
                $fullPath = $alternateFullPath;
            }
        }

        if (! is_file($fullPath) && str_starts_with($path, 'private/')) {
            $alternatePath = substr($path, strlen('private/'));
            $alternateFullPath = storage_path('app/'.$alternatePath);

            if (is_file($alternateFullPath)) {
                $path = $alternatePath;
                $fullPath = $alternateFullPath;
            }
        }

        if (! is_file($fullPath)) {
            throw new JsonException('Category discovery JSON file does not exist: storage/app/'.$path);
        }

        $data = json_decode((string) file_get_contents($fullPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new JsonException('Category discovery JSON file does not contain an object: storage/app/'.$path);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveJson(string $path, array $data): void
    {
        $path = ltrim(trim($path), '/');
        $fullPath = storage_path('app/'.$path);

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info('Saved product-link discovery result to storage/app/'.$path);
    }
}
