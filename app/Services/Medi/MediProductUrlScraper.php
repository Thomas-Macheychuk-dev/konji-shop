<?php

declare(strict_types=1);

namespace App\Services\Medi;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MediProductUrlScraper
{
    private const MEDI_HOST = 'www.medi-polska.pl';

    private const CATEGORY_PATH_PREFIX = '/shop/kategoria-produktu/';

    /**
     * @var array<int, string>
     */
    private const APPROVED_ROOT_CATEGORIES = [
        'kompresja',
        'ortopedia',
        'akcesoria',
    ];

    /**
     * This medi category currently redirects back to itself until the HTTP client
     * reaches its redirect limit. Its products are already exposed by the parent
     * Rajstopy uciskowe category, so skipping it avoids a permanent false failure.
     *
     * @var array<string, string>
     */
    private const KNOWN_UNCRAWLABLE_CATEGORY_PATHS = [
        '/shop/kategoria-produktu/kompresja/rajstopy-uciskowe-meskie.html' => 'Known medi redirect loop; products are covered by the parent category.',
    ];

    /**
     * Magento category pages render the canonical product detail link in both the
     * product image and product name. Restricting discovery to product-list wrappers
     * prevents recommendations and navigation links from entering the crawl queue.
     *
     * @var array<int, string>
     */
    private const PRODUCT_LINK_SELECTORS = [
        '.products.wrapper.grid.products-grid a.product-item-link[href]',
        '.products-grid .product-item-info a.product-item-link[href]',
        'ol.products.list.items.product-items a.product-item-link[href]',
        'li.product-item a.product-item-link[href]',
        '.products-grid .product-item-info a.product.photo.product-item-photo[href]',
    ];

    /**
     * @var array<int, string>
     */
    private const NEXT_PAGE_SELECTORS = [
        'li.item.pages-item-next a.action.next[href]',
        '.pages-items .pages-item-next a[href]',
        '.toolbar-products .pages a.action.next[href]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 0;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    public function __construct(
        private readonly MediCategoryUrlScraper $categoryScraper,
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
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [MediCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(array $startUrls = [MediCategoryUrlScraper::DEFAULT_URL], ?int $pageLimit = null, ?int $categoryLimit = null): array
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
                'source' => 'medi',
                'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($url)),
                'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($url)),
                'url' => $url,
                'path' => is_array($category['path'] ?? null) ? array_values($category['path']) : [$this->categoryNameFromUrl($url)],
                'level' => (int) ($category['level'] ?? count($this->categoryPathSegments($url))),
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
                'source' => 'medi',
                'external_category_id' => $this->categoryExternalIdFromUrl($normalized),
                'name' => $this->categoryNameFromUrl($normalized),
                'url' => $normalized,
                'path' => [$this->categoryNameFromUrl($normalized)],
                'level' => count($this->categoryPathSegments($normalized)),
            ];
        }

        return $this->scrapeCategoryRecords(
            array_values($categories),
            $pageLimit,
            $categoryLimit,
            $this->actionableFailedUrls($discovery['failed_urls'] ?? []),
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
                'source' => 'medi',
                'external_category_id' => $this->categoryExternalIdFromUrl($normalized),
                'name' => $this->categoryNameFromUrl($normalized),
                'url' => $normalized,
                'path' => [$this->categoryNameFromUrl($normalized)],
                'level' => count($this->categoryPathSegments($normalized)),
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
        $skippedCategories = [];

        foreach ($categories as $index => $category) {
            $categoryUrl = (string) ($category['url'] ?? '');

            if ($categoryUrl === '') {
                continue;
            }

            $skipReason = $this->knownCategorySkipReason($categoryUrl);

            if ($skipReason !== null) {
                $this->emit(sprintf(
                    'Skipping medi category %d/%d: %s (%s)',
                    $index + 1,
                    count($categories),
                    (string) ($category['name'] ?? $categoryUrl),
                    $skipReason,
                ));

                $skippedCategories[] = [
                    'source' => 'medi',
                    'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($categoryUrl)),
                    'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($categoryUrl)),
                    'url' => $categoryUrl,
                    'reason' => $skipReason,
                ];

                $categoryResults[] = $this->categoryResult(
                    $category,
                    pageUrls: [],
                    failedPageUrls: [],
                    productUrls: [],
                    skipped: true,
                    skipReason: $skipReason,
                );

                unset($failedUrls[$categoryUrl]);

                continue;
            }

            $this->emit(sprintf(
                'Scraping medi category %d/%d: %s',
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
                        $reason = 'HTTP '.$response->status();
                        $failedUrls[$nextPageUrl] = $reason;
                        $failedPageUrls[$nextPageUrl] = $reason;

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

            $categoryResults[] = $this->categoryResult(
                $category,
                $categoryPageUrls,
                $failedPageUrls,
                array_keys($categoryProductUrls),
            );
        }

        $uniqueProductUrls = array_keys($productUrls);
        sort($uniqueProductUrls);

        return [
            'source' => 'medi',
            'source_categories' => array_values($categories),
            'category_results' => $categoryResults,
            'skipped_categories' => $skippedCategories,
            'product_urls' => $uniqueProductUrls,
            'products' => array_values(array_map(
                fn (string $url): array => [
                    'source' => 'medi',
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
     * @param  array<string, mixed>  $category
     * @param  array<int, string>  $pageUrls
     * @param  array<string, string>  $failedPageUrls
     * @param  array<int, string>  $productUrls
     * @return array<string, mixed>
     */
    private function categoryResult(
        array $category,
        array $pageUrls,
        array $failedPageUrls,
        array $productUrls,
        bool $skipped = false,
        ?string $skipReason = null,
    ): array {
        $categoryUrl = (string) ($category['url'] ?? '');

        return [
            'category' => $category,
            'source' => 'medi',
            'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($categoryUrl)),
            'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($categoryUrl)),
            'url' => $categoryUrl,
            'category_path' => is_array($category['path'] ?? null) ? array_values($category['path']) : [$this->categoryNameFromUrl($categoryUrl)],
            'page_urls' => $pageUrls,
            'pages_scraped' => count($pageUrls),
            'failed_page_urls' => $failedPageUrls,
            'failed_page_count' => count($failedPageUrls),
            'product_count' => count($productUrls),
            'product_urls' => $productUrls,
            'skipped' => $skipped,
            'skip_reason' => $skipReason,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    private function actionableFailedUrls(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $failedUrls = [];

        foreach ($value as $url => $reason) {
            if (! is_string($url) || ! is_scalar($reason)) {
                continue;
            }

            $normalized = $this->normalizeCategoryUrl($url);

            if ($normalized !== null && $this->knownCategorySkipReason($normalized) !== null) {
                continue;
            }

            $failedUrls[$url] = (string) $reason;
        }

        return $failedUrls;
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
        $normalized = $this->normalizeUrl($url, MediCategoryUrlScraper::DEFAULT_URL, keepPageQuery: false);

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

    private function normalizePageUrl(string $url, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, keepPageQuery: true);

        if ($normalized === null || ! $this->isCategoryUrl($normalized)) {
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
        } elseif (str_starts_with($url, '?')) {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $url = 'https://'.self::MEDI_HOST.$basePath.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::MEDI_HOST.$url;
        } elseif (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $baseDirectory = str_ends_with($basePath, '/') ? rtrim($basePath, '/') : dirname($basePath);
            $url = 'https://'.self::MEDI_HOST.'/'.trim($baseDirectory.'/'.$url, '/');
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

            if (isset($queryParts['p']) && is_scalar($queryParts['p'])) {
                $query = '?p='.max(1, (int) $queryParts['p']);
            }
        }

        return 'https://'.$host.$path.$query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return preg_replace('#/+#', '/', $path) ?: $path;
    }

    private function isProductUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::MEDI_HOST) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        return count($segments) === 2
            && $segments[0] === 'shop'
            && str_ends_with($segments[1], '.html')
            && $segments[1] !== '.html';
    }

    private function isCategoryUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::MEDI_HOST) {
            return false;
        }

        $segments = $this->categoryPathSegments($url);

        return $segments !== [] && in_array($segments[0], self::APPROVED_ROOT_CATEGORIES, true);
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host === 'medi-polska.pl' ? self::MEDI_HOST : null;
    }

    /**
     * @return array<int, string>
     */
    private function categoryPathSegments(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! str_starts_with($path, self::CATEGORY_PATH_PREFIX)) {
            return [];
        }

        $relativePath = substr($path, strlen(self::CATEGORY_PATH_PREFIX));
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/'))));

        if ($segments === []) {
            return [];
        }

        $lastIndex = array_key_last($segments);
        $lastSegment = $segments[$lastIndex];

        if (! str_ends_with($lastSegment, '.html')) {
            return [];
        }

        $segments[$lastIndex] = substr($lastSegment, 0, -5);

        return array_values(array_map(
            static fn (string $segment): string => Str::slug($segment),
            $segments,
        ));
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
        $filename = basename((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($filename, '.html') ? substr($filename, 0, -5) : $filename;
    }

    private function externalProductIdFromUrl(string $url): string
    {
        return $this->productSlugFromUrl($url);
    }

    private function knownCategorySkipReason(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return self::KNOWN_UNCRAWLABLE_CATEGORY_PATHS[$path] ?? null;
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

        throw $lastException ?? new RuntimeException('Failed to fetch medi URL: '.$url);
    }

    private function shouldRetryResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMediScraper/1.0; +https://konji.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
