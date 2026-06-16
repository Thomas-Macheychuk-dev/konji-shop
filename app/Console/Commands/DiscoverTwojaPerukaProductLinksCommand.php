<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TwojaPeruka\TwojaPerukaCategoryScraper;
use App\Services\TwojaPeruka\TwojaPerukaProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DiscoverTwojaPerukaProductLinksCommand extends Command
{
    protected $signature = 'twojaperuka:product-links
        {--url=* : TwojaPeruka category/menu page to scan. Defaults to https://twojaperuka.pl/peruki.}
        {--categories-from= : Use an existing category discovery JSON file under storage/app instead of discovering categories again.}
        {--category-url=* : Explicit TwojaPeruka category URL to scrape. When used, hierarchy discovery is skipped.}
        {--page-limit= : Maximum number of paginated pages to scrape per category.}
        {--category-limit= : Maximum number of product-scraping categories to scrape. Useful while testing.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each TwojaPeruka HTTP request.}
        {--no-progress : Do not print per-category and per-page progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed TwojaPeruka URLs.}';

    protected $description = 'Discover TwojaPeruka product URLs from allowed product-scraping categories.';

    public function __construct(
        private readonly TwojaPerukaProductUrlScraper $scraper,
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
                $this->info('Discovering TwojaPeruka product URLs from explicit category URLs...');
            }

            $result = $this->scraper->scrapeCategories($categoryUrls, $this->pageLimit(), $this->categoryLimit());
        } elseif (is_string($this->option('categories-from')) && trim((string) $this->option('categories-from')) !== '') {
            if (! $json) {
                $this->info('Discovering TwojaPeruka product URLs from saved category discovery JSON...');
            }

            $result = $this->scraper->scrapeFromDiscoveredCategories(
                $this->loadCategoryDiscoveryJson((string) $this->option('categories-from')),
                $this->pageLimit(),
                $this->categoryLimit(),
            );
        } else {
            if (! $json) {
                $this->info('Discovering TwojaPeruka product URLs from allowed category hierarchy...');
            }

            $urls = $this->option('url') ?: [TwojaPerukaCategoryScraper::DEFAULT_CATEGORY_URL];
            $result = $this->scraper->scrape($urls, $this->pageLimit(), $this->categoryLimit());
        }

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Visited pages: '.count($result['visited_urls']));
            $this->info('Source product categories: '.count($result['source_categories']));
            $this->info('Discovered product URLs: '.count($result['product_urls']));
            $this->printResult($result);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed TwojaPeruka URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return $result['product_urls'] === [] ? self::FAILURE : self::SUCCESS;
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

        return max(0, (int) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCategoryDiscoveryJson(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new JsonException('Unable to read category discovery JSON file: storage/app/'.$relativePath);
            }

            return $this->decodeCategoryDiscoveryJson($contents, 'storage/app/'.$relativePath);
        }

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new JsonException('Category discovery JSON file does not exist: storage/app/'.$relativePath);
        }

        return $this->decodeCategoryDiscoveryJson(
            Storage::disk('local')->get($relativePath),
            'local disk: '.$relativePath,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCategoryDiscoveryJson(string $contents, string $source): array
    {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('Category discovery JSON file does not contain a JSON object: '.$source);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printResult(array $result): void
    {
        $this->newLine();
        $this->line('Products by product-scraping category:');

        foreach ($result['category_results'] as $categoryResult) {
            $path = is_array($categoryResult['category_path'] ?? null)
                ? implode(' > ', $categoryResult['category_path'])
                : (string) ($categoryResult['name'] ?? '');

            $this->line('- '.$path);
            $this->line('  '.$categoryResult['url']);
            $this->line('  Pages scraped: '.$categoryResult['pages_scraped']);
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
    private function saveJson(string $relativePath, array $result, bool $quiet = false): void
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

        if (! $quiet) {
            $this->info('Saved product link discovery result to storage/app/'.$relativePath);
        }
    }
}
