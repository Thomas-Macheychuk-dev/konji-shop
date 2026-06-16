<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TwojaPeruka\TwojaPerukaProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class CrawlTwojaPerukaProductDataCommand extends Command
{
    protected $signature = 'twojaperuka:crawl-product-data
        {--from=scrapers/twojaperuka/product-links.json : Product link discovery JSON file under storage/app.}
        {--url=* : Explicit TwojaPeruka product URL to scrape instead of reading --from.}
        {--limit= : Maximum number of product URLs to scrape.}
        {--offset=0 : Number of product URLs to skip before scraping.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each TwojaPeruka HTTP request.}
        {--no-progress : Do not print per-product progress.}
        {--json : Print full product data as JSON.}
        {--save= : Save full product data JSON under storage/app.}
        {--show-failures : Print failed TwojaPeruka product URLs.}';

    protected $description = 'Scrape TwojaPeruka product details from discovered product URLs into one JSON dataset.';

    public function __construct(
        private readonly TwojaPerukaProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $this->crawler
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $explicitUrls = $this->option('url');

        if ($explicitUrls !== []) {
            if (! $json) {
                $this->info('Scraping TwojaPeruka product data from explicit product URLs...');
            }

            $result = $this->crawler->crawlProductUrls(
                array_values(array_map('strval', $explicitUrls)),
                $this->limit(),
                $this->offset(),
            );
        } else {
            if (! $json) {
                $this->info('Scraping TwojaPeruka product data from saved product-link discovery JSON...');
            }

            $productLinkDiscovery = $this->loadProductLinkDiscoveryJson((string) $this->option('from'));

            $result = $this->crawler->crawlFromProductLinkDiscovery(
                $productLinkDiscovery,
                $this->limit(),
                $this->offset(),
            );
        }

        if ($json) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ));
        } else {
            $this->info('Source product URLs: '.$result['source_product_url_count']);
            $this->info('Scraped products: '.$result['product_count']);
            $this->info('Skipped duplicate URLs: '.count($result['skipped_duplicate_urls']));
            $this->info('Skipped duplicate external IDs: '.count($result['skipped_duplicate_external_ids']));
            $this->info('Failed URLs: '.count($result['failed_urls']));
            $this->printProductSummary($result);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed TwojaPeruka product URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
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

        if (! is_string($value) || trim($value) === '') {
            return 0;
        }

        return max(0, (int) $value);
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
    private function printProductSummary(array $result): void
    {
        if ($result['products'] === []) {
            return;
        }

        $this->newLine();
        $this->line('Products:');

        foreach ($result['products'] as $product) {
            $this->line('- '.$product['name']);
            $this->line('  '.$product['canonical_url']);
            $this->line('  External ID: '.($product['external_product_id'] ?? 'not found'));
            $this->line('  Price gross: '.($product['price_gross_amount'] ?? 'not found').' '.$product['currency']);
            $this->line('  Images: '.count($product['images']));
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
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
        );

        if (! $quiet) {
            $this->info('Saved full product data to storage/app/'.$relativePath);
        }
    }
}
