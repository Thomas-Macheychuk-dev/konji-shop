<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vermeiren\VermeirenProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class CrawlVermeirenProductDataCommand extends Command
{
    protected $signature = 'vermeiren:crawl-product-data
        {--from=scrapers/vermeiren/product-links.json : Product-link discovery JSON file under storage/app.}
        {--url=* : Explicit Vermeiren product URL to scrape instead of reading --from.}
        {--limit= : Maximum number of product URLs to scrape.}
        {--offset=0 : Number of product URLs to skip before scraping.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts for HTTP 429 and 5xx responses.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying a Vermeiren request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Vermeiren HTTP request.}
        {--insecure : Disable TLS certificate verification only for this Vermeiren run.}
        {--no-progress : Do not print per-product progress.}
        {--json : Print full product data as JSON.}
        {--save= : Save full product data JSON under storage/app.}
        {--show-failures : Print failed Vermeiren product URLs.}';

    protected $description = 'Scrape Vermeiren product details from discovered product URLs into one JSON dataset.';

    public function __construct(
        private readonly VermeirenProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $verifyTls = ! (bool) $this->option('insecure');

        $this->crawler
            ->withTimeout($this->timeoutSeconds())
            ->withMaxAttempts($this->maxAttempts(), $this->retryDelayMilliseconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withTlsVerification($verifyTls);

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json && ! $verifyTls) {
            $this->warn('TLS certificate verification is disabled for this Vermeiren run.');
        }

        $explicitUrls = $this->option('url');

        if ($explicitUrls !== []) {
            if (! $json) {
                $this->info('Scraping Vermeiren product data from explicit product URLs...');
            }

            $result = $this->crawler->crawlProductUrls(
                array_values(array_map('strval', $explicitUrls)),
                $this->limit(),
                $this->offset(),
            );
        } else {
            if (! $json) {
                $this->info('Scraping Vermeiren product data from saved product-link discovery JSON...');
            }

            $result = $this->crawler->crawlFromProductLinkDiscovery(
                $this->loadProductLinkDiscoveryJson((string) $this->option('from')),
                $this->limit(),
                $this->offset(),
            );
        }

        if ($json) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
        } else {
            $this->printSummary($result);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Vermeiren product URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printSummary(array $result): void
    {
        $this->info('Source product URLs: '.$result['source_product_url_count']);
        $this->info('Scraped products: '.$result['product_count']);
        $this->info('Skipped failed products: '.count($result['skipped_failed_products']));
        $this->info('Skipped duplicate URLs: '.count($result['skipped_duplicate_urls']));
        $this->info('Skipped duplicate external IDs: '.count($result['skipped_duplicate_external_ids']));
        $this->info('Warnings: '.count($result['warnings']));
        $this->info('Failed URLs: '.count($result['failed_urls']));

        if ((bool) ($result['stopped_early'] ?? false)) {
            $this->warn('Stopped early: '.($result['stop_reason'] ?? 'unknown reason'));
        }

        if (($result['failed_url_counts'] ?? []) !== []) {
            $this->newLine();
            $this->line('Failures by reason:');

            foreach ($result['failed_url_counts'] as $reason => $count) {
                $this->line('- '.$reason.': '.$count);
            }
        }

        if ($result['products'] === []) {
            return;
        }

        $this->newLine();
        $this->line('Products:');

        foreach ($result['products'] as $product) {
            $this->line('- '.$product['name']);
            $this->line('  '.($product['canonical_url'] ?? $product['source_url'] ?? 'URL not found'));
            $this->line('  External ID: '.($product['external_product_id'] ?? 'not found'));
            $this->line('  SKU/model: '.($product['sku'] ?? 'not found'));
            $this->line('  Category: '.($product['category'] ?? 'not found'));
            $this->line('  Images: '.count($product['images']));
            $this->line('  Technical specifications: '.count($product['technical_specifications']));
            $this->line('  Colors: '.count($product['colors']));
            $this->line('  Options: '.count($product['options']));
            $this->line('  Documents: '.count($product['documents']));
        }
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    private function offset(): int
    {
        $value = $this->option('offset');

        return is_string($value) ? max(0, (int) $value) : 0;
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');
        $timeout = is_string($value) ? (int) $value : 20;

        return $timeout > 0 ? $timeout : 20;
    }

    private function maxAttempts(): int
    {
        $value = $this->option('attempts');

        return is_string($value) ? max(1, (int) $value) : 3;
    }

    private function retryDelayMilliseconds(): int
    {
        $value = $this->option('retry-delay-ms');

        return is_string($value) ? max(0, (int) $value) : 1500;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        return is_string($value) ? max(0, (int) $value) : 500;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProductLinkDiscoveryJson(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new JsonException('Unable to read product-link discovery JSON file: storage/app/'.$relativePath);
            }

            return $this->decodeProductLinkDiscoveryJson($contents, 'storage/app/'.$relativePath);
        }

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new JsonException('Product-link discovery JSON file does not exist: storage/app/'.$relativePath);
        }

        return $this->decodeProductLinkDiscoveryJson(
            Storage::disk('local')->get($relativePath),
            'local disk: '.$relativePath,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProductLinkDiscoveryJson(string $contents, string $source): array
    {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('Product-link discovery JSON file does not contain a JSON object: '.$source);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result, bool $quiet = false): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Vermeiren product-data directory: '.$directory);
        }

        $json = json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Unable to save Vermeiren product data JSON: '.$path);
        }

        if (! $quiet) {
            $this->info('Saved full product data to storage/app/'.$relativePath);
        }
    }
}
