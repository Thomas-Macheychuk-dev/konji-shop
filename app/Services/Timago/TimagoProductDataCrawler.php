<?php

declare(strict_types=1);

namespace App\Services\Timago;

use Closure;

final class TimagoProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $requestDelayMilliseconds = 500;

    public function __construct(
        private readonly TimagoProductScraper $productScraper,
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
    public function crawlFromProductLinkDiscovery(array $productLinkDiscovery, ?int $limit = null, int $offset = 0): array
    {
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
    public function crawlProductUrls(array $productUrls, ?int $limit = null, int $offset = 0, ?array $productLinkDiscovery = null): array
    {
        $sourceUrls = $this->sliceProductUrls($productUrls, $limit, $offset);
        $sourceUrlCount = count($sourceUrls);
        $contextsByUrl = $this->productContextsByUrl($productLinkDiscovery);

        $products = [];
        $scrapedUrls = [];
        $canonicalUrls = [];
        $externalProductIds = [];
        $failedUrls = [];
        $skippedFailedProducts = [];
        $skippedDuplicateUrls = [];
        $skippedDuplicateExternalIds = [];
        $warnings = [];

        foreach ($sourceUrls as $index => $sourceUrl) {
            $this->emit('Scraping product '.($index + 1).'/'.$sourceUrlCount.': '.$sourceUrl);

            if (isset($scrapedUrls[$sourceUrl])) {
                $skippedDuplicateUrls[] = [
                    'url' => $sourceUrl,
                    'reason' => 'duplicate_source_url',
                ];

                continue;
            }

            $scrapedUrls[$sourceUrl] = true;
            $product = $this->productScraper->scrape($sourceUrl, $contextsByUrl[$sourceUrl] ?? null);
            $productFailedUrls = $this->stringMap($product['failed_urls'] ?? []);

            foreach ($productFailedUrls as $failedUrl => $reason) {
                $failedUrls[$failedUrl] = $reason;
            }

            foreach ($this->stringList($product['warnings'] ?? []) as $warning) {
                $warnings[] = [
                    'url' => $sourceUrl,
                    'warning' => $warning,
                ];
            }

            if (($product['name'] ?? '') === '' || $productFailedUrls !== []) {
                $skippedFailedProducts[] = [
                    'url' => $sourceUrl,
                    'reason' => $this->firstFailureReason($productFailedUrls) ?? 'missing_required_product_data',
                ];

                continue;
            }

            $canonicalUrl = $this->productScraper->normalizeProductUrl((string) ($product['canonical_url'] ?? $product['source_url'] ?? $sourceUrl));
            $externalProductId = $this->normalizeProductId($product['external_product_id'] ?? null);

            if ($canonicalUrl !== null && isset($canonicalUrls[$canonicalUrl])) {
                $skippedDuplicateUrls[] = [
                    'url' => $sourceUrl,
                    'canonical_url' => $canonicalUrl,
                    'kept_url' => $canonicalUrls[$canonicalUrl],
                    'reason' => 'duplicate_canonical_url',
                ];

                continue;
            }

            if ($externalProductId !== null && isset($externalProductIds[$externalProductId])) {
                $skippedDuplicateExternalIds[] = [
                    'external_product_id' => $externalProductId,
                    'url' => $sourceUrl,
                    'kept_url' => $externalProductIds[$externalProductId],
                    'reason' => 'duplicate_external_product_id',
                ];

                continue;
            }

            if ($canonicalUrl !== null) {
                $canonicalUrls[$canonicalUrl] = $sourceUrl;
            }

            if ($externalProductId !== null) {
                $externalProductIds[$externalProductId] = $sourceUrl;
            }

            $products[] = $product;
        }

        return [
            'source' => 'timago',
            'product_count' => count($products),
            'products' => $products,
            'source_product_urls' => $sourceUrls,
            'source_product_url_count' => count($sourceUrls),
            'total_product_url_count' => $this->totalProductUrlCount($productUrls),
            'offset' => max(0, $offset),
            'limit' => $limit,
            'request_delay_ms' => $this->requestDelayMilliseconds,
            'product_link_discovery' => $productLinkDiscovery,
            'skipped_failed_products' => $skippedFailedProducts,
            'skipped_duplicate_urls' => $skippedDuplicateUrls,
            'skipped_duplicate_external_ids' => $skippedDuplicateExternalIds,
            'warnings' => $warnings,
            'failed_urls' => $failedUrls,
            'failed_url_counts' => $this->failureCounts($failedUrls),
        ];
    }

    /**
     * @param  array<string, mixed>  $productLinkDiscovery
     * @return array<int, string>
     */
    private function productUrlsFromDiscovery(array $productLinkDiscovery): array
    {
        if (is_array($productLinkDiscovery['product_urls'] ?? null)) {
            return $this->stringList($productLinkDiscovery['product_urls']);
        }

        if (! is_array($productLinkDiscovery['products'] ?? null)) {
            return [];
        }

        $urls = [];

        foreach ($productLinkDiscovery['products'] as $product) {
            if (! is_array($product) || ! is_string($product['url'] ?? null)) {
                continue;
            }

            $urls[] = $product['url'];
        }

        return $this->stringList($urls);
    }

    /**
     * @param  array<int, string>  $productUrls
     * @return array<int, string>
     */
    private function sliceProductUrls(array $productUrls, ?int $limit, int $offset): array
    {
        $normalized = [];

        foreach ($productUrls as $url) {
            $normalizedUrl = $this->productScraper->normalizeProductUrl($url);

            if ($normalizedUrl !== null) {
                $normalized[] = $normalizedUrl;
            }
        }

        $offset = max(0, $offset);
        $limit = $limit !== null && $limit > 0 ? $limit : null;

        return $limit === null
            ? array_slice($normalized, $offset)
            : array_slice($normalized, $offset, $limit);
    }

    /**
     * @param  array<int, string>  $productUrls
     */
    private function totalProductUrlCount(array $productUrls): int
    {
        return count(array_values(array_filter(array_map(
            fn (mixed $url): ?string => is_string($url) ? $this->productScraper->normalizeProductUrl($url) : null,
            $productUrls,
        ))));
    }

    /**
     * @param  array<string, mixed>|null  $productLinkDiscovery
     * @return array<string, array<string, mixed>>
     */
    private function productContextsByUrl(?array $productLinkDiscovery): array
    {
        if (! is_array($productLinkDiscovery['products'] ?? null)) {
            return [];
        }

        $contexts = [];

        foreach ($productLinkDiscovery['products'] as $product) {
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

    private function normalizeProductId(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $strings[] = trim($entry);
            }
        }

        return array_values($strings);
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && is_string($entry)) {
                $map[$key] = $entry;
            }
        }

        return $map;
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
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
    }
}
