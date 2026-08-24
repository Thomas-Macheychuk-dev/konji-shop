<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use Closure;

final class SigvarisProductDataCrawler
{
    private ?Closure $progressCallback = null;
    private int $timeoutSeconds = 20;
    private int $attempts = 3;
    private int $retryDelayMilliseconds = 1500;
    private int $requestDelayMilliseconds = 500;
    private bool $verifyTls = true;

    public function __construct(private readonly SigvarisProductScraper $scraper) {}

    public function withProgressCallback(?Closure $callback): self { $this->progressCallback = $callback; return $this; }
    public function withTimeout(int $seconds): self { $this->timeoutSeconds = max(1, $seconds); return $this; }
    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds): self { $this->attempts = max(1, $attempts); $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds); return $this; }
    public function withRequestDelayMilliseconds(int $milliseconds): self { $this->requestDelayMilliseconds = max(0, $milliseconds); return $this; }
    public function withTlsVerification(bool $verify): self { $this->verifyTls = $verify; return $this; }

    /** @param array<string,mixed> $discovery @return array<string,mixed> */
    public function crawlFromProductLinkDiscovery(array $discovery, ?int $limit = null, int $offset = 0): array
    {
        $records = [];
        foreach ($discovery['products'] ?? [] as $product) {
            if (is_array($product) && isset($product['url'])) {
                $records[(string) $product['url']] = $product;
            }
        }
        foreach ($discovery['product_urls'] ?? [] as $url) {
            if (is_string($url) && trim($url) !== '') {
                $records[$url] ??= ['url' => $url, 'category_paths' => [], 'category_urls' => []];
            }
        }
        return $this->crawlRecords(array_values($records), $limit, $offset);
    }

    /** @param array<int,string> $urls @return array<string,mixed> */
    public function crawlProductUrls(array $urls, ?int $limit = null, int $offset = 0): array
    {
        $records = array_map(static fn (string $url): array => ['url' => $url, 'category_paths' => [], 'category_urls' => []], $urls);
        return $this->crawlRecords($records, $limit, $offset);
    }

    /** @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    private function crawlRecords(array $records, ?int $limit, int $offset): array
    {
        $sourceCount = count($records);
        $records = array_slice($records, max(0, $offset), $limit !== null ? max(1, $limit) : null);
        $products = [];
        $failed = [];
        $warnings = [];

        $this->scraper
            ->withTlsVerification($this->verifyTls)
            ->withTimeout($this->timeoutSeconds)
            ->withAttempts($this->attempts)
            ->withRetryDelayMilliseconds($this->retryDelayMilliseconds)
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds)
            ->withProgressCallback($this->progressCallback);

        foreach ($records as $index => $record) {
            $url = trim((string) ($record['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if ($this->progressCallback !== null) {
                ($this->progressCallback)(sprintf('Product %d/%d: %s', $index + 1, count($records), $url));
            }
            $product = $this->scraper->scrape($url);
            if ($product === null) {
                $failed[$url] = 'Product request failed.';
                continue;
            }
            $product['source_category_paths'] = array_values(is_array($record['category_paths'] ?? null) ? $record['category_paths'] : []);
            $product['source_category_urls'] = array_values(is_array($record['category_urls'] ?? null) ? $record['category_urls'] : []);
            foreach ($product['warnings'] ?? [] as $warning) {
                $warnings[] = ($product['name'] ?? $url).': '.$warning;
            }
            $products[] = $product;
        }

        return [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'source_product_url_count' => $sourceCount,
            'selected_product_url_count' => count($records),
            'product_count' => count($products),
            'products' => $products,
            'warnings' => $warnings,
            'failed_urls' => $failed,
            'skipped_failed_products' => array_keys($failed),
            'stopped_early' => false,
            'stop_reason' => null,
        ];
    }
}
