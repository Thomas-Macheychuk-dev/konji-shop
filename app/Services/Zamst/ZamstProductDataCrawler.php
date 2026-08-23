<?php

declare(strict_types=1);

namespace App\Services\Zamst;

use Closure;

final class ZamstProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $requestDelayMilliseconds = 500;

    public function __construct(
        private readonly ZamstProductScraper $productScraper,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;
        $this->productScraper->withProgressCallback($callback);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->productScraper->withTimeout($seconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->productScraper->withMaxAttempts($attempts, $retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->productScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->productScraper->withTlsVerification($verify);

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
        return $this->crawlProductUrls(
            $this->productUrlsFromDiscovery($productLinkDiscovery),
            $limit,
            $offset,
            $productLinkDiscovery,
        );
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
        $allUrls = $this->normalizeProductUrls($productUrls);
        $sourceUrls = $limit !== null && $limit > 0
            ? array_slice($allUrls, max(0, $offset), $limit)
            : array_slice($allUrls, max(0, $offset));
        $contexts = $this->productContextsByUrl($productLinkDiscovery);
        $products = [];
        $canonicalUrls = [];
        $externalIds = [];
        $failedUrls = [];
        $warnings = [];
        $skippedProducts = [];
        $skippedDuplicateUrls = [];
        $skippedDuplicateExternalIds = [];
        $stoppedEarly = false;
        $stopReason = null;

        foreach ($sourceUrls as $index => $sourceUrl) {
            $this->emit(sprintf(
                'Scraping Zamst product %d/%d: %s',
                $index + 1,
                count($sourceUrls),
                $sourceUrl,
            ));

            $product = $this->productScraper->scrape($sourceUrl, $contexts[$sourceUrl] ?? null);
            $productFailures = $this->stringMap($product['failed_urls'] ?? []);

            foreach ($productFailures as $url => $reason) {
                $failedUrls[$url] = $reason;
            }

            foreach ($this->stringList($product['warnings'] ?? []) as $warning) {
                $warnings[] = [
                    'url' => $sourceUrl,
                    'warning' => $warning,
                ];
            }

            if (($product['name'] ?? '') === '' || $productFailures !== []) {
                $skippedProducts[] = [
                    'url' => $sourceUrl,
                    'reason' => $this->firstFailureReason($productFailures) ?? 'missing_required_product_data',
                ];

                if ($this->hasRateLimitFailure($productFailures)) {
                    $stoppedEarly = true;
                    $stopReason = 'HTTP 429 rate limit or temporary block from Zamst';
                    $this->emit('Stopping crawl: '.$stopReason);
                    break;
                }

                continue;
            }

            $canonicalUrl = $this->productScraper->normalizeProductUrl(
                (string) ($product['canonical_url'] ?? $sourceUrl),
            );
            $externalId = $this->stringScalar($product['external_product_id'] ?? null);

            if ($canonicalUrl !== null && isset($canonicalUrls[$canonicalUrl])) {
                $skippedDuplicateUrls[] = [
                    'url' => $sourceUrl,
                    'canonical_url' => $canonicalUrl,
                    'kept_url' => $canonicalUrls[$canonicalUrl],
                ];
                continue;
            }

            if ($externalId !== null && isset($externalIds[$externalId])) {
                $skippedDuplicateExternalIds[] = [
                    'external_product_id' => $externalId,
                    'url' => $sourceUrl,
                    'kept_url' => $externalIds[$externalId],
                ];
                continue;
            }

            if ($canonicalUrl !== null) {
                $canonicalUrls[$canonicalUrl] = $sourceUrl;
            }

            if ($externalId !== null) {
                $externalIds[$externalId] = $sourceUrl;
            }

            $products[] = $product;
        }

        return [
            'source' => 'zamst',
            'product_count' => count($products),
            'products' => $products,
            'source_product_urls' => $sourceUrls,
            'source_product_url_count' => count($sourceUrls),
            'total_product_url_count' => count($allUrls),
            'offset' => max(0, $offset),
            'limit' => $limit,
            'request_delay_ms' => $this->requestDelayMilliseconds,
            'skipped_failed_products' => $skippedProducts,
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

        $urls = [];

        foreach (($discovery['products'] ?? []) as $product) {
            if (is_array($product) && is_string($product['url'] ?? null)) {
                $urls[] = $product['url'];
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function normalizeProductUrls(array $urls): array
    {
        $normalized = [];

        foreach ($urls as $url) {
            $candidate = $this->productScraper->normalizeProductUrl((string) $url);

            if ($candidate !== null) {
                $normalized[$candidate] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<string, mixed>|null  $discovery
     * @return array<string, array<string, mixed>>
     */
    private function productContextsByUrl(?array $discovery): array
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
            if (is_scalar($entry) && trim((string) $entry) !== '') {
                $result[] = trim((string) $entry);
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
            if (is_string($key) && is_scalar($entry)) {
                $result[$key] = (string) $entry;
            }
        }

        return $result;
    }

    private function stringScalar(mixed $value): ?string
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
        foreach ($failedUrls as $reason) {
            return $reason;
        }

        return null;
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
