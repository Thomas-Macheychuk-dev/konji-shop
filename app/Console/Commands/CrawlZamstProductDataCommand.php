<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zamst\ZamstProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class CrawlZamstProductDataCommand extends Command
{
    protected $signature = 'zamst:crawl-product-data
        {--from=scrapers/zamst/product-links.json : Zamst product-link discovery JSON under storage/app.}
        {--url=* : Explicit Zamst product URL to scrape instead of reading --from.}
        {--limit= : Maximum number of product URLs to scrape.}
        {--offset=0 : Number of product URLs to skip before scraping.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts for temporary Zamst failures.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying a Zamst request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Zamst HTTP request.}
        {--insecure : Disable TLS certificate verification for Zamst requests.}
        {--no-progress : Do not print per-product progress.}
        {--json : Print full scraped product data as JSON.}
        {--save= : Save scraped product data JSON under storage/app.}
        {--show-failures : Print failed Zamst product URLs.}';

    protected $description = 'Scrape Zamst WooCommerce product details into JSON without importing products.';

    public function __construct(
        private readonly ZamstProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');

        $this->crawler
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withMaxAttempts(
                $this->integerOption('attempts', 3, 1),
                $this->integerOption('retry-delay-ms', 1500, 0),
            )
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 500, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json && $insecure) {
            $this->warn('TLS certificate verification is disabled for this Zamst run.');
        }

        $explicitUrls = array_values(array_filter(array_map(
            static fn (mixed $url): string => trim((string) $url),
            $this->option('url'),
        )));

        if ($explicitUrls !== []) {
            if (! $json) {
                $this->info('Scraping Zamst product data from explicit URLs.');
            }

            $result = $this->crawler->crawlProductUrls($explicitUrls, $this->limit(), $this->offset());
        } else {
            if (! $json) {
                $this->info('Scraping Zamst product data from saved product-link discovery JSON.');
            }

            $result = $this->crawler->crawlFromProductLinkDiscovery(
                $this->loadJson((string) $this->option('from')),
                $this->limit(),
                $this->offset(),
            );
        }

        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result);
        }

        if ((bool) $this->option('show-failures') && ($result['failed_urls'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Failed Zamst product URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return ($result['product_count'] ?? 0) > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    private function offset(): int
    {
        return max(0, (int) $this->option('offset'));
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max($minimum, (int) $value) : $default;
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
            throw new JsonException('Zamst product-link JSON does not exist: storage/app/'.$relativePath);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('Zamst product-link JSON does not contain an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private function printSummary(array $result): void
    {
        $this->info('Source product URLs: '.($result['source_product_url_count'] ?? 0));
        $this->info('Scraped products: '.($result['product_count'] ?? 0));
        $this->info('Skipped failed products: '.count($result['skipped_failed_products'] ?? []));
        $this->info('Warnings: '.count($result['warnings'] ?? []));
        $this->info('Failed URLs: '.count($result['failed_urls'] ?? []));

        if ((bool) ($result['stopped_early'] ?? false)) {
            $this->warn('Stopped early: '.($result['stop_reason'] ?? 'unknown reason'));
        }

        foreach ($result['products'] ?? [] as $product) {
            $this->newLine();
            $this->line('- '.($product['name'] ?? 'Unnamed Zamst product'));
            $this->line('  URL: '.($product['canonical_url'] ?? $product['source_url'] ?? 'not found'));
            $this->line('  Product ID: '.($product['external_product_id'] ?? 'not found'));
            $this->line('  Price: '.($product['price_gross_amount'] ?? 'not found').' '.($product['currency'] ?? 'PLN'));
            $this->line('  Category: '.($product['category'] ?? 'not found'));
            $this->line('  Images: '.count($product['images'] ?? []));
            $this->line('  Downloads: '.count($product['downloads'] ?? []));
            $this->line('  Videos: '.count($product['videos'] ?? []));
            $this->line('  Select attributes: '.count($product['attributes'] ?? []));
            $this->line('  Actual WooCommerce variants: '.count($product['variant_candidates'] ?? []));
            $this->line('  Product warnings: '.count($product['warnings'] ?? []));
        }
    }

    /** @param array<string, mixed> $data */
    private function saveJson(string $relativePath, array $data, bool $quiet): void
    {
        $relativePath = ltrim(trim($relativePath), '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Zamst product-data directory: '.$directory);
        }

        if (file_put_contents($path, $this->encode($data).PHP_EOL) === false) {
            throw new RuntimeException('Unable to save Zamst product-data JSON.');
        }

        if (! $quiet) {
            $this->info('Saved product data to storage/app/'.$relativePath);
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Zamst product data JSON.');
        }

        return $encoded;
    }
}
