<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Medi\MediCategoryUrlScraper;
use App\Services\Medi\MediProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DiscoverMediProductLinksCommand extends Command
{
    protected $signature = 'medi:product-links
        {--url=* : medi category/menu page to scan. Defaults to https://www.medi-polska.pl/shop/.}
        {--categories-from= : Use an existing category discovery JSON file under storage/app instead of discovering categories again.}
        {--category-url=* : Explicit medi category URL to scrape. When used, hierarchy discovery is skipped.}
        {--page-limit= : Maximum number of paginated Magento product-list pages to scrape per category.}
        {--category-limit= : Maximum number of product-scraping categories to scrape. Useful while testing.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per medi HTTP request.}
        {--retry-delay-ms=1500 : Milliseconds to pause between retry attempts.}
        {--request-delay-ms=500 : Milliseconds to pause before each medi HTTP request.}
        {--no-progress : Do not print per-category and per-page progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed medi URLs.}';

    protected $description = 'Discover medi product URLs from Magento product categories.';

    public function __construct(
        private readonly MediProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $categoryUrls = $this->option('category-url');

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withMaxAttempts($this->maxAttempts(), $this->retryDelayMilliseconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if ($categoryUrls !== []) {
            if (! $json) {
                $this->info('Discovering medi product URLs from explicit category URLs...');
            }

            $result = $this->scraper->scrapeCategories($categoryUrls, $this->pageLimit(), $this->categoryLimit());
        } elseif (is_string($this->option('categories-from')) && trim((string) $this->option('categories-from')) !== '') {
            if (! $json) {
                $this->info('Discovering medi product URLs from saved category discovery JSON...');
            }

            $result = $this->scraper->scrapeFromDiscoveredCategories(
                $this->loadCategoryDiscoveryJson((string) $this->option('categories-from')),
                $this->pageLimit(),
                $this->categoryLimit(),
            );
        } else {
            if (! $json) {
                $this->info('Discovering medi product URLs from category hierarchy...');
            }

            $urls = $this->option('url') ?: [MediCategoryUrlScraper::DEFAULT_URL];
            $result = $this->scraper->scrape($urls, $this->pageLimit(), $this->categoryLimit());
        }

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Visited product-list pages: '.count($result['visited_urls'] ?? []));
            $this->info('Source product categories: '.count($result['source_categories'] ?? []));
            $this->info('Skipped product categories: '.count($result['skipped_categories'] ?? []));
            $this->info('Discovered product URLs: '.count($result['product_urls'] ?? []));

            foreach ($result['category_results'] ?? [] as $categoryResult) {
                $path = is_array($categoryResult['category_path'] ?? null)
                    ? implode(' > ', $categoryResult['category_path'])
                    : (string) ($categoryResult['name'] ?? $categoryResult['url'] ?? 'Unknown category');

                if ((bool) ($categoryResult['skipped'] ?? false)) {
                    $this->line(sprintf(
                        '- %s: skipped (%s)',
                        $path,
                        (string) ($categoryResult['skip_reason'] ?? 'unspecified reason'),
                    ));

                    continue;
                }

                $this->line(sprintf(
                    '- %s: %d products, %d page(s), %d failed page(s)',
                    $path,
                    count($categoryResult['product_urls'] ?? []),
                    (int) ($categoryResult['pages_scraped'] ?? 0),
                    (int) ($categoryResult['failed_page_count'] ?? 0),
                ));
            }
        }

        if ((bool) $this->option('show-failures') && ($result['failed_urls'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Failed medi URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return ($result['product_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
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

    private function timeoutSeconds(): int
    {
        return max(1, (int) $this->option('timeout'));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) $this->option('attempts'));
    }

    private function retryDelayMilliseconds(): int
    {
        return max(0, (int) $this->option('retry-delay-ms'));
    }

    private function requestDelayMilliseconds(): int
    {
        return max(0, (int) $this->option('request-delay-ms'));
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

        if (! is_file($fullPath) && Storage::disk('local')->exists($path)) {
            $fullPath = Storage::disk('local')->path($path);
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
    private function saveJson(string $path, array $data, bool $quiet = false): void
    {
        $path = ltrim(trim($path), '/');
        $fullPath = storage_path('app/'.$path);
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $fullPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        if (! $quiet) {
            $this->info('Saved product-link discovery result to storage/app/'.$path);
        }
    }
}
