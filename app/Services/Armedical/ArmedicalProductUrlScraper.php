<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Closure;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ArmedicalProductUrlScraper
{
    public const DEFAULT_URL = ArmedicalUrl::OFFER_ARCHIVE_URL;

    private ?Closure $progressCallback = null;

    public function __construct(
        private readonly ArmedicalHttpClient $http,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->http->withTimeout($seconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->http->withMaxAttempts($attempts, $retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->http->withRequestDelayMilliseconds($milliseconds);

        return $this;
    }

    /**
     * @param  array<int, string>  $listingUrls
     * @return array<string, mixed>
     */
    public function scrapeListings(array $listingUrls = [self::DEFAULT_URL], ?int $pageLimit = null): array
    {
        $queue = [];
        $queued = [];
        $visited = [];
        $failed = [];
        $productsByUrl = [];
        $sourceCategories = [];
        $pageLimit = $pageLimit !== null ? max(1, $pageLimit) : null;

        foreach ($listingUrls as $listingUrl) {
            $url = ArmedicalUrl::listingPage($listingUrl, self::DEFAULT_URL);

            if ($url === null) {
                continue;
            }

            $queue[] = $url;
            $queued[$url] = true;

            $categoryUrl = ArmedicalUrl::categoryCanonical($url);

            if ($categoryUrl !== null) {
                $sourceCategories[$categoryUrl] = true;
            }
        }

        while ($queue !== []) {
            if ($pageLimit !== null && count($visited) >= $pageLimit) {
                break;
            }

            $url = array_shift($queue);

            if (! is_string($url) || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching ARmedical product listing: '.$url);
            $response = $this->http->fetch($url);

            if (! is_string($response['body'])) {
                $failed[$url] = (string) ($response['error'] ?? 'Unknown HTTP error');

                if ((int) ($response['status'] ?? 0) === 429) {
                    break;
                }

                continue;
            }

            $categoryUrl = ArmedicalUrl::categoryCanonical($url);

            foreach ($this->extractProducts($response['body'], $url) as $product) {
                if (! isset($productsByUrl[$product['url']])) {
                    $productsByUrl[$product['url']] = $product + [
                        'source_category_url' => $categoryUrl,
                    ];
                } elseif ($productsByUrl[$product['url']]['source_category_url'] === null && $categoryUrl !== null) {
                    $productsByUrl[$product['url']]['source_category_url'] = $categoryUrl;
                }
            }

            foreach ($this->extractPaginationUrls($response['body'], $url) as $paginationUrl) {
                if (! isset($visited[$paginationUrl], $queued[$paginationUrl])) {
                    $queue[] = $paginationUrl;
                    $queued[$paginationUrl] = true;
                }
            }
        }

        return [
            'source' => 'armedical',
            'source_categories' => array_keys($sourceCategories),
            'products' => array_values($productsByUrl),
            'product_urls' => array_keys($productsByUrl),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
            'page_limit' => $pageLimit,
            'stopped_early' => $queue !== [],
            'stop_reason' => $queue !== [] ? 'page limit reached or listing crawl stopped early' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $categoryDiscovery
     * @return array<string, mixed>
     */
    public function scrapeFromCategoryDiscovery(array $categoryDiscovery, ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        $urls = [];

        foreach ($categoryDiscovery['product_category_urls'] ?? [] as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = ArmedicalUrl::categoryCanonical($url);

            if ($normalized !== null) {
                $urls[] = $normalized;
            }
        }

        $urls = array_values(array_unique($urls));

        if ($categoryLimit !== null) {
            $urls = array_slice($urls, 0, max(1, $categoryLimit));
        }

        return $this->scrapeListings($urls, $pageLimit);
    }

    /**
     * @return array<int, array{url: string, external_product_id: string, catalogue_number: string|null, name: string|null}>
     */
    private function extractProducts(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $products = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$products, $baseUrl): void {
            $url = ArmedicalUrl::product((string) $node->attr('href'), $baseUrl);

            if ($url === null) {
                return;
            }

            $externalProductId = ArmedicalUrl::externalProductId($url);

            if ($externalProductId === null) {
                return;
            }

            $text = $this->normalizeText((string) $node->text(''));
            [$catalogueNumber, $name] = $this->splitCatalogueNumberAndName($text);

            $products[$url] = [
                'url' => $url,
                'external_product_id' => $externalProductId,
                'catalogue_number' => $catalogueNumber,
                'name' => $name,
            ];
        });

        return array_values($products);
    }

    /** @return array<int, string> */
    private function extractPaginationUrls(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $pages = [];

        foreach (['a.next[href]', '.pagination a[href]', '.nav-links a[href]', 'a.page-numbers[href]', 'a[href*="/page/"]'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$pages, $baseUrl): void {
                    $url = ArmedicalUrl::listingPage((string) $node->attr('href'), $baseUrl);

                    if ($url !== null) {
                        $pages[$url] = true;
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        return array_keys($pages);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function splitCatalogueNumberAndName(string $text): array
    {
        if ($text === '') {
            return [null, null];
        }

        if (preg_match('/^((?:\d{4,5}\s+do\s+\d{4,5})|(?:[A-Z]{1,5}-?[A-Z0-9]{1,10}(?:-[A-Z0-9]{1,10})*(?:\s*\/\s*[A-Z]{1,5}-?[A-Z0-9]{1,10}(?:-[A-Z0-9]{1,10})*)*))\s+(.+)$/iu', $text, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [null, $text];
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
