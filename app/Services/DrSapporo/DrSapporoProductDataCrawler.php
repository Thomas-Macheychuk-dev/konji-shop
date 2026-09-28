<?php

declare(strict_types=1);

namespace App\Services\DrSapporo;

use Closure;

final class DrSapporoProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 500;

    public function __construct(
        private readonly DrSapporoProductScraper $productScraper,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;
        $this->productScraper->withProgressCallback($callback);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);
        $this->productScraper->withTimeout($this->timeoutSeconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->productScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $productLinkDiscovery
     * @return array<string, mixed>
     */
    public function crawlFromProductLinkDiscovery(
        array $productLinkDiscovery,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $urls = $this->productUrlsFromDiscovery($productLinkDiscovery);

        return $this->crawlProductUrls($urls, $limit, $offset, $productLinkDiscovery);
    }

    /**
     * @param  array<int, string>  $productUrls
     * @param  array<string, mixed>|null  $productLinkDiscovery
     * @return array<string, mixed>
     */
    public function crawlProductUrls(
        array $productUrls,
        ?int $limit = null,
        int $offset = 0,
        ?array $productLinkDiscovery = null,
    ): array {
        $normalized = [];

        foreach ($productUrls as $url) {
            $candidate = $this->productScraper->normalizeProductUrl($url);

            if ($candidate !== null) {
                $normalized[] = $candidate;
            }
        }

        $normalized = array_values(array_unique($normalized));
        $totalProductUrlCount = count($normalized);
        $offset = max(0, $offset);
        $sourceUrls = $limit !== null && $limit > 0
            ? array_slice($normalized, $offset, $limit)
            : array_slice($normalized, $offset);

        $contexts = $this->contextsByUrl($productLinkDiscovery);
        $products = [];
        $failedUrls = [];
        $warnings = [];
        $skippedFailedProducts = [];
        $seenCanonical = [];
        $seenExternalIds = [];
        $skippedDuplicateUrls = [];
        $skippedDuplicateExternalIds = [];
        $stoppedEarly = false;
        $stopReason = null;

        foreach ($sourceUrls as $index => $sourceUrl) {
            $this->emit('Scraping Dr Sapporo product '.($index + 1).'/'.count($sourceUrls).': '.$sourceUrl);

            $product = $this->productScraper->scrape($sourceUrl, $contexts[$sourceUrl] ?? null);
            $productFailedUrls = $this->stringMap($product['failed_urls'] ?? []);

            foreach ($productFailedUrls as $failedUrl => $reason) {
                $failedUrls[$failedUrl] = $reason;
            }

            foreach ($this->stringList($product['warnings'] ?? []) as $warning) {
                $warnings[] = ['url' => $sourceUrl, 'warning' => $warning];
            }

            if (($product['name'] ?? '') === '' || $productFailedUrls !== []) {
                $skippedFailedProducts[] = [
                    'url' => $sourceUrl,
                    'reason' => $this->firstFailureReason($productFailedUrls) ?? 'missing_required_product_data',
                ];

                if ($this->hasRateLimitFailure($productFailedUrls)) {
                    $stoppedEarly = true;
                    $stopReason = 'HTTP 429 rate limit or temporary block from Dr Sapporo';
                    $this->emit('Stopping crawl: '.$stopReason);
                    break;
                }

                continue;
            }

            $canonicalUrl = $this->productScraper->normalizeProductUrl(
                (string) ($product['canonical_url'] ?? $sourceUrl)
            );
            $externalProductId = $this->stringOrNull($product['external_product_id'] ?? null);

            if ($canonicalUrl !== null && isset($seenCanonical[$canonicalUrl])) {
                $skippedDuplicateUrls[] = [
                    'url' => $sourceUrl,
                    'canonical_url' => $canonicalUrl,
                    'kept_url' => $seenCanonical[$canonicalUrl],
                    'reason' => 'duplicate_canonical_url',
                ];

                continue;
            }

            if ($externalProductId !== null && isset($seenExternalIds[$externalProductId])) {
                $skippedDuplicateExternalIds[] = [
                    'external_product_id' => $externalProductId,
                    'url' => $sourceUrl,
                    'kept_url' => $seenExternalIds[$externalProductId],
                    'reason' => 'duplicate_external_product_id',
                ];

                continue;
            }

            if ($canonicalUrl !== null) {
                $seenCanonical[$canonicalUrl] = $sourceUrl;
            }

            if ($externalProductId !== null) {
                $seenExternalIds[$externalProductId] = $sourceUrl;
            }

            $products[] = $product;
        }

        return [
            'source' => 'drsapporo',
            'product_count' => count($products),
            'products' => $products,
            'source_product_urls' => $sourceUrls,
            'source_product_url_count' => count($sourceUrls),
            'total_product_url_count' => $totalProductUrlCount,
            'offset' => $offset,
            'limit' => $limit,
            'request_delay_ms' => $this->requestDelayMilliseconds,
            'product_link_discovery' => $productLinkDiscovery,
            'skipped_failed_products' => $skippedFailedProducts,
            'skipped_duplicate_urls' => $skippedDuplicateUrls,
            'skipped_duplicate_external_ids' => $skippedDuplicateExternalIds,
            'warnings' => $warnings,
            'failed_urls' => $failedUrls,
            'failed_url_counts' => $this->failureCounts($failedUrls),
            'stopped_early' => $stoppedEarly,
            'stop_reason' => $stopReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<int, string>
     */
    private function productUrlsFromDiscovery(array $discovery): array
    {
        if (is_array($discovery['product_urls'] ?? null)) {
            return $this->stringList($discovery['product_urls']);
        }

        if (! is_array($discovery['products'] ?? null)) {
            return [];
        }

        $urls = [];

        foreach ($discovery['products'] as $product) {
            if (is_array($product) && is_string($product['url'] ?? null)) {
                $urls[] = $product['url'];
            }
        }

        return $this->stringList($urls);
    }

    /**
     * @param  array<string, mixed>|null  $discovery
     * @return array<string, array<string, mixed>>
     */
    private function contextsByUrl(?array $discovery): array
    {
        if (! is_array($discovery['products'] ?? null)) {
            return [];
        }

        $contexts = [];

        foreach ($discovery['products'] as $product) {
            if (! is_array($product) || ! is_string($product['url'] ?? null)) {
                continue;
            }

            $url = $this->productScraper->normalizeProductUrl($product['url']);

            if ($url !== null) {
                $contexts[$url] = $product;
            }
        }

        return $contexts;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $result[] = trim($entry);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && is_string($entry)) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, string>  $failedUrls
     */
    private function firstFailureReason(array $failedUrls): ?string
    {
        $first = reset($failedUrls);

        return is_string($first) ? $first : null;
    }

    /**
     * @param  array<string, string>  $failedUrls
     */
    private function hasRateLimitFailure(array $failedUrls): bool
    {
        foreach ($failedUrls as $reason) {
            if (str_contains($reason, '429')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $failedUrls
     * @return array<string, int>
     */
    private function failureCounts(array $failedUrls): array
    {
        $counts = [];

        foreach ($failedUrls as $reason) {
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
