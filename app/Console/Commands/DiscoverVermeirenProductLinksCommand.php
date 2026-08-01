<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vermeiren\VermeirenCategoryUrlScraper;
use App\Services\Vermeiren\VermeirenProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DiscoverVermeirenProductLinksCommand extends Command
{
    protected $signature = 'vermeiren:product-links
        {--url=* : Vermeiren page containing the Produkty menu. Defaults to the Polish home page.}
        {--categories-from= : Use an existing Vermeiren category discovery JSON file under storage/app.}
        {--category-url=* : Explicit Vermeiren product-category URL to scrape.}
        {--category-limit= : Maximum number of product categories to scrape. Useful for smoke tests.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Vermeiren request.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Vermeiren HTTP request.}
        {--insecure : Disable TLS certificate verification for Vermeiren requests.}
        {--no-progress : Do not print per-category progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Vermeiren URLs.}';

    protected $description = 'Discover Vermeiren product detail URLs from saved product categories.';

    public function __construct(
        private readonly VermeirenProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');
        $categoryUrls = $this->option('category-url');

        $this->scraper
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withAttempts($this->integerOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->integerOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 500, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json && $insecure) {
            $this->warn('TLS certificate verification is disabled for this Vermeiren run.');
        }

        if ($categoryUrls !== []) {
            if (! $json) {
                $this->info('Discovering Vermeiren product URLs from explicit category URLs...');
            }

            $result = $this->scraper->scrapeCategories($categoryUrls, $this->categoryLimit());
        } elseif (is_string($this->option('categories-from')) && trim((string) $this->option('categories-from')) !== '') {
            if (! $json) {
                $this->info('Discovering Vermeiren product URLs from saved category discovery JSON...');
            }

            $result = $this->scraper->scrapeFromDiscoveredCategories(
                $this->loadCategoryDiscoveryJson((string) $this->option('categories-from')),
                $this->categoryLimit(),
            );
        } else {
            if (! $json) {
                $this->info('Discovering Vermeiren product URLs from the live category hierarchy...');
            }

            $urls = $this->option('url') ?: [VermeirenCategoryUrlScraper::DEFAULT_URL];
            $result = $this->scraper->scrape($urls, $this->categoryLimit());
        }

        if ($json) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->info('Visited product-list pages: '.count($result['visited_urls'] ?? []));
            $this->info('Source product categories: '.count($result['source_categories'] ?? []));
            $this->info('Discovered product URLs: '.count($result['product_urls'] ?? []));

            foreach ($result['category_results'] ?? [] as $categoryResult) {
                $path = is_array($categoryResult['category_path'] ?? null)
                    ? implode(' > ', $categoryResult['category_path'])
                    : (string) ($categoryResult['name'] ?? $categoryResult['url'] ?? 'Unknown category');

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
            $this->warn('Failed Vermeiren URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return ($result['product_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function categoryLimit(): ?int
    {
        $value = $this->option('category-limit');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return $default;
        }

        return max($minimum, (int) $value);
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
            json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ).PHP_EOL,
        );

        if (! $quiet) {
            $this->info('Saved product-link discovery result to storage/app/'.$path);
        }
    }
}
