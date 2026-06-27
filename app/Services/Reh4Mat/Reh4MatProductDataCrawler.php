<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use Closure;

final class Reh4MatProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 5000;

    public function __construct(
        private readonly Reh4MatProductScraper $productScraper,
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
        $stoppedEarly = false;
        $stopReason = null;

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
            $product = $this->productScraper->scrape($sourceUrl);
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

                if ($this->hasRateLimitFailure($productFailedUrls)) {
                    $stoppedEarly = true;
                    $stopReason = 'HTTP 429 rate limit or temporary block from Reh4Mat';
                    $this->emit('Stopping crawl: '.$stopReason);
                    break;
                }

                continue;
            }

            $product = $this->enrichWithProductLinkContext($product, $sourceUrl, $contextsByUrl[$sourceUrl] ?? null);

            $canonicalUrl = $this->normalizeProductUrl((string) ($product['canonical_url'] ?? $product['source_url'] ?? $sourceUrl));
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
            'source' => 'reh4mat',
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
            'stopped_early' => $stoppedEarly,
            'stop_reason' => $stopReason,
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
            $normalizedUrl = $this->normalizeProductUrl($url);

            if ($normalizedUrl === null) {
                continue;
            }

            $normalized[] = $normalizedUrl;
        }

        $offset = max(0, $offset);
        $limit = $limit !== null && $limit > 0 ? $limit : null;

        if ($limit === null) {
            return array_slice($normalized, $offset);
        }

        return array_slice($normalized, $offset, $limit);
    }

    /**
     * @param  array<int, string>  $productUrls
     */
    private function totalProductUrlCount(array $productUrls): int
    {
        return count(array_values(array_filter(array_map(
            fn (mixed $url): ?string => is_string($url) ? $this->normalizeProductUrl($url) : null,
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

            $url = $this->normalizeProductUrl($product['url']);

            if ($url === null) {
                continue;
            }

            $contexts[$url] = $product;
        }

        return $contexts;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function enrichWithProductLinkContext(array $product, string $sourceUrl, ?array $context): array
    {
        if ($context === null) {
            return $product + [
                'source_category_name' => null,
                'source_category_url' => null,
                'source_top_category_name' => null,
                'source_top_category_url' => null,
                'source_category_path' => [],
                'source_product_list_name' => null,
            ];
        }

        $categoryPath = $this->stringList($context['category_path'] ?? []);
        $categoryName = is_string($context['category_name'] ?? null) ? trim($context['category_name']) : null;
        $categoryUrl = is_string($context['category_url'] ?? null) ? $context['category_url'] : null;
        $topCategoryName = is_string($context['top_category_name'] ?? null) ? trim($context['top_category_name']) : null;
        $topCategoryUrl = is_string($context['top_category_url'] ?? null) ? $context['top_category_url'] : null;
        $productListName = is_string($context['name'] ?? null) ? trim($context['name']) : null;

        if ($categoryPath !== [] && $this->stringList($product['categories'] ?? []) === []) {
            $product['categories'] = $categoryPath;
        }

        if (($product['category'] ?? null) === null && $categoryName !== null && $categoryName !== '') {
            $product['category'] = $categoryName;
        }

        if (($product['name'] ?? '') === '' && $productListName !== null && $productListName !== '') {
            $product['name'] = $productListName;
        }

        $product['source_url'] = $product['source_url'] ?? $sourceUrl;
        $product['source_category_name'] = $categoryName;
        $product['source_category_url'] = $categoryUrl;
        $product['source_top_category_name'] = $topCategoryName;
        $product['source_top_category_url'] = $topCategoryUrl;
        $product['source_category_path'] = $categoryPath;
        $product['source_product_list_name'] = $productListName;

        return $product;
    }

    private function normalizeProductUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with(mb_strtolower($url), 'mailto:')
            || str_starts_with(mb_strtolower($url), 'tel:')
            || str_starts_with(mb_strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://www.reh4mat.com'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);

        if (! in_array($host, ['www.reh4mat.com', 'reh4mat.com'], true)) {
            return null;
        }

        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = rtrim($path, '/');

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (count($segments) < 3 || ($segments[0] ?? '') !== 'produkt') {
            return null;
        }

        return 'https://www.reh4mat.com'.$path.'/';
    }

    private function normalizeProductId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $id = trim((string) $value);

        return $id === '' ? null : $id;
    }

    /**
     * @param  mixed  $values
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  mixed  $values
     * @return array<string, string>
     */
    private function stringMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $map = [];

        foreach ($values as $key => $value) {
            $map[(string) $key] = (string) $value;
        }

        return $map;
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
            if ($reason === 'HTTP 429') {
                return true;
            }
        }

        return false;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
