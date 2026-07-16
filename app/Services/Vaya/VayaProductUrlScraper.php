<?php

declare(strict_types=1);

namespace App\Services\Vaya;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class VayaProductUrlScraper
{
    private const VAYA_HOST = 'www.vaya.com.pl';

    /**
     * Vaya uses Shoper product cards. Stable product detail URLs use the
     * `/pl/p/{slug}/{id}` shape.
     */
    private const PRODUCT_LINK_SELECTORS = [
        '.products .product-main-wrap a.prodname[href]',
        '.products .product-main-wrap a.prodimage[href]',
        '.products [data-product-id] a[href*="/pl/p/"]',
        '.products a[href*="/pl/p/"]',
    ];

    /**
     * Shoper pagination renders numeric links inside `.paginator`. Category page
     * numbers are appended to the category URL, for example `/produkty-scholl/2`.
     */
    private const PAGINATION_LINK_SELECTORS = [
        'link[rel="next"][href]',
        '.paginator a[href]',
        'ul.paginator a[href]',
        '.pagination a[href]',
        'a[rel="next"][href]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 0;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    public function __construct(
        private readonly VayaCategoryUrlScraper $categoryScraper,
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

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [VayaCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(
        array $startUrls = [VayaCategoryUrlScraper::DEFAULT_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
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
    public function scrapeFromDiscoveredCategories(
        array $discovery,
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $productCategoryUrls = [];

        foreach (($discovery['product_category_urls'] ?? []) as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = $this->normalizeCategoryUrl($url);

            if ($normalized !== null) {
                $productCategoryUrls[$normalized] = true;
            }
        }

        $categories = [];

        foreach (($discovery['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeCategoryUrl((string) ($category['url'] ?? ''));

            if ($url === null) {
                continue;
            }

            $isProductCategory = ($category['is_product_category'] ?? null) === true
                || isset($productCategoryUrls[$url]);

            if (! $isProductCategory) {
                continue;
            }

            $categories[$url] = $this->categoryRecord($url, $category);
        }

        foreach (array_keys($productCategoryUrls) as $url) {
            if (! isset($categories[$url])) {
                $categories[$url] = $this->categoryRecord($url);
            }
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
    public function scrapeCategories(
        array $categoryUrls,
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $categories = [];

        foreach ($categoryUrls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = $this->normalizeCategoryUrl($url);

            if ($normalized !== null) {
                $categories[$normalized] = $this->categoryRecord($normalized);
            }
        }

        return $this->scrapeCategoryRecords(array_values($categories), $pageLimit, $categoryLimit);
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string, string>  $initialFailedUrls
     * @return array<string, mixed>
     */
    private function scrapeCategoryRecords(
        array $categories,
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
        array $initialFailedUrls = [],
    ): array {
        if ($categoryLimit !== null) {
            $categories = array_slice($categories, 0, max(1, $categoryLimit));
        }

        $products = [];
        $categoryResults = [];
        $visitedUrls = [];
        $failedUrls = $initialFailedUrls;

        foreach ($categories as $index => $category) {
            $categoryUrl = (string) ($category['url'] ?? '');

            if ($categoryUrl === '') {
                continue;
            }

            $this->emit(sprintf(
                'Scraping Vaya category %d/%d: %s',
                $index + 1,
                count($categories),
                $this->categoryPathLabel($category),
            ));
            $this->emit('  '.$categoryUrl);

            $categoryProducts = [];
            $categoryPageUrls = [];
            $failedPageUrls = [];
            $queuedUrls = [$categoryUrl => true];
            $queue = [$categoryUrl];

            while ($queue !== []) {
                if ($pageLimit !== null && count($categoryPageUrls) >= $pageLimit) {
                    break;
                }

                $pageUrl = array_shift($queue);

                if (! is_string($pageUrl) || isset($categoryPageUrls[$pageUrl])) {
                    continue;
                }

                $categoryPageUrls[$pageUrl] = true;
                $visitedUrls[$pageUrl] = true;
                $pageNumber = count($categoryPageUrls);

                $this->emit(sprintf('  Fetching page %d: %s', $pageNumber, $pageUrl));

                $html = $this->fetchBody($pageUrl, $failedUrls);

                if ($html === null) {
                    $failedPageUrls[$pageUrl] = $failedUrls[$pageUrl] ?? 'Unknown HTTP failure';

                    continue;
                }

                $pageProducts = $this->extractProducts($html, $pageUrl);
                $this->emit(sprintf('  Page %d product links: %d', $pageNumber, count($pageProducts)));

                foreach ($pageProducts as $product) {
                    $productUrl = (string) $product['url'];
                    $categoryProducts[$productUrl] = true;

                    if (! isset($products[$productUrl])) {
                        $products[$productUrl] = $product + [
                            'source' => 'vaya',
                            'category_urls' => [$categoryUrl],
                            'category_paths' => [$category['path']],
                        ];

                        continue;
                    }

                    if ($products[$productUrl]['name'] === '' && $product['name'] !== '') {
                        $products[$productUrl]['name'] = $product['name'];
                    }

                    if (! in_array($categoryUrl, $products[$productUrl]['category_urls'], true)) {
                        $products[$productUrl]['category_urls'][] = $categoryUrl;
                    }

                    if (! in_array($category['path'], $products[$productUrl]['category_paths'], true)) {
                        $products[$productUrl]['category_paths'][] = $category['path'];
                    }
                }

                foreach ($this->extractPaginationUrls($html, $pageUrl, $categoryUrl) as $paginationUrl) {
                    if (isset($queuedUrls[$paginationUrl]) || isset($categoryPageUrls[$paginationUrl])) {
                        continue;
                    }

                    $queuedUrls[$paginationUrl] = true;
                    $queue[] = $paginationUrl;
                }
            }

            $categoryResults[] = [
                'category' => $category,
                'source' => 'vaya',
                'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($categoryUrl)),
                'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($categoryUrl)),
                'url' => $categoryUrl,
                'category_path' => $category['path'],
                'page_urls' => array_keys($categoryPageUrls),
                'pages_scraped' => count($categoryPageUrls),
                'failed_page_urls' => $failedPageUrls,
                'failed_page_count' => count($failedPageUrls),
                'product_count' => count($categoryProducts),
                'product_urls' => array_keys($categoryProducts),
            ];
        }

        $productRecords = array_values($products);
        usort(
            $productRecords,
            static fn (array $left, array $right): int => strcmp((string) $left['url'], (string) $right['url'])
        );

        return [
            'source' => 'vaya',
            'source_categories' => array_values($categories),
            'category_results' => $categoryResults,
            'products' => $productRecords,
            'product_urls' => array_values(array_map(
                static fn (array $product): string => (string) $product['url'],
                $productRecords,
            )),
            'visited_urls' => array_keys($visitedUrls),
            'failed_urls' => $failedUrls,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function categoryRecord(string $url, array $metadata = []): array
    {
        $name = trim((string) ($metadata['name'] ?? ''));
        $path = $metadata['path'] ?? null;

        if (! is_array($path) || $path === []) {
            $path = [$name !== '' ? $name : $this->categoryNameFromUrl($url)];
        }

        $path = array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $path),
            static fn (string $value): bool => $value !== '',
        ));

        if ($path === []) {
            $path = [$this->categoryNameFromUrl($url)];
        }

        return [
            'source' => 'vaya',
            'external_category_id' => (string) ($metadata['external_category_id'] ?? $this->categoryExternalIdFromUrl($url)),
            'name' => $name !== '' ? $name : end($path),
            'url' => $url,
            'path' => $path,
            'level' => (int) ($metadata['level'] ?? count($path)),
            'parent_external_category_id' => isset($metadata['parent_external_category_id'])
                ? (string) $metadata['parent_external_category_id']
                : null,
            'top_category_external_id' => isset($metadata['top_category_external_id'])
                ? (string) $metadata['top_category_external_id']
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractProducts(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $products = [];

        foreach (self::PRODUCT_LINK_SELECTORS as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$products, $baseUrl): void {
                $url = $this->normalizeProductUrl((string) $node->attr('href'), $baseUrl);

                if ($url === null) {
                    return;
                }

                $name = $this->cleanText((string) ($node->attr('title') ?? ''));

                if ($name === '') {
                    $name = $this->cleanText($node->text('', true));
                }

                if (! isset($products[$url])) {
                    $products[$url] = [
                        'url' => $url,
                        'external_id' => $this->externalProductIdFromUrl($url),
                        'slug' => $this->productSlugFromUrl($url),
                        'name' => $name,
                    ];
                } elseif ($products[$url]['name'] === '' && $name !== '') {
                    $products[$url]['name'] = $name;
                }
            });
        }

        return array_values($products);
    }

    /**
     * @return array<int, string>
     */
    private function extractPaginationUrls(string $html, string $baseUrl, string $categoryUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $urls = [];

        foreach (self::PAGINATION_LINK_SELECTORS as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$urls, $baseUrl, $categoryUrl): void {
                $url = $this->normalizePaginationUrl((string) $node->attr('href'), $baseUrl, $categoryUrl);

                if ($url !== null) {
                    $urls[$url] = true;
                }
            });
        }

        return array_keys($urls);
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $lastReason = 'Unknown HTTP failure';

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastReason = $exception->getMessage();

                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();
                    continue;
                }

                break;
            }

            if ($response->successful()) {
                unset($failed[$url]);

                return $response->body();
            }

            $lastReason = 'HTTP '.$response->status();

            if ($attempt < $this->maxAttempts && $this->shouldRetry($response)) {
                $this->pauseBeforeRetry();
                continue;
            }

            break;
        }

        $failed[$url] = $lastReason;

        return null;
    }

    private function shouldRetry(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function normalizeCategoryUrl(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url, VayaCategoryUrlScraper::DEFAULT_URL, keepPageQuery: false);

        if ($normalized === null || ! $this->isCategoryUrl($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeProductUrl(string $url, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, keepPageQuery: false);

        if ($normalized === null || ! $this->isProductUrl($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizePaginationUrl(string $url, string $baseUrl, string $categoryUrl): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, keepPageQuery: true);

        if ($normalized === null || $normalized === $categoryUrl) {
            return null;
        }

        $categoryParts = parse_url($categoryUrl);
        $candidateParts = parse_url($normalized);

        if (! is_array($categoryParts) || ! is_array($candidateParts)) {
            return null;
        }

        if (($candidateParts['host'] ?? null) !== ($categoryParts['host'] ?? null)) {
            return null;
        }

        $categoryPath = rtrim((string) ($categoryParts['path'] ?? '/'), '/');
        $candidatePath = rtrim((string) ($candidateParts['path'] ?? '/'), '/');

        if ($candidatePath === $categoryPath) {
            return isset($candidateParts['query']) ? $normalized : null;
        }

        $suffix = substr($candidatePath, strlen($categoryPath));

        if (! str_starts_with($candidatePath, $categoryPath.'/') || preg_match('#^/([2-9]|[1-9][0-9]+)$#', $suffix) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizeUrl(string $url, string $baseUrl, bool $keepPageQuery): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || $url === '#' || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::VAYA_HOST.$url;
        } elseif (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $directory = rtrim(str_ends_with($basePath, '/') ? $basePath : dirname($basePath), '/');
            $url = 'https://'.self::VAYA_HOST.$directory.'/'.ltrim($url, '/');
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

        if ($keepPageQuery && isset($parts['query'])) {
            parse_str((string) $parts['query'], $queryParts);

            foreach (['page', 'p'] as $key) {
                if (! isset($queryParts[$key]) || ! is_scalar($queryParts[$key])) {
                    continue;
                }

                $page = (int) $queryParts[$key];

                if ($page > 1) {
                    $query = '?'.$key.'='.$page;
                }

                break;
            }
        }

        return 'https://'.self::VAYA_HOST.$path.$query;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host === 'vaya.com.pl' ? self::VAYA_HOST : null;
    }

    private function normalizePath(string $path): string
    {
        $path = rawurldecode('/'.ltrim($path, '/'));
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);

        return $encoded === [] ? '/' : '/'.implode('/', $encoded);
    }

    private function isProductUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::VAYA_HOST) {
            return false;
        }

        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        return preg_match('#^/pl/p/[^/]+/[0-9]+$#u', $path) === 1;
    }

    private function isCategoryUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::VAYA_HOST) {
            return false;
        }

        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if ($path === '/' || str_starts_with($path, '/pl/p/')) {
            return false;
        }

        if (preg_match('#^/pl/c/[^/]+/[0-9]+$#u', $path) === 1) {
            return true;
        }

        return preg_match('#^/[A-Za-z0-9%._,\-]+$#u', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    private function externalProductIdFromUrl(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        return (string) end($segments);
    }

    private function productSlugFromUrl(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/'))));

        return count($segments) >= 2 ? (string) $segments[count($segments) - 2] : '';
    }

    private function categoryExternalIdFromUrl(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('#^/pl/c/[^/]+/([0-9]+)$#u', $path, $matches) === 1) {
            return $matches[1];
        }

        return trim($path, '/');
    }

    private function categoryNameFromUrl(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/'))));

        if ($segments === []) {
            return $url;
        }

        $name = count($segments) >= 4 && $segments[0] === 'pl' && $segments[1] === 'c'
            ? $segments[count($segments) - 2]
            : end($segments);

        return $this->cleanText(str_replace(['-', '_'], ' ', (string) $name));
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function categoryPathLabel(array $category): string
    {
        $path = $category['path'] ?? null;

        if (is_array($path) && $path !== []) {
            return implode(' > ', array_map(static fn (mixed $value): string => (string) $value, $path));
        }

        return (string) ($category['name'] ?? $category['url'] ?? 'Unknown category');
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

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopVayaScraper/1.0; +https://konji.pl)',
        ];
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
