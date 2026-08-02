<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Microlife\MicrolifeProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class CrawlMicrolifeProductDataCommand extends Command
{
    protected $signature = 'microlife:crawl-product-data
        {--from=scrapers/microlife/product-links.json : Product link discovery JSON file under storage/app.}
        {--url=* : Explicit Microlife product URL to scrape instead of reading --from.}
        {--limit= : Maximum number of product URLs to scrape.}
        {--offset=0 : Number of product URLs to skip before scraping.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts for HTTP 429 and 5xx responses.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying a Microlife request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Microlife HTTP request.}
        {--insecure : Disable TLS certificate verification for Microlife requests.}
        {--no-progress : Do not print per-product progress.}
        {--json : Print full product data as JSON.}
        {--save= : Save full product data JSON under storage/app.}
        {--show-failures : Print failed Microlife product URLs.}';

    protected $description = 'Scrape consumer and professional Microlife product details into one JSON dataset.';

    public function __construct(
        private readonly MicrolifeProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');

        $this->crawler
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->positiveIntOption('timeout', 20))
            ->withMaxAttempts(
                $this->positiveIntOption('attempts', 3),
                $this->nonNegativeIntOption('retry-delay-ms', 1500),
            )
            ->withRequestDelayMilliseconds($this->nonNegativeIntOption('request-delay-ms', 500));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json && $insecure) {
            $this->warn('TLS certificate verification is disabled for this Microlife run.');
        }

        $explicitUrls = $this->option('url');

        if ($explicitUrls !== []) {
            if (! $json) {
                $this->info('Scraping Microlife product data from explicit product URLs...');
            }

            $result = $this->crawler->crawlProductUrls(
                array_values(array_map('strval', $explicitUrls)),
                $this->limit(),
                $this->offset(),
            );
        } else {
            if (! $json) {
                $this->info('Scraping Microlife product data from saved product-link discovery JSON...');
            }

            $productLinkDiscovery = $this->loadJson((string) $this->option('from'));
            $result = $this->crawler->crawlFromProductLinkDiscovery(
                $productLinkDiscovery,
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
            $this->warn('Failed Microlife product URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $save = $this->option('save');

        if (is_string($save) && trim($save) !== '') {
            $this->saveJson($save, $result, $json);
        }

        return $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
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

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : $default;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new JsonException('Unable to read product-link discovery JSON file: storage/app/'.$relativePath);
            }

            return $this->decodeJson($contents, 'storage/app/'.$relativePath);
        }

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new JsonException('Product-link discovery JSON file does not exist: storage/app/'.$relativePath);
        }

        return $this->decodeJson(
            Storage::disk('local')->get($relativePath),
            'local disk: '.$relativePath,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $contents, string $source): array
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
    private function printSummary(array $result): void
    {
        $this->info('Source product URLs: '.$result['source_product_url_count']);
        $this->info('Scraped products: '.$result['product_count']);
        $this->info('- Consumer products: '.$result['consumer_product_count']);
        $this->info('- Professional products: '.$result['professional_product_count']);
        $this->info('Skipped failed products: '.count($result['skipped_failed_products']));
        $this->info('Skipped duplicate URLs: '.count($result['skipped_duplicate_urls']));
        $this->info('Skipped duplicate external IDs: '.count($result['skipped_duplicate_external_ids']));
        $this->info('Warnings: '.count($result['warnings']));
        $this->info('Failed URLs: '.count($result['failed_urls']));

        if ((bool) ($result['stopped_early'] ?? false)) {
            $this->warn('Stopped early: '.($result['stop_reason'] ?? 'unknown reason'));
        }

        if ($result['products'] === []) {
            return;
        }

        $this->newLine();
        $this->line('Products:');

        foreach ($result['products'] as $product) {
            $this->line('- ['.($product['catalogue_type'] ?? 'unknown').'] '.($product['name'] ?? 'Unnamed product'));
            $this->line('  '.($product['canonical_url'] ?? $product['source_url'] ?? 'URL not found'));
            $this->line('  Category: '.($product['category'] ?? 'not found'));
            $this->line('  Images: '.count($product['images'] ?? []));
            $this->line('  Features: '.count($product['features'] ?? []));
            $this->line('  Specifications: '.count($product['specification_items'] ?? []));
            $this->line('  Downloads: '.count($product['downloads'] ?? []));
            $this->line('  Variants: '.count($product['variant_candidates'] ?? []));
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result, bool $jsonOutput): void
    {
        $relativePath = ltrim(trim($relativePath), '/');

        if ($relativePath === '') {
            throw new RuntimeException('Microlife product-data save path cannot be empty.');
        }

        $encoded = json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Microlife product data as JSON.');
        }

        $absolutePath = storage_path('app/'.$relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Microlife product-data directory: '.$directory);
        }

        $bytesWritten = file_put_contents($absolutePath, $encoded.PHP_EOL);

        if ($bytesWritten === false) {
            throw new RuntimeException(
                'Unable to save Microlife product data to storage/app/'.$relativePath,
            );
        }

        if (! $jsonOutput) {
            $this->info('Saved full product data to storage/app/'.$relativePath);
        }
    }
}
