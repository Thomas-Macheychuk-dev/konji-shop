<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use Closure;
use Throwable;

final class PerukaProductDataCrawler
{
    private ?Closure $progressCallback = null;

    private int $requestDelayMilliseconds = 500;

    public function __construct(
        private readonly PerukaProductUrlScraper $productUrlScraper,
        private readonly PerukaProductScraper $productScraper,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;
        $this->productUrlScraper->withProgressCallback($callback);
        $this->productScraper->withProgressCallback($callback);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->productUrlScraper->withTimeout($seconds);
        $this->productScraper->withTimeout($seconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->productUrlScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);
        $this->productScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    /**
     * Crawl product data from explicit product URLs and/or product URLs discovered from category pages.
     * Variant colour links found on product pages are enqueued as independent products.
     *
     * @param  array<int, string>  $productUrls
     * @param  array<int, string>  $categoryUrls
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function crawl(
        array $productUrls = [],
        array $categoryUrls = [],
        array $startUrls = [PerukaCategoryUrlScraper::DEFAULT_URL],
        bool $discoverCategories = true,
        ?int $limit = null,
        ?int $maxPages = null,
        ?int $categoryLimit = null,
    ): array {
        $queue = [];
        $queuedUrls = [];
        $scrapedUrls = [];
        $scrapedExternalIds = [];
        $skippedDuplicateUrls = [];
        $skippedDuplicateExternalIds = [];
        $failedUrls = [];
        $products = [];
        $initialProductUrls = [];
        $variantProductUrls = [];
        $discoveryResult = null;

        foreach ($productUrls as $productUrl) {
            $this->enqueue($queue, $queuedUrls, $productUrl, $initialProductUrls);
        }

        if ($productUrls === [] || $categoryUrls !== []) {
            $this->emit('Discovering Peruka product URLs before product-data crawl...');

            $discoveryResult = $this->productUrlScraper->scrape(
                categoryUrls: $categoryUrls,
                startUrls: $startUrls,
                discoverCategories: $discoverCategories,
                limit: null,
                maxPages: $maxPages,
                categoryLimit: $categoryLimit,
            );

            foreach ($discoveryResult['product_urls'] as $productUrl) {
                $this->enqueue($queue, $queuedUrls, $productUrl, $initialProductUrls);
            }
        }

        $this->emit('Peruka product-data queue size: '.count($queue));

        while ($queue !== []) {
            if ($limit !== null && count($products) >= $limit) {
                $this->emit('Product-data crawl limit reached: '.$limit);
                break;
            }

            $url = array_shift($queue);

            if (! is_string($url)) {
                continue;
            }

            $normalizedUrl = $this->productScraper->normalizeProductUrl($url);

            if ($normalizedUrl === null) {
                continue;
            }

            if (isset($scrapedUrls[$normalizedUrl])) {
                $skippedDuplicateUrls[$normalizedUrl] = true;
                continue;
            }

            $scrapedUrls[$normalizedUrl] = true;

            try {
                $product = $this->productScraper->scrape($normalizedUrl);
            } catch (Throwable $exception) {
                $failedUrls[$normalizedUrl] = $exception->getMessage();
                continue;
            }

            $externalProductId = is_string($product['external_product_id'] ?? null)
                ? $product['external_product_id']
                : null;

            if ($externalProductId !== null && isset($scrapedExternalIds[$externalProductId])) {
                $skippedDuplicateExternalIds[$normalizedUrl] = $externalProductId;
                continue;
            }

            if ($externalProductId !== null) {
                $scrapedExternalIds[$externalProductId] = true;
            }

            $products[] = $product;

            foreach (($product['variant_product_urls'] ?? []) as $variantUrl) {
                if (! is_string($variantUrl)) {
                    continue;
                }

                $normalizedVariantUrl = $this->productScraper->normalizeProductUrl($variantUrl, $normalizedUrl);

                if ($normalizedVariantUrl === null) {
                    continue;
                }

                $variantProductUrls[$normalizedVariantUrl] = true;

                if (isset($scrapedUrls[$normalizedVariantUrl]) || isset($queuedUrls[$normalizedVariantUrl])) {
                    $skippedDuplicateUrls[$normalizedVariantUrl] = true;
                    continue;
                }

                $this->emit('  Enqueued Peruka colour product as independent product: '.$normalizedVariantUrl);
                $queue[] = $normalizedVariantUrl;
                $queuedUrls[$normalizedVariantUrl] = true;
            }
        }

        return [
            'products' => $products,
            'product_count' => count($products),
            'initial_product_urls' => array_keys($initialProductUrls),
            'variant_product_urls' => array_keys($variantProductUrls),
            'scraped_product_urls' => array_keys($scrapedUrls),
            'scraped_external_ids' => array_map('strval', array_keys($scrapedExternalIds)),
            'skipped_duplicate_urls' => array_keys($skippedDuplicateUrls),
            'skipped_duplicate_external_ids' => $skippedDuplicateExternalIds,
            'failed_urls' => $failedUrls,
            'discovery' => $discoveryResult,
        ];
    }

    /**
     * @param  array<int, string>  $queue
     * @param  array<string, true>  $queuedUrls
     * @param  array<string, true>  $bucket
     */
    private function enqueue(array &$queue, array &$queuedUrls, string $url, array &$bucket): void
    {
        $normalizedUrl = $this->productScraper->normalizeProductUrl($url);

        if ($normalizedUrl === null || isset($queuedUrls[$normalizedUrl])) {
            return;
        }

        $queue[] = $normalizedUrl;
        $queuedUrls[$normalizedUrl] = true;
        $bucket[$normalizedUrl] = true;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
    }
}
