<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Armedical\ArmedicalProductDataCrawler;
use App\Services\Armedical\ArmedicalUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class CrawlArmedicalProductDataCommand extends Command
{
    protected $signature = 'armedical:crawl-product-data
        {--from= : Product-link discovery JSON on the local filesystem disk.}
        {--url=* : Explicit ARmedical product URL(s).}
        {--limit= : Maximum products to scrape.}
        {--offset=0 : Product offset.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per request.}
        {--retry-delay-ms=1500 : Milliseconds to pause between retries.}
        {--request-delay-ms=500 : Milliseconds to pause before each request.}
        {--json : Print result as JSON.}
        {--save= : Save JSON on the local filesystem disk.}
        {--show-failures : Print failed product URLs.}
        {--show-warnings : Print scraper warnings.}';

    protected $description = 'Scrape ARmedical product data without creating or modifying Konji Shop products.';

    public function __construct(
        private readonly ArmedicalProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->crawler
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withMaxAttempts(max(1, (int) $this->option('attempts')), max(0, (int) $this->option('retry-delay-ms')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withProgressCallback(fn (string $message): null => $this->line($message));

        $limit = $this->nullablePositiveInt($this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));
        $from = $this->option('from');

        if (is_string($from) && trim($from) !== '') {
            $this->info('Scraping ARmedical product data from saved product-link discovery...');
            $result = $this->crawler->crawlFromProductLinkDiscovery($this->loadJson($from), $limit, $offset);
        } else {
            $urls = [];

            foreach ($this->option('url') as $url) {
                if (! is_string($url)) {
                    continue;
                }

                $normalized = ArmedicalUrl::product($url);

                if ($normalized !== null) {
                    $urls[] = $normalized;
                }
            }

            if ($urls === []) {
                $this->error('Provide --from=<product-links.json> or at least one --url=<product-url>.');

                return self::FAILURE;
            }

            $this->info('Scraping explicit ARmedical product URLs...');
            $result = $this->crawler->crawlProductUrls($urls, $limit, $offset);
        }

        $this->info('Products scraped: '.(int) ($result['product_count'] ?? 0));
        $this->info('Selected source URLs: '.(int) ($result['source_product_url_count'] ?? 0));
        $this->info('Failed URLs: '.count($result['failed_urls'] ?? []));
        $this->info('Warnings: '.count($result['warnings'] ?? []));

        if ((bool) $this->option('json')) {
            $this->line($this->json($result));
        }

        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] ?? [] as $url => $reason) {
                $this->warn($url.' - '.$reason);
            }
        }

        if ((bool) $this->option('show-warnings')) {
            foreach ($result['warnings'] ?? [] as $warning) {
                $this->warn((string) ($warning['url'] ?? '').' - '.(string) ($warning['warning'] ?? ''));
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        if (($result['stopped_early'] ?? false) === true) {
            $this->error('ARmedical crawl stopped early: '.(string) ($result['stop_reason'] ?? 'unknown reason'));

            return self::FAILURE;
        }

        return (int) ($result['product_count'] ?? 0) === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function loadJson(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new \RuntimeException('JSON file not found on local filesystem disk: '.$relativePath);
        }

        try {
            return json_decode(Storage::disk('local')->get($relativePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Invalid JSON on local filesystem disk at '.$relativePath.': '.$exception->getMessage(), 0, $exception);
        }
    }

    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        Storage::disk('local')->put($relativePath, $this->json($result));
        $this->info('Saved product data to '.Storage::disk('local')->path($relativePath));
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    private function json(array $result): string
    {
        return (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
