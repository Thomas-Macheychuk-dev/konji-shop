<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Iconic\IconicProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class CrawlIconicProductDataCommand extends Command
{
    protected $signature = 'iconic:crawl-product-data
        {--from=scrapers/iconic/product-links-v2.json : Product link discovery JSON file under storage/app/private.}
        {--url=* : Explicit Iconic product URL to scrape instead of reading --from.}
        {--limit= : Maximum number of product URLs to scrape.}
        {--offset=0 : Number of product URLs to skip before scraping.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Iconic HTTP request.}
        {--no-progress : Do not print per-product progress.}
        {--json : Print full product data as JSON.}
        {--save= : Save full product data JSON under storage/app/private.}
        {--show-failures : Print failed Iconic product URLs.}';

    protected $description = 'Scrape Iconic product details from discovered product URLs into one JSON dataset.';

    public function __construct(
        private readonly IconicProductDataCrawler $crawler,
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
            $result = $this->crawler->crawlProductUrls(
                array_values(array_map('strval', $explicitUrls)),
                $this->limit(),
                $this->offset(),
            );
        } else {
            $result = $this->crawler->crawlFromProductLinkDiscovery(
                $this->loadJson((string) $this->option('from')),
                $this->limit(),
                $this->offset(),
            );
        }

        if ($json) {
            $this->line($this->encodeJson($result));
        } else {
            $this->info('Source product URLs: '.$result['source_product_url_count']);
            $this->info('Scraped products: '.$result['product_count']);
            $this->info('Skipped failed products: '.count($result['skipped_failed_products']));
            $this->info('Skipped duplicate URLs: '.count($result['skipped_duplicate_urls']));
            $this->info('Skipped duplicate external IDs: '.count($result['skipped_duplicate_external_ids']));
            $this->info('Warnings: '.count($result['warnings']));
            $this->info('Failed URLs: '.count($result['failed_urls']));

            foreach ($result['products'] as $product) {
                $this->line('- '.$product['name']);
                $this->line('  '.($product['canonical_url'] ?? $product['source_url']));
                $this->line('  Price: '.($product['price_gross_amount'] ?? 'not found').' '.($product['currency'] ?? 'PLN'));
                $this->line('  Catalogue number: '.($product['catalogue_number'] ?? 'not found'));
                $this->line('  Availability: '.($product['availability_label'] ?? $product['availability'] ?? 'not found'));
                $this->line('  Images: '.count($product['images']));
                $this->line('  Variants: '.count($product['variant_candidates']));
                $this->line('  Medical wording: '.(($product['is_medical_device'] ?? false) ? 'yes' : 'no'));
            }
        }

        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] as $url => $reason) {
                $this->warn($url.' - '.$reason);
            }
        }

        $save = $this->option('save');

        if (is_string($save) && trim($save) !== '') {
            $relativePath = ltrim($save, '/');
            $contents = $this->encodeJson($result);

            if (! Storage::disk('local')->put($relativePath, $contents)) {
                throw new \RuntimeException(
                    'Unable to save Iconic product crawl JSON to local disk: '.$relativePath,
                );
            }

            if (! Storage::disk('local')->exists($relativePath)
                || Storage::disk('local')->size($relativePath) === 0) {
                throw new \RuntimeException(
                    'Iconic product crawl JSON was written as an empty artifact: '.$relativePath,
                );
            }
        }

        return $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function encodeJson(array $result): string
    {
        return json_encode(
            $result,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new JsonException(
                'Iconic product-link discovery JSON does not exist on local disk: '.$relativePath,
            );
        }

        $decoded = json_decode(
            Storage::disk('local')->get($relativePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new JsonException('Iconic product-link discovery JSON does not contain an object.');
        }

        return $decoded;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        return is_string($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function offset(): int
    {
        $value = $this->option('offset');

        return is_string($value) ? max(0, (int) $value) : 0;
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');

        return is_string($value) && (int) $value > 0 ? (int) $value : 15;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        return is_string($value) ? max(0, (int) $value) : 500;
    }
}
