<?php

declare(strict_types=1);

namespace App\Services\Novicare;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class NovicareProductUrlScraper
{
    private const CANONICAL_HOST = 'novicare.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 2000;

    private int $requestDelayMilliseconds = 0;

    private bool $verifyTls = true;

    public function __construct(
        private readonly NovicareCategoryUrlScraper $categoryScraper,
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

    public function withAttempts(int $attempts): self
    {
        $this->attempts = max(1, $attempts);
        $this->categoryScraper->withAttempts($this->attempts);

        return $this;
    }

    public function withRetryDelayMilliseconds(int $milliseconds): self
    {
        $this->retryDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRetryDelayMilliseconds($this->retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->verifyTls = $verify;
        $this->categoryScraper->withTlsVerification($verify);

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [NovicareCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(
        array $startUrls = [NovicareCategoryUrlScraper::DEFAULT_URL],
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

            if (($category['is_product_category'] ?? null) !== true && ! isset($productCategoryUrls[$url])) {
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
                'Scraping Novicare category %d/%d: %s',
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
                if ($pageLimit !== null && count($categoryPageUrls) >= max(1, $pageLimit)) {
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

                $pageProducts = $this->extractProducts($html, $pageUrl, $category);
                $this->emit(sprintf('  Page %d product links: %d', $pageNumber, count($pageProducts)));

                foreach ($pageProducts as $product) {
                    $productUrl = (string) $product['url'];
                    $categoryProducts[$productUrl] = true;

                    if (! isset($products[$productUrl])) {
                        $products[$productUrl] = $product + [
                            'source' => 'novicare',
                            'category_urls' => [$categoryUrl],
                            'category_paths' => [$category['path']],
                        ];

                        continue;
                    }

                    if ($products[$productUrl]['name'] === '' && $product['name'] !== '') {
                        $products[$productUrl]['name'] = $product['name'];
                    }

                    if ($products[$productUrl]['product_code'] === null && $product['product_code'] !== null) {
                        $products[$productUrl]['product_code'] = $product['product_code'];
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
                'source' => 'novicare',
                'external_category_id' => (string) $category['external_category_id'],
                'name' => (string) $category['name'],
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
            'source' => 'novicare',
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
     * @param  array<string, mixed>  $category
     * @return array<int, array<string, mixed>>
     */
    private function extractProducts(string $html, string $baseUrl, array $category): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $products = [];
        $expectedCategorySlug = (string) $category['slug'];

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$products, $baseUrl, $expectedCategorySlug): void {
            $node = $link->getNode(0);

            if (! $node instanceof DOMElement) {
                return;
            }

            $url = $this->normalizeUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null) {
                return;
            }

            $identity = $this->productIdentityFromUrl($url);

            if ($identity === null || $identity['category_slug'] !== $expectedCategorySlug || isset($products[$url])) {
                return;
            }

            $name = $this->normalizeText($link->text(''));

            if ($name === '') {
                $name = $this->titleFromProductCard($node);
            }

            if ($name === '') {
                $name = $this->humanizeSlug($identity['slug']);
            }

            $products[$url] = [
                'external_id' => hash('sha256', $url),
                'slug' => $identity['slug'],
                'category_slug' => $identity['category_slug'],
                'product_code' => $this->productCodeFromName($name),
                'name' => $name,
                'url' => $url,
                'source_url' => $url,
                'canonical_url' => $url,
            ];
        });

        return array_values($products);
    }

    /**
     * @return array<int, string>
     */
    private function extractPaginationUrls(string $html, string $baseUrl, string $categoryUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $urls = [];

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$urls, $baseUrl, $categoryUrl): void {
            $node = $link->getNode(0);

            if (! $node instanceof DOMElement) {
                return;
            }

            $candidate = $this->normalizeUrl($node->getAttribute('href'), $baseUrl, true);

            if ($candidate === null || $candidate === $categoryUrl || ! $this->isPaginationUrlForCategory($candidate, $categoryUrl)) {
                return;
            }

            $urls[$candidate] = true;
        });

        return array_keys($urls);
    }

    private function titleFromProductCard(DOMElement $link): string
    {
        $ancestor = $link->parentNode;
        $depth = 0;

        while ($ancestor instanceof DOMElement && $depth < 4) {
            $crawler = new Crawler($ancestor);
            $headings = $crawler->filter('h1, h2, h3, h4, h5, h6, .kt-blocks-info-box-title');

            if ($headings->count() > 0) {
                $name = $this->normalizeText($headings->first()->text(''));

                if ($name !== '') {
                    return $name;
                }
            }

            $ancestor = $ancestor->parentNode;
            $depth++;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function categoryRecord(string $url, array $metadata = []): array
    {
        $slug = $this->categorySlugFromUrl($url) ?? 'unknown';
        $name = $this->normalizeText((string) ($metadata['name'] ?? ''));
        $path = $metadata['path'] ?? null;

        if (! is_array($path) || $path === []) {
            $path = [$name !== '' ? $name : $this->humanizeSlug($slug)];
        }

        $path = array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $path),
            static fn (string $value): bool => $value !== '',
        ));

        if ($path === []) {
            $path = [$this->humanizeSlug($slug)];
        }

        return [
            'source' => 'novicare',
            'external_category_id' => (string) ($metadata['external_category_id'] ?? $slug),
            'slug' => (string) ($metadata['slug'] ?? $slug),
            'name' => $name !== '' ? $name : end($path),
            'url' => $url,
            'path' => $path,
            'level' => (int) ($metadata['level'] ?? count($path)),
            'parent_external_category_id' => isset($metadata['parent_external_category_id'])
                ? (string) $metadata['parent_external_category_id']
                : null,
            'top_category_external_id' => (string) ($metadata['top_category_external_id'] ?? $slug),
            'top_category_name' => (string) ($metadata['top_category_name'] ?? $path[0]),
            'has_children' => false,
            'is_product_category' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function categoryPathLabel(array $category): string
    {
        if (is_array($category['path'] ?? null) && $category['path'] !== []) {
            return implode(' > ', $category['path']);
        }

        return (string) ($category['name'] ?? $category['url'] ?? 'Unknown category');
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $lastFailure = 'Unknown HTTP failure';

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withOptions(['verify' => $this->verifyTls])
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->emit(sprintf(
                        '  Novicare request failed on attempt %d/%d: %s',
                        $attempt,
                        $this->attempts,
                        $lastFailure,
                    ));
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->successful()) {
                unset($failed[$url]);

                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->attempts) {
                $this->emit(sprintf(
                    '  Novicare request returned %s on attempt %d/%d.',
                    $lastFailure,
                    $attempt,
                    $this->attempts,
                ));
                $this->pauseBeforeRetry();
            }
        }

        $failed[$url] = $lastFailure;

        return null;
    }

    private function normalizeCategoryUrl(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url, NovicareCategoryUrlScraper::DEFAULT_URL);

        return $normalized !== null && $this->categorySlugFromUrl($normalized) !== null
            ? $normalized
            : null;
    }

    private function categorySlugFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/produkty/([^/]+)/$#u', $path, $matches) !== 1) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    /**
     * @return array{category_slug: string, slug: string}|null
     */
    private function productIdentityFromUrl(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/produkty/([^/]+)/([^/]+)/$#u', $path, $matches) !== 1) {
            return null;
        }

        return [
            'category_slug' => rawurldecode($matches[1]),
            'slug' => rawurldecode($matches[2]),
        ];
    }

    private function isPaginationUrlForCategory(string $url, string $categoryUrl): bool
    {
        $categoryPath = rtrim((string) parse_url($categoryUrl, PHP_URL_PATH), '/');
        $candidatePath = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if (preg_match('#^'.preg_quote($categoryPath, '#').'/page/[1-9][0-9]*$#', $candidatePath) === 1) {
            return true;
        }

        if ($candidatePath !== $categoryPath || $query === '') {
            return false;
        }

        parse_str($query, $parameters);

        return isset($parameters['paged']) && is_numeric($parameters['paged']) && (int) $parameters['paged'] > 1;
    }

    private function normalizeUrl(string $candidate, string $baseUrl, bool $preservePaginationQuery = false): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with(mb_strtolower($candidate), 'javascript:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $basePath = str_replace('\\', '/', (string) parse_url($baseUrl, PHP_URL_PATH));
            $baseDirectory = str_ends_with($basePath, '/')
                ? rtrim($basePath, '/')
                : rtrim(dirname($basePath), '/');
            $candidate = 'https://'.self::CANONICAL_HOST.($baseDirectory !== '' ? $baseDirectory.'/' : '/').$candidate;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, [self::CANONICAL_HOST, 'www.'.self::CANONICAL_HOST], true)) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));

        if ($path !== '/' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        $query = '';

        if ($preservePaginationQuery && is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $parameters);

            if (isset($parameters['paged']) && is_numeric($parameters['paged']) && (int) $parameters['paged'] > 1) {
                $query = '?paged='.(int) $parameters['paged'];
            }
        }

        return 'https://'.self::CANONICAL_HOST.$path.$query;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = rawurlencode(rawurldecode($segment));
        }

        return '/'.implode('/', $segments);
    }

    private function productCodeFromName(string $name): ?string
    {
        if (preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)*)\b/u', trim($name), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function humanizeSlug(string $slug): string
    {
        $value = str_replace(['-', '_'], ' ', rawurldecode($slug));

        return mb_convert_case($this->normalizeText($value), MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key) || ! is_scalar($entry)) {
                continue;
            }

            $map[$key] = (string) $entry;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopNovicareProductUrlCrawler/1.0)',
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
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
