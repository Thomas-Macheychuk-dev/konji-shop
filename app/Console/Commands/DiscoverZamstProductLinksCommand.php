<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zamst\ZamstProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class DiscoverZamstProductLinksCommand extends Command
{
    protected $signature = 'zamst:product-links
        {--categories-from= : Zamst category discovery JSON file under storage/app.}
        {--category-limit= : Maximum number of category pages to scrape in addition to the main shop page.}
        {--page-limit= : Maximum pages to scrape per catalogue/category branch.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Zamst request.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Zamst HTTP request.}
        {--insecure : Disable TLS certificate verification for Zamst requests.}
        {--no-progress : Do not print per-page progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Zamst URLs.}';

    protected $description = 'Discover Zamst product URLs and category context without writing to the database.';

    public function __construct(
        private readonly ZamstProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');
        $categoriesFrom = trim((string) ($this->option('categories-from') ?? ''));
        $categoryDiscovery = $categoriesFrom !== '' ? $this->loadJson($categoriesFrom) : null;

        $this->scraper
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withAttempts($this->integerOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->integerOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 500, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json) {
            $this->info('Discovering Zamst product links.');

            if ($categoriesFrom === '') {
                $this->warn('No --categories-from supplied: product URLs will still be discovered from /sklep/, but category enrichment will be limited.');
            }

            if ($insecure) {
                $this->warn('TLS certificate verification is disabled for this Zamst run.');
            }
        }

        $result = $this->scraper->scrape(
            $categoryDiscovery,
            $this->nullablePositiveOption('category-limit'),
            $this->nullablePositiveOption('page-limit'),
        );

        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->info('Visited pages: '.count($result['visited_urls'] ?? []));
            $this->info('Catalogue pages: '.count($result['catalogue_pages'] ?? []));
            $this->info('Source categories: '.count($result['source_categories'] ?? []));
            $this->info('Discovered product URLs: '.count($result['product_urls'] ?? []));
            $this->info('Failed URLs: '.count($result['failed_urls'] ?? []));

            foreach ($result['category_results'] ?? [] as $category) {
                $this->line(sprintf(
                    '- %s: %d product(s), %d page(s)',
                    implode(' > ', $category['category_path'] ?? []),
                    (int) ($category['product_count'] ?? 0),
                    (int) ($category['pages_scraped'] ?? 0),
                ));
            }
        }

        if ((bool) $this->option('show-failures') && ($result['failed_urls'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Failed Zamst URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return ($result['product_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max($minimum, (int) $value) : $default;
    }

    private function nullablePositiveOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    /** @return array<string, mixed> */
    private function loadJson(string $relativePath): array
    {
        $relativePath = ltrim(trim($relativePath), '/');
        $path = storage_path('app/'.$relativePath);

        if (! is_file($path) && Storage::disk('local')->exists($relativePath)) {
            $path = Storage::disk('local')->path($relativePath);
        }

        if (! is_file($path)) {
            throw new JsonException('Zamst category discovery JSON does not exist: storage/app/'.$relativePath);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('Zamst category discovery JSON does not contain an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function saveJson(string $relativePath, array $data, bool $quiet): void
    {
        $relativePath = ltrim(trim($relativePath), '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Zamst scraper directory: '.$directory);
        }

        if (file_put_contents($path, $this->encode($data).PHP_EOL) === false) {
            throw new RuntimeException('Unable to save Zamst product-link discovery JSON.');
        }

        if (! $quiet) {
            $this->info('Saved product-link discovery result to storage/app/'.$relativePath);
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Zamst product-link JSON.');
        }

        return $encoded;
    }
}
