<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Microlife\MicrolifeProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DiscoverMicrolifeProductLinksCommand extends Command
{
    protected $signature = 'microlife:product-links
        {--categories-from= : Use an existing Microlife category discovery JSON file under storage/app.}
        {--category-url=* : Explicit Microlife product-category URL to scrape.}
        {--catalogue=all : Catalogue branch: all, consumer, or professional.}
        {--category-limit= : Maximum number of product categories to scrape. Useful for smoke tests.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Microlife request.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Microlife HTTP request.}
        {--insecure : Disable TLS certificate verification for Microlife requests.}
        {--no-progress : Do not print per-category progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Microlife URLs.}';

    protected $description = 'Discover product detail URLs from Microlife consumer and professional category pages.';

    public function __construct(
        private readonly MicrolifeProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $catalogue = $this->catalogueOption();

        if ($catalogue === false) {
            return self::FAILURE;
        }

        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');

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
            $this->warn('TLS certificate verification is disabled for this Microlife run.');
        }

        $categoryUrls = $this->option('category-url');

        if ($categoryUrls !== []) {
            if (! $json) {
                $this->info('Discovering Microlife product URLs from explicit category URLs...');
            }

            $result = $this->scraper->scrapeCategories(
                $categoryUrls,
                $this->categoryLimit(),
            );
        } else {
            $categoriesFrom = $this->option('categories-from');

            if (! is_string($categoriesFrom) || trim($categoriesFrom) === '') {
                $this->error('Provide --categories-from or at least one --category-url.');

                return self::FAILURE;
            }

            if (! $json) {
                $this->info('Discovering Microlife product URLs from saved category discovery JSON...');
            }

            $result = $this->scraper->scrapeFromDiscoveredCategories(
                $this->loadCategoryDiscoveryJson($categoriesFrom),
                $this->categoryLimit(),
                $catalogue,
            );
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
                    '- [%s] %s: %d products, %d failed page(s)',
                    $categoryResult['catalogue_type'] ?? 'unknown',
                    $path,
                    (int) ($categoryResult['product_count'] ?? 0),
                    (int) ($categoryResult['failed_page_count'] ?? 0),
                ));
            }
        }

        if ((bool) $this->option('show-failures') && ($result['failed_urls'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Failed Microlife URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $savePath = $this->option('save');

        if (is_string($savePath) && trim($savePath) !== '') {
            $this->saveJson($savePath, $result, $json);
        }

        return ($result['product_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function catalogueOption(): string|false|null
    {
        $catalogue = mb_strtolower(trim((string) $this->option('catalogue')));

        if ($catalogue === 'all') {
            return null;
        }

        if (in_array($catalogue, ['consumer', 'professional'], true)) {
            return $catalogue;
        }

        $this->error('Invalid --catalogue value. Use all, consumer, or professional.');

        return false;
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
