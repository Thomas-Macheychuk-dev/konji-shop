<?php

declare(strict_types=1);

namespace App\Services\Antar;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class AntarProductUrlScraper
{
    private const ANTAR_HOST = 'antar.net';

    /**
     * Antar is WooCommerce. Product archive pages render products through Elementor's
     * WooCommerce Products widget and use `/produkt/{slug}/` detail URLs.
     * Pagination uses `?product-page=N` links inside `.woocommerce-pagination`.
     */
    private const PRODUCT_LINK_SELECTORS = [
        '.elementor-products-grid a.woocommerce-LoopProduct-link[href]',
        'a.woocommerce-LoopProduct-link[href]',
        '.woocommerce-loop-product__buttons a[href]',
        'li.product a[href]',
        '.products a[href*="/produkt/"]',
        'a[href*="/produkt/"]',
    ];

    private const NEXT_PAGE_SELECTORS = [
        'nav.woocommerce-pagination a.next.page-numbers[href]',
        'nav.woocommerce-pagination a.next[href]',
        'a.next.page-numbers[href]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    public function __construct(
        private readonly AntarCategoryUrlScraper $categoryScraper,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;
        $this->categoryScraper->withProgressCallback($callback);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);
        $this->categoryScraper->withTimeout($this->timeoutSeconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->maxAttempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds);
        $this->categoryScraper->withMaxAttempts($this->maxAttempts, $this->retryDelayMilliseconds);

        return $this;
    }

    /**
     * Convenience wrapper for callers/tests that only need product URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [AntarCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(array $startUrls = [AntarCategoryUrlScraper::DEFAULT_URL], ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        return $this->scrapeFromDiscoveredCategories(
            $this->categoryScraper->scrape($startUrls),
            $pageLimit,
            $categoryLimit,
        );
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    public function scrapeFromDiscoveredCategories(array $discovery, ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        $categories = [];

        foreach (($discovery['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeCategoryUrl((string) ($category['url'] ?? ''));

            if ($url === null) {
                continue;
            }

            $categories[$url] = [
                'source' => 'antar',
                'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($url)),
                'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($url)),
                'url' => $url,
                'path' => is_array($category['path'] ?? null) ? array_values($category['path']) : [$this->categoryNameFromUrl($url)],
                'level' => (int) ($category['level'] ?? max(1, count($this->categoryPathSegments($url)))),
            ];
        }

        foreach (($discovery['product_category_urls'] ?? []) as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = $this->normalizeCategoryUrl($url);

            if ($normalized === null || isset($categories[$normalized])) {
                continue;
            }

            $categories[$normalized] = [
                'source' => 'antar',
                'external_category_id' => $this->categoryExternalIdFromUrl($normalized),
                'name' => $this->categoryNameFromUrl($normalized),
                'url' => $normalized,
                'path' => [$this->categoryNameFromUrl($normalized)],
                'level' => max(1, count($this->categoryPathSegments($normalized))),
            ];
        }

        return $this->scrapeCategoryRecords(
            array_values($categories),
            $pageLimit,
            $categoryLimit,
            $this->stringMap($discovery['failed_urls'] ?? []),
        );
    }

    /**
     * @param  array<int, string>  $categoryUrls
     * @return array<string, mixed>
     */
    public function scrapeCategories(array $categoryUrls, ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        $categories = [];

        foreach ($categoryUrls as $url) {
            $normalized = $this->normalizeCategoryUrl($url);

            if ($normalized === null) {
                continue;
            }

            $categories[$normalized] = [
                'source' => 'antar',
                'external_category_id' => $this->categoryExternalIdFromUrl($normalized),
                'name' => $this->categoryNameFromUrl($normalized),
                'url' => $normalized,
                'path' => [$this->categoryNameFromUrl($normalized)],
                'level' => max(1, count($this->categoryPathSegments($normalized))),
            ];
        }

        return $this->scrapeCategoryRecords(array_values($categories), $pageLimit, $categoryLimit);
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string, string>  $initialFailedUrls
     * @return array<string, mixed>
     */
    private function scrapeCategoryRecords(array $categories, ?int $pageLimit = null, ?int $categoryLimit = null, array $initialFailedUrls = []): array
    {
        if ($categoryLimit !== null) {
            $categories = array_slice($categories, 0, max(1, $categoryLimit));
        }

        $productUrls = [];
        $categoryResults = [];
        $visitedUrls = [];
        $failedUrls = $initialFailedUrls;

        foreach ($categories as $index => $category) {
            $categoryUrl = (string) ($category['url'] ?? '');

            if ($categoryUrl === '') {
                continue;
            }

            $this->emit(sprintf(
                'Scraping Antar category %d/%d: %s',
                $index + 1,
                count($categories),
                (string) ($category['name'] ?? $categoryUrl),
            ));
            $this->emit('  '.$categoryUrl);

            $categoryProductUrls = [];
            $categoryPageUrls = [];
            $failedPageUrls = [];
            $nextPageUrl = $categoryUrl;
            $pagesScraped = 0;

            while ($nextPageUrl !== null) {
                if ($pageLimit !== null && $pagesScraped >= $pageLimit) {
                    break;
                }

                if (in_array($nextPageUrl, $categoryPageUrls, true)) {
                    break;
                }

                $pagesScraped++;
                $categoryPageUrls[] = $nextPageUrl;
                $visitedUrls[$nextPageUrl] = true;

                $this->emit(sprintf('  Fetching page %d: %s', $pagesScraped, $nextPageUrl));

                try {
                    $response = $this->fetch($nextPageUrl);

                    if (! $response->successful()) {
                        $failedUrls[$nextPageUrl] = 'HTTP '.$response->status();
                        $failedPageUrls[$nextPageUrl] = 'HTTP '.$response->status();

                        break;
                    }

                    $body = $response->body();
                    $pageProductUrls = $this->extractProductUrls($body, $nextPageUrl);

                    foreach ($pageProductUrls as $productUrl) {
                        $categoryProductUrls[$productUrl] = true;
                        $productUrls[$productUrl] = true;
                    }

                    $this->emit(sprintf('  Page %d product links: %d', $pagesScraped, count($pageProductUrls)));

                    $nextPageUrl = $this->extractNextPageUrl($body, $nextPageUrl);
                } catch (Throwable $exception) {
                    $failedUrls[$nextPageUrl] = $exception->getMessage();
                    $failedPageUrls[$nextPageUrl] = $exception->getMessage();

                    break;
                }
            }

            $categoryResults[] = [
                'category' => $category,
                'source' => 'antar',
                'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($categoryUrl)),
                'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($categoryUrl)),
                'url' => $categoryUrl,
                'category_path' => is_array($category['path'] ?? null) ? array_values($category['path']) : [$this->categoryNameFromUrl($categoryUrl)],
                'page_urls' => $categoryPageUrls,
                'pages_scraped' => count($categoryPageUrls),
                'failed_page_urls' => $failedPageUrls,
                'failed_page_count' => count($failedPageUrls),
                'product_count' => count($categoryProductUrls),
                'product_urls' => array_keys($categoryProductUrls),
            ];
        }

        $uniqueProductUrls = array_keys($productUrls);
        sort($uniqueProductUrls);

        return [
            'source' => 'antar',
            'source_categories' => array_values($categories),
            'category_results' => $categoryResults,
            'product_urls' => $uniqueProductUrls,
            'products' => array_values(array_map(
                fn (string $url): array => [
                    'source' => 'antar',
                    'url' => $url,
                    'external_id' => $this->externalProductIdFromUrl($url),
                    'slug' => $this->productSlugFromUrl($url),
                ],
                $uniqueProductUrls,
            )),
            'visited_urls' => array_keys($visitedUrls),
            'failed_urls' => $failedUrls,
        ];
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

        foreach ($value as $key => $reason) {
            if (! is_string($key) || ! is_scalar($reason)) {
                continue;
            }

            $map[$key] = (string) $reason;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function extractProductUrls(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $urls = [];

        foreach (self::PRODUCT_LINK_SELECTORS as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                $url = $this->normalizeProductUrl((string) $node->attr('href'), $baseUrl);

                if ($url !== null) {
                    $urls[$url] = true;
                }
            });
        }

        return array_keys($urls);
    }

    private function extractNextPageUrl(string $html, string $baseUrl): ?string
    {
        $crawler = new Crawler($html, $baseUrl);

        foreach (self::NEXT_PAGE_SELECTORS as $selector) {
            $next = null;

            $crawler->filter($selector)->each(function (Crawler $node) use (&$next, $baseUrl): void {
                if ($next !== null) {
                    return;
                }

                $next = $this->normalizePageUrl((string) $node->attr('href'), $baseUrl);
            });

            if ($next !== null) {
                return $next;
            }
        }

        return null;
    }

    private function normalizeCategoryUrl(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url, AntarCategoryUrlScraper::DEFAULT_URL, keepQuery: false);

        if ($normalized === null || ! $this->isCategoryUrl($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeProductUrl(string $url, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, keepQuery: false);

        if ($normalized === null || ! $this->isProductUrl($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizePageUrl(string $url, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, keepQuery: true);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        return str_starts_with($path, '/produkty/') || $path === '/produkty/' ? $normalized : null;
    }

    private function normalizeUrl(string $url, string $baseUrl, bool $keepQuery = false): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || $url === '#' || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $parts = parse_url($baseUrl);
            $host = (string) ($parts['host'] ?? self::ANTAR_HOST);
            $url = 'https://'.$host.$url;
        } elseif (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $directory = rtrim(str_ends_with($basePath, '/') ? $basePath : dirname($basePath), '/');
            $url = 'https://'.(string) ($baseParts['host'] ?? self::ANTAR_HOST).$directory.'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = '';

        if ($keepQuery && isset($parts['query'])) {
            parse_str((string) $parts['query'], $queryParts);

            if (isset($queryParts['product-page']) && is_scalar($queryParts['product-page'])) {
                $page = max(1, (int) $queryParts['product-page']);
                $query = '?product-page='.$page;
            }
        }

        return 'https://'.$host.$path.$query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function isProductUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::ANTAR_HOST) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        return count($segments) === 2 && $segments[0] === 'produkt' && $segments[1] !== '';
    }

    private function isCategoryUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::ANTAR_HOST) {
            return false;
        }

        $segments = $this->categoryPathSegments($url);

        return $segments !== [];
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host === self::ANTAR_HOST ? self::ANTAR_HOST : null;
    }

    /**
     * @return array<int, string>
     */
    private function categoryPathSegments(string $url): array
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        if ($segments === [] || $segments[0] !== 'produkty') {
            return [];
        }

        array_shift($segments);

        return array_values(array_map(static fn (string $segment): string => Str::slug($segment), $segments));
    }

    private function categoryExternalIdFromUrl(string $url): string
    {
        return implode('/', $this->categoryPathSegments($url));
    }

    private function categoryNameFromUrl(string $url): string
    {
        $segments = $this->categoryPathSegments($url);
        $last = (string) end($segments);

        return Str::of($last)->replace('-', ' ')->ucfirst()->toString();
    }

    private function productSlugFromUrl(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        return (string) end($segments);
    }

    private function externalProductIdFromUrl(string $url): string
    {
        return $this->productSlugFromUrl($url);
    }


    private function fetch(string $url): Response
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $this->pauseBeforeRequest();

                $response = Http::withHeaders($this->headers())
                    ->connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->get($url);

                if ($this->shouldRetryResponse($response) && $attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();

                    continue;
                }

                return $response;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt >= $this->maxAttempts) {
                    throw $exception;
                }

                $this->pauseBeforeRetry();
            }
        }

        throw $lastException ?? new \RuntimeException('Failed to fetch Antar URL: '.$url);
    }

    private function shouldRetryResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->retryDelayMilliseconds * 1000);
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopAntarScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
