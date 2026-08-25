<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Closure;

final class ArmedicalProductDataCrawler
{
    private ?Closure $progressCallback = null;

    public function __construct(
        private readonly ArmedicalProductScraper $productScraper,
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
        $this->productScraper->withRequestDelayMilliseconds($milliseconds);

        return $this;
    }

    /** @return array<string, mixed> */
    public function crawlFromProductLinkDiscovery(array $discovery, ?int $limit = null, int $offset = 0): array
    {
        $contextsByUrl = [];

        foreach ($discovery['products'] ?? [] as $product) {
            if (! is_array($product) || ! is_string($product['url'] ?? null)) {
                continue;
            }

            $url = ArmedicalUrl::product($product['url']);

            if ($url !== null) {
                $contextsByUrl[$url] = $product;
            }
        }

        $urls = [];

        foreach ($discovery['product_urls'] ?? array_keys($contextsByUrl) as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = ArmedicalUrl::product($url);

            if ($normalized !== null) {
                $urls[] = $normalized;
            }
        }

        return $this->crawlProductUrls($urls, $limit, $offset, $contextsByUrl, $discovery);
    }

    /**
     * @param  array<int, string>  $productUrls
     * @param  array<string, array<string, mixed>>  $contextsByUrl
     * @return array<string, mixed>
     */
    public function crawlProductUrls(array $productUrls, ?int $limit = null, int $offset = 0, array $contextsByUrl = [], ?array $discovery = null): array
    {
        $productUrls = array_values(array_unique(array_filter(array_map(
            static fn (string $url): ?string => ArmedicalUrl::product($url),
            $productUrls,
        ))));
        $selectedUrls = $limit !== null && $limit > 0
            ? array_slice($productUrls, max(0, $offset), $limit)
            : array_slice($productUrls, max(0, $offset));

        $products = [];
        $failedUrls = [];
        $warnings = [];
        $externalIds = [];
        $skippedDuplicates = [];
        $stoppedEarly = false;
        $stopReason = null;

        foreach ($selectedUrls as $index => $url) {
            $this->emit('Scraping ARmedical product '.($index + 1).'/'.count($selectedUrls).': '.$url);
            $product = $this->productScraper->scrape($url, $contextsByUrl[$url] ?? null);

            foreach ($product['failed_urls'] ?? [] as $failedUrl => $reason) {
                if (is_string($failedUrl) && is_string($reason)) {
                    $failedUrls[$failedUrl] = $reason;
                }
            }

            foreach ($product['warnings'] ?? [] as $warning) {
                if (is_string($warning)) {
                    $warnings[] = ['url' => $url, 'warning' => $warning];
                }
            }

            if (($product['name'] ?? '') === '' || ($product['failed_urls'] ?? []) !== []) {
                if ($this->hasRateLimitFailure($product['failed_urls'] ?? [])) {
                    $stoppedEarly = true;
                    $stopReason = 'HTTP 429 rate limit or temporary block from ARmedical';
                    break;
                }

                continue;
            }

            $externalId = is_string($product['external_product_id'] ?? null) ? $product['external_product_id'] : null;

            if ($externalId !== null && isset($externalIds[$externalId])) {
                $skippedDuplicates[] = [
                    'external_product_id' => $externalId,
                    'url' => $url,
                    'kept_url' => $externalIds[$externalId],
                ];

                continue;
            }

            if ($externalId !== null) {
                $externalIds[$externalId] = $url;
            }

            $products[] = $product;
        }

        return [
            'source' => 'armedical',
            'product_count' => count($products),
            'products' => $products,
            'source_product_urls' => $selectedUrls,
            'source_product_url_count' => count($selectedUrls),
            'total_product_url_count' => count($productUrls),
            'offset' => max(0, $offset),
            'limit' => $limit,
            'product_link_discovery' => $discovery,
            'skipped_duplicate_external_ids' => $skippedDuplicates,
            'warnings' => $warnings,
            'failed_urls' => $failedUrls,
            'stopped_early' => $stoppedEarly,
            'stop_reason' => $stopReason,
        ];
    }

    private function hasRateLimitFailure(array $failedUrls): bool
    {
        foreach ($failedUrls as $reason) {
            if (is_string($reason) && str_contains($reason, '429')) {
                return true;
            }
        }

        return false;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
