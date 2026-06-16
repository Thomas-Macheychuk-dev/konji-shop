<?php

declare(strict_types=1);

namespace App\Services\TwojaPeruka;

use Closure;

final class TwojaPerukaProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly TwojaPerukaProductScraper $productScraper,
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

        $products = [];
        $scrapedUrls = [];
        $canonicalUrls = [];
        $externalProductIds = [];
        $failedUrls = [];
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
            $product = $this->productScraper->scrape($sourceUrl);

            foreach ($this->stringMap($product['failed_urls'] ?? []) as $failedUrl => $reason) {
                $failedUrls[$failedUrl] = $reason;
            }

            foreach ($this->stringList($product['warnings'] ?? []) as $warning) {
                $warnings[] = [
                    'url' => $sourceUrl,
                    'warning' => $warning,
                ];
            }

            if (($product['name'] ?? '') === '' || $product['failed_urls'] !== []) {
                continue;
            }

            $canonicalUrl = $this->normalizeUrl((string) ($product['canonical_url'] ?? $product['source_url'] ?? $sourceUrl));
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
            'source' => 'twojaperuka',
            'product_count' => count($products),
            'products' => $products,
            'source_product_urls' => $sourceUrls,
            'source_product_url_count' => count($sourceUrls),
            'total_product_url_count' => count(array_values(array_filter(array_map(
                fn (mixed $url): ?string => is_string($url) ? $this->normalizeUrl($url) : null,
                $productUrls,
            )))),
            'offset' => max(0, $offset),
            'limit' => $limit,
            'product_link_discovery' => $productLinkDiscovery,
            'skipped_duplicate_urls' => $skippedDuplicateUrls,
            'skipped_duplicate_external_ids' => $skippedDuplicateExternalIds,
            'warnings' => $warnings,
            'failed_urls' => $failedUrls,
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
            $normalizedUrl = $this->normalizeUrl($url);

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

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://twojaperuka.pl'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);

        if (! in_array($host, ['twojaperuka.pl', 'www.twojaperuka.pl'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = '/'.ltrim($path, '/');
        $path = rtrim($path, '/') ?: '/';

        return 'https://twojaperuka.pl'.$path;
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

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
