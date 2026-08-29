<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use Closure;

final class NeoxmedProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 750;

    public function __construct(
        private readonly NeoxmedCategoryUrlScraper $categoryScraper,
        private readonly NeoxmedProductPageScraper $productPageScraper,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;
        $this->categoryScraper->withProgressCallback($callback);
        $this->productPageScraper->withProgressCallback($callback);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);
        $this->categoryScraper->withTimeout($this->timeoutSeconds);
        $this->productPageScraper->withTimeout($this->timeoutSeconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);
        $this->productPageScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->categoryScraper->withMaxAttempts($attempts, $retryDelayMilliseconds);
        $this->productPageScraper->withMaxAttempts($attempts, $retryDelayMilliseconds);

        return $this;
    }

    /**
     * Discover the seven NeoxMed category pages and scrape every product section.
     *
     * @return array<string,mixed>
     */
    public function crawl(?int $limit = null, int $offset = 0): array
    {
        $categoryDiscovery = $this->categoryScraper->scrape();

        return $this->crawlFromCategoryDiscovery($categoryDiscovery, $limit, $offset);
    }

    /**
     * @param  array<string,mixed>  $categoryDiscovery
     * @return array<string,mixed>
     */
    public function crawlFromCategoryDiscovery(array $categoryDiscovery, ?int $limit = null, int $offset = 0): array
    {
        $categories = $this->categoriesFromDiscovery($categoryDiscovery);
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories,
        ));

        return $this->crawlCategories($categoryUrls, $categories, $limit, $offset, $categoryDiscovery);
    }

    /**
     * @param  array<int,string>  $categoryUrls
     * @return array<string,mixed>
     */
    public function crawlCategoryUrls(array $categoryUrls, ?int $limit = null, int $offset = 0): array
    {
        return $this->crawlCategories($categoryUrls, [], $limit, $offset, null);
    }

    /**
     * @param  array<int,string>  $categoryUrls
     * @param  array<int,array<string,mixed>>  $categories
     * @param  array<string,mixed>|null  $categoryDiscovery
     * @return array<string,mixed>
     */
    private function crawlCategories(
        array $categoryUrls,
        array $categories,
        ?int $limit,
        int $offset,
        ?array $categoryDiscovery,
    ): array {
        $normalizedUrls = $this->normalizedCategoryUrls($categoryUrls);
        $contexts = $this->contextsByUrl($categories);
        $productsByCode = [];
        $visitedUrls = [];
        $failedUrls = [];
        $warnings = [];
        $duplicateProducts = [];
        $stoppedEarly = false;
        $stopReason = null;

        foreach ($normalizedUrls as $index => $categoryUrl) {
            $this->emit('Scraping NeoxMed category '.($index + 1).'/'.count($normalizedUrls).': '.$categoryUrl);
            $result = $this->productPageScraper->scrape($categoryUrl, $contexts[$categoryUrl] ?? null);
            $visitedUrls[] = $categoryUrl;

            foreach ($this->stringMap($result['failed_urls'] ?? []) as $failedUrl => $reason) {
                $failedUrls[$failedUrl] = $reason;
            }

            foreach ($this->stringList($result['warnings'] ?? []) as $warning) {
                $warnings[] = [
                    'url' => $categoryUrl,
                    'warning' => $warning,
                ];
            }

            if ($this->hasRateLimitFailure($result['failed_urls'] ?? [])) {
                $stoppedEarly = true;
                $stopReason = 'HTTP 429 rate limit or temporary block from NeoxMed';
                break;
            }

            foreach (is_array($result['products'] ?? null) ? $result['products'] : [] as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $code = is_string($product['external_product_id'] ?? null)
                    ? trim((string) $product['external_product_id'])
                    : '';

                if ($code === '') {
                    continue;
                }

                if (! isset($productsByCode[$code])) {
                    $productsByCode[$code] = $product;

                    continue;
                }

                $duplicateProducts[] = [
                    'external_product_id' => $code,
                    'duplicate_category_url' => $categoryUrl,
                    'kept_source_url' => $productsByCode[$code]['source_url'] ?? null,
                ];
                $productsByCode[$code] = $this->mergeProduct($productsByCode[$code], $product);
            }
        }

        $allProducts = array_values($productsByCode);
        usort($allProducts, static fn (array $left, array $right): int => strnatcasecmp(
            (string) ($left['external_product_id'] ?? ''),
            (string) ($right['external_product_id'] ?? ''),
        ));

        $offset = max(0, $offset);
        $limit = $limit !== null && $limit > 0 ? $limit : null;
        $selectedProducts = $limit === null
            ? array_slice($allProducts, $offset)
            : array_slice($allProducts, $offset, $limit);

        return [
            'source' => 'neoxmed',
            'product_count' => count($selectedProducts),
            'products' => $selectedProducts,
            'discovered_product_count' => count($allProducts),
            'source_category_urls' => $normalizedUrls,
            'source_category_url_count' => count($normalizedUrls),
            'visited_urls' => array_values(array_unique($visitedUrls)),
            'failed_urls' => $failedUrls,
            'failed_url_counts' => $this->failureCounts($failedUrls),
            'duplicate_products' => $duplicateProducts,
            'warnings' => $warnings,
            'offset' => $offset,
            'limit' => $limit,
            'request_delay_ms' => $this->requestDelayMilliseconds,
            'category_discovery' => $categoryDiscovery,
            'stopped_early' => $stoppedEarly,
            'stop_reason' => $stopReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $categoryDiscovery
     * @return array<int,array<string,mixed>>
     */
    private function categoriesFromDiscovery(array $categoryDiscovery): array
    {
        if (! is_array($categoryDiscovery['categories'] ?? null)) {
            return [];
        }

        $categories = [];

        foreach ($categoryDiscovery['categories'] as $category) {
            if (! is_array($category) || ! is_string($category['url'] ?? null)) {
                continue;
            }

            $normalized = $this->productPageScraper->normalizeCategoryUrl((string) $category['url']);

            if ($normalized === null) {
                continue;
            }

            $category['url'] = $normalized;
            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * @param  array<int,string>  $urls
     * @return array<int,string>
     */
    private function normalizedCategoryUrls(array $urls): array
    {
        $normalized = [];

        foreach ($urls as $url) {
            $candidate = $this->productPageScraper->normalizeCategoryUrl($url);

            if ($candidate !== null) {
                $normalized[$candidate] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<int,array<string,mixed>>  $categories
     * @return array<string,array<string,mixed>>
     */
    private function contextsByUrl(array $categories): array
    {
        $contexts = [];

        foreach ($categories as $category) {
            if (! is_string($category['url'] ?? null)) {
                continue;
            }

            $contexts[(string) $category['url']] = $category;
        }

        return $contexts;
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $duplicate
     * @return array<string,mixed>
     */
    private function mergeProduct(array $existing, array $duplicate): array
    {
        $existing['categories'] = array_values(array_unique(array_merge(
            $this->stringList($existing['categories'] ?? []),
            $this->stringList($duplicate['categories'] ?? []),
        )));

        $paths = [];

        foreach (array_merge(
            is_array($existing['source_category_paths'] ?? null) ? $existing['source_category_paths'] : [],
            is_array($duplicate['source_category_paths'] ?? null) ? $duplicate['source_category_paths'] : [],
        ) as $path) {
            if (! is_array($path)) {
                continue;
            }

            $normalizedPath = $this->stringList($path);

            if ($normalizedPath !== [] && ! in_array($normalizedPath, $paths, true)) {
                $paths[] = $normalizedPath;
            }
        }

        $existing['source_category_paths'] = $paths;
        $existing['nfz_codes'] = array_values(array_unique(array_merge(
            $this->stringList($existing['nfz_codes'] ?? []),
            $this->stringList($duplicate['nfz_codes'] ?? []),
        )));
        $existing['images'] = $this->mergeImages($existing['images'] ?? [], $duplicate['images'] ?? []);
        $existing['size_chart_images'] = $this->mergeImages($existing['size_chart_images'] ?? [], $duplicate['size_chart_images'] ?? []);
        $existing['warnings'] = array_values(array_unique(array_merge(
            $this->stringList($existing['warnings'] ?? []),
            $this->stringList($duplicate['warnings'] ?? []),
        )));

        if (($existing['description_text'] ?? '') === '' && ($duplicate['description_text'] ?? '') !== '') {
            $existing['description_text'] = $duplicate['description_text'];
            $existing['description_lines'] = $duplicate['description_lines'] ?? [];
            $existing['description_html'] = $duplicate['description_html'] ?? null;
        }

        return $existing;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function mergeImages(mixed $left, mixed $right): array
    {
        $images = [];

        foreach (array_merge(is_array($left) ? $left : [], is_array($right) ? $right : []) as $image) {
            if (! is_array($image) || ! is_string($image['url'] ?? null)) {
                continue;
            }

            $images[$image['url']] = $image;
        }

        return array_values($images);
    }

    private function hasRateLimitFailure(mixed $failedUrls): bool
    {
        foreach ($this->stringMap($failedUrls) as $reason) {
            if (str_contains($reason, 'HTTP 429')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,string>  $failedUrls
     * @return array<string,int>
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
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<string,string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
