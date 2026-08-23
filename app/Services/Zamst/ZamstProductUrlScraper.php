<?php

declare(strict_types=1);

namespace App\Services\Zamst;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ZamstProductUrlScraper
{
    private const CANONICAL_HOST = 'zamst.com.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 2000;

    private int $requestDelayMilliseconds = 500;

    private bool $verifyTls = true;

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    public function withAttempts(int $attempts): self
    {
        $this->attempts = max(1, $attempts);

        return $this;
    }

    public function withRetryDelayMilliseconds(int $milliseconds): self
    {
        $this->retryDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->verifyTls = $verify;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $categoryDiscovery
     * @return array<string, mixed>
     */
    public function scrape(
        ?array $categoryDiscovery = null,
        ?int $categoryLimit = null,
        ?int $pageLimit = null,
    ): array {
        $failedUrls = [];
        $visitedUrls = [];
        $products = [];
        $cataloguePages = $this->crawlCataloguePages(
            ZamstCategoryUrlScraper::DEFAULT_URL,
            $products,
            $visitedUrls,
            $failedUrls,
            $pageLimit,
        );

        $categories = $this->categoryRecords($categoryDiscovery);

        if ($categoryLimit !== null) {
            $categories = array_slice($categories, 0, max(1, $categoryLimit));
        }

        $categoryResults = [];

        foreach ($categories as $index => $category) {
            $categoryUrl = (string) $category['url'];
            $this->emit(sprintf(
                'Scraping Zamst category %d/%d: %s',
                $index + 1,
                count($categories),
                implode(' > ', $category['path']),
            ));

            $categoryProductUrls = [];
            $categoryPageUrls = [];
            $queue = [$categoryUrl];
            $queued = [$categoryUrl => true];

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
                $this->emit('  Fetching '.$pageUrl);
                $html = $this->fetchBody($pageUrl, $failedUrls);

                if ($html === null) {
                    continue;
                }

                foreach ($this->extractProductCards($html, $pageUrl) as $product) {
                    $productUrl = (string) $product['url'];
                    $categoryProductUrls[$productUrl] = true;
                    $this->mergeProduct($products, $product, $category);
                }

                foreach ($this->paginationUrls($html, $pageUrl, $categoryUrl) as $nextUrl) {
                    if (isset($queued[$nextUrl]) || isset($categoryPageUrls[$nextUrl])) {
                        continue;
                    }

                    $queued[$nextUrl] = true;
                    $queue[] = $nextUrl;
                }
            }

            $categoryResults[] = [
                'source' => 'zamst',
                'external_category_id' => $category['external_category_id'],
                'name' => $category['name'],
                'url' => $categoryUrl,
                'category_path' => $category['path'],
                'page_urls' => array_keys($categoryPageUrls),
                'pages_scraped' => count($categoryPageUrls),
                'product_count' => count($categoryProductUrls),
                'product_urls' => array_keys($categoryProductUrls),
            ];
        }

        $productRecords = array_values($products);
        usort($productRecords, static fn (array $left, array $right): int => strcmp((string) $left['url'], (string) $right['url']));

        return [
            'source' => 'zamst',
            'catalogue_url' => ZamstCategoryUrlScraper::DEFAULT_URL,
            'catalogue_pages' => $cataloguePages,
            'source_categories' => $categories,
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
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, bool>  $visitedUrls
     * @param  array<string, string>  $failedUrls
     * @return array<int, string>
     */
    private function crawlCataloguePages(
        string $startUrl,
        array &$products,
        array &$visitedUrls,
        array &$failedUrls,
        ?int $pageLimit,
    ): array {
        $queue = [$startUrl];
        $queued = [$startUrl => true];
        $pageUrls = [];

        while ($queue !== []) {
            if ($pageLimit !== null && count($pageUrls) >= max(1, $pageLimit)) {
                break;
            }

            $pageUrl = array_shift($queue);

            if (! is_string($pageUrl) || isset($pageUrls[$pageUrl])) {
                continue;
            }

            $pageUrls[$pageUrl] = true;
            $visitedUrls[$pageUrl] = true;
            $this->emit('Fetching Zamst catalogue page: '.$pageUrl);
            $html = $this->fetchBody($pageUrl, $failedUrls);

            if ($html === null) {
                continue;
            }

            $crawler = new Crawler($html, $pageUrl);
            $foundSection = false;

            foreach ($crawler->filter('ul.category-list > li') as $sectionNode) {
                if (! $sectionNode instanceof DOMElement) {
                    continue;
                }

                $section = new Crawler($sectionNode);
                $heading = $section->filter('h2 a[href*="/kategoria-produktu/"]')->first();

                if ($heading->count() === 0) {
                    continue;
                }

                $href = $heading->attr('href');
                $categoryUrl = is_string($href) ? $this->normalizeCategoryUrl($href, $pageUrl) : null;

                if ($categoryUrl === null) {
                    continue;
                }

                $foundSection = true;
                $category = $this->categoryRecordFromUrl(
                    $categoryUrl,
                    $this->normalizeText($heading->text('')),
                );

                foreach ($this->extractProductCardsFromCrawler($section, $pageUrl) as $product) {
                    $this->mergeProduct($products, $product, $category);
                }
            }

            if (! $foundSection) {
                foreach ($this->extractProductCards($html, $pageUrl) as $product) {
                    $this->mergeProduct($products, $product, null);
                }
            }

            foreach ($this->paginationUrls($html, $pageUrl, ZamstCategoryUrlScraper::DEFAULT_URL) as $nextUrl) {
                if (! $this->isCataloguePaginationUrl($nextUrl) || isset($queued[$nextUrl]) || isset($pageUrls[$nextUrl])) {
                    continue;
                }

                $queued[$nextUrl] = true;
                $queue[] = $nextUrl;
            }
        }

        return array_keys($pageUrls);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractProductCards(string $html, string $baseUrl): array
    {
        return $this->extractProductCardsFromCrawler(new Crawler($html, $baseUrl), $baseUrl);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractProductCardsFromCrawler(Crawler $crawler, string $baseUrl): array
    {
        $products = [];

        $crawler->filter('a[href*="/produkt/"]')->each(function (Crawler $link) use (&$products, $baseUrl): void {
            $node = $link->getNode(0);

            if (! $node instanceof DOMElement) {
                return;
            }

            $url = $this->normalizeProductUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null || isset($products[$url])) {
                return;
            }

            $name = '';

            foreach (['h3', 'h2', 'h4', 'h1'] as $nameSelector) {
                $name = $this->normalizeText($link->filter($nameSelector)->first()->text(''));

                if ($name !== '') {
                    break;
                }
            }

            if ($name === '') {
                $name = $this->normalizeText($link->text(''));
            }

            $slug = $this->productSlugFromUrl($url);
            $imageUrl = null;
            $image = $link->filter('img')->first();

            if ($image->count() > 0) {
                foreach (['data-lazy-src', 'data-src', 'src'] as $attribute) {
                    $candidate = $image->attr($attribute);
                    $normalized = is_string($candidate) ? $this->normalizeAssetUrl($candidate, $baseUrl) : null;

                    if ($normalized !== null && ! str_starts_with($normalized, 'data:')) {
                        $imageUrl = $normalized;
                        break;
                    }
                }
            }

            $products[$url] = [
                'source' => 'zamst',
                'external_id' => $slug,
                'slug' => $slug,
                'name' => $name !== '' ? $name : $this->humanizeSlug($slug),
                'url' => $url,
                'source_url' => $url,
                'canonical_url' => $url,
                'listing_image_url' => $imageUrl,
                'source_categories' => [],
                'category_paths' => [],
            ];
        });

        return array_values($products);
    }

    /**
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, mixed>|null  $category
     */
    private function mergeProduct(array &$products, array $product, ?array $category): void
    {
        $url = (string) $product['url'];

        if (! isset($products[$url])) {
            $products[$url] = $product;
        } else {
            if ((string) ($products[$url]['name'] ?? '') === '' && (string) ($product['name'] ?? '') !== '') {
                $products[$url]['name'] = $product['name'];
            }

            if (($products[$url]['listing_image_url'] ?? null) === null && ($product['listing_image_url'] ?? null) !== null) {
                $products[$url]['listing_image_url'] = $product['listing_image_url'];
            }
        }

        if ($category === null) {
            return;
        }

        $externalId = (string) $category['external_category_id'];
        $sourceCategories = is_array($products[$url]['source_categories'] ?? null)
            ? $products[$url]['source_categories']
            : [];

        foreach ($sourceCategories as $existing) {
            if (is_array($existing) && (string) ($existing['external_category_id'] ?? '') === $externalId) {
                return;
            }
        }

        $sourceCategories[] = $category;
        $products[$url]['source_categories'] = $sourceCategories;
        $categoryPaths = is_array($products[$url]['category_paths'] ?? null)
            ? $products[$url]['category_paths']
            : [];
        $path = $category['path'];

        if (! in_array($path, $categoryPaths, true)) {
            $categoryPaths[] = $path;
        }

        $products[$url]['category_paths'] = $categoryPaths;
    }

    /**
     * @param  array<string, mixed>|null  $discovery
     * @return array<int, array<string, mixed>>
     */
    private function categoryRecords(?array $discovery): array
    {
        if (! is_array($discovery['categories'] ?? null)) {
            return [];
        }

        $categories = [];

        foreach ($discovery['categories'] as $category) {
            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeCategoryUrl((string) ($category['url'] ?? ''), ZamstCategoryUrlScraper::DEFAULT_URL);

            if ($url === null) {
                continue;
            }

            $record = $this->categoryRecordFromUrl($url, (string) ($category['name'] ?? ''));
            $path = $this->stringList($category['path'] ?? []);

            if ($path !== []) {
                $record['path'] = $path;
                $record['level'] = count($path);
            }

            $categories[$url] = $record;
        }

        return array_values($categories);
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRecordFromUrl(string $url, string $name): array
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        preg_match('#^/kategoria-produktu/(.+)/$#u', $path, $matches);
        $externalId = trim((string) ($matches[1] ?? 'unknown'), '/');
        $segments = array_values(array_filter(explode('/', $externalId)));
        $slug = (string) (end($segments) ?: 'unknown');
        $name = $this->normalizeText($name);
        $name = $name !== '' ? $name : $this->humanizeSlug($slug);

        return [
            'source' => 'zamst',
            'external_category_id' => $externalId,
            'slug' => $slug,
            'name' => $name,
            'url' => $url,
            'path' => [$name],
            'level' => count($segments),
            'parent_external_category_id' => count($segments) > 1
                ? implode('/', array_slice($segments, 0, -1))
                : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function paginationUrls(string $html, string $baseUrl, string $rootUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $urls = [];

        foreach (['link[rel="next"][href]', 'a.next.page-numbers[href]', '.page-numbers a[href]', 'a[rel="next"][href]'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$urls, $baseUrl, $rootUrl): void {
                    $href = $node->attr('href');
                    $url = is_string($href) ? $this->normalizeListingUrl($href, $baseUrl) : null;

                    if ($url !== null && $url !== $rootUrl) {
                        $urls[$url] = true;
                    }
                });
            } catch (Throwable) {
                continue;
            }
        }

        return array_keys($urls);
    }

    private function isCataloguePaginationUrl(string $url): bool
    {
        return preg_match('#^/sklep/page/[1-9][0-9]*/$#', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    private function normalizeListingUrl(string $candidate, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($candidate, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = rawurldecode((string) parse_url($normalized, PHP_URL_PATH));

        return preg_match('#^/(?:sklep(?:/page/[1-9][0-9]*)?|kategoria-produktu/.+?(?:/page/[1-9][0-9]*)?)/$#u', $path) === 1
            ? $normalized
            : null;
    }

    private function normalizeCategoryUrl(string $candidate, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($candidate, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = rawurldecode((string) parse_url($normalized, PHP_URL_PATH));

        return preg_match('#^/kategoria-produktu/.+/$#u', $path) === 1 && ! str_contains($path, '/page/')
            ? $normalized
            : null;
    }

    private function normalizeProductUrl(string $candidate, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($candidate, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        return preg_match('#^/produkt/[^/]+/$#u', rawurldecode((string) parse_url($normalized, PHP_URL_PATH))) === 1
            ? $normalized
            : null;
    }

    private function normalizeAssetUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode(str_replace('\\/', '/', $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return 'https:'.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return 'https://'.self::CANONICAL_HOST.$candidate;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        return null;
    }

    private function normalizeUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode(str_replace('\\/', '/', $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with(mb_strtolower($candidate), 'javascript:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
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

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', (string) ($parts['path'] ?? '/'))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = rawurlencode(rawurldecode($segment));
        }

        $path = '/'.implode('/', $segments);

        if ($path !== '/' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function productSlugFromUrl(string $url): string
    {
        $path = trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
        $segments = explode('/', $path);

        return (string) end($segments);
    }

    /**
     * @param  array<string, string>  $failedUrls
     */
    private function fetchBody(string $url, array &$failedUrls): ?string
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
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->successful()) {
                unset($failedUrls[$url]);

                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->attempts) {
                $this->pauseBeforeRetry();
            }
        }

        $failedUrls[$url] = $lastFailure;

        return null;
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopZamstCrawler/1.0)',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $entry) {
            if (is_scalar($entry)) {
                $entry = $this->normalizeText((string) $entry);

                if ($entry !== '') {
                    $values[] = $entry;
                }
            }
        }

        return array_values(array_unique($values));
    }

    private function humanizeSlug(string $slug): string
    {
        return mb_convert_case(
            $this->normalizeText(str_replace(['-', '_'], ' ', rawurldecode($slug))),
            MB_CASE_TITLE,
            'UTF-8',
        );
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
