<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class WojdakProductUrlScraper
{
    private const WOJDAK_SHOP_HOST = 'sklep.wojdak.pl';

    public function __construct(
        private readonly WojdakCategoryUrlScraper $categoryUrlScraper,
    ) {
    }

    /**
     * Convenience wrapper for callers/tests that only need product URLs.
     *
     * When no category URLs are passed, the hard-coded Wojdak shop categories are used.
     *
     * @param  array<int, string>  $categoryUrls
     * @param  array<int, string>  $rootCategoryUrls Backwards-compatible alias for category URLs.
     * @return array<int, string>
     */
    public function discover(
        array $categoryUrls = [],
        array $rootCategoryUrls = WojdakCategoryUrlScraper::DEFAULT_CATEGORY_URLS,
        bool $discoverCategories = true,
        ?int $limit = null,
        ?int $maxPages = null,
    ): array {
        return $this->scrape(
            categoryUrls: $categoryUrls,
            rootCategoryUrls: $rootCategoryUrls,
            discoverCategories: $discoverCategories,
            limit: $limit,
            maxPages: $maxPages,
        )['product_urls'];
    }

    /**
     * @param  array<int, string>  $categoryUrls
     * @param  array<int, string>  $rootCategoryUrls Backwards-compatible alias for category URLs.
     * @return array{
     *     product_urls: array<int, string>,
     *     category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $categoryUrls = [],
        array $rootCategoryUrls = WojdakCategoryUrlScraper::DEFAULT_CATEGORY_URLS,
        bool $discoverCategories = true,
        ?int $limit = null,
        ?int $maxPages = null,
    ): array {
        $failed = [];
        $visited = [];
        $productUrls = [];
        $categoryUrls = $this->normalizedCategoryUrls($categoryUrls);

        if ($discoverCategories) {
            $categoryDiscoveryResult = $this->categoryUrlScraper->scrape(
                startUrls: $rootCategoryUrls,
                includeHardCodedCategories: true,
            );

            foreach ($categoryDiscoveryResult['category_urls'] as $categoryUrl) {
                $categoryUrls[$categoryUrl] = true;
            }

            $failed = array_replace($failed, $categoryDiscoveryResult['failed_urls']);
        }

        foreach (array_keys($categoryUrls) as $categoryUrl) {
            $categoryProductUrls = $this->crawlCategory(
                categoryUrl: $categoryUrl,
                visited: $visited,
                failed: $failed,
                remainingLimit: $limit === null ? null : max(0, $limit - count($productUrls)),
                maxPages: $maxPages,
            );

            foreach ($categoryProductUrls as $productUrl) {
                $productUrls[$productUrl] = true;

                if ($limit !== null && count($productUrls) >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'product_urls' => array_keys($productUrls),
            'category_urls' => array_keys($categoryUrls),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, true>  $visited
     * @param  array<string, string>  $failed
     * @return array<int, string>
     */
    private function crawlCategory(
        string $categoryUrl,
        array &$visited,
        array &$failed,
        ?int $remainingLimit = null,
        ?int $maxPages = null,
    ): array {
        $categoryProductUrls = [];
        $queued = [$categoryUrl => true];
        $queue = [$categoryUrl];
        $pagesVisitedForCategory = 0;

        while ($queue !== []) {
            if ($remainingLimit !== null && count($categoryProductUrls) >= $remainingLimit) {
                break;
            }

            if ($maxPages !== null && $pagesVisitedForCategory >= $maxPages) {
                break;
            }

            $pageUrl = array_shift($queue);

            if (! is_string($pageUrl) || isset($visited[$pageUrl])) {
                continue;
            }

            $visited[$pageUrl] = true;
            $pagesVisitedForCategory++;

            $html = $this->fetchBody($pageUrl, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractProductUrls($html, $pageUrl) as $productUrl) {
                $categoryProductUrls[$productUrl] = true;

                if ($remainingLimit !== null && count($categoryProductUrls) >= $remainingLimit) {
                    break;
                }
            }

            if ($remainingLimit !== null && count($categoryProductUrls) >= $remainingLimit) {
                break;
            }

            foreach ($this->extractPaginationUrls($html, $pageUrl, $categoryUrl) as $paginationUrl) {
                if (isset($queued[$paginationUrl]) || isset($visited[$paginationUrl])) {
                    continue;
                }

                $queued[$paginationUrl] = true;
                $queue[] = $paginationUrl;
            }
        }

        return array_keys($categoryProductUrls);
    }

    /**
     * @param  array<int, string>  $categoryUrls
     * @return array<string, true>
     */
    private function normalizedCategoryUrls(array $categoryUrls): array
    {
        $normalizedCategoryUrls = [];

        foreach ($categoryUrls as $categoryUrl) {
            $normalized = $this->normalizeCategoryUrl($categoryUrl);

            if ($normalized !== null) {
                $normalizedCategoryUrls[$normalized] = true;
            }
        }

        return $normalizedCategoryUrls;
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders($this->headers())
                ->get($url);
        } catch (Throwable $exception) {
            $failed[$url] = $exception->getMessage();

            return null;
        }

        if (! $response->successful()) {
            $failed[$url] = 'HTTP '.$response->status();

            return null;
        }

        return $response->body();
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractProductUrls(string $html, string $baseUrl): array
    {
        $urls = [];

        foreach ($this->extractProductUrlsFromAnchors($html, $baseUrl) as $url) {
            $urls[$url] = true;
        }

        foreach ($this->extractRawProductUrls($html, $baseUrl) as $url) {
            $urls[$url] = true;
        }

        return array_keys($urls);
    }

    /**
     * @return array<int, string>
     */
    private function extractProductUrlsFromAnchors(string $html, string $baseUrl): array
    {
        $urls = [];

        try {
            $crawler = new Crawler($html, $baseUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                $href = $node->attr('href');

                if (! is_string($href) || trim($href) === '') {
                    return;
                }

                $url = $this->normalizeProductUrl($href, $baseUrl);

                if ($url === null) {
                    return;
                }

                $urls[$url] = true;
            });
        } catch (Throwable) {
            return [];
        }

        return array_keys($urls);
    }

    /**
     * @return array<int, string>
     */
    private function extractRawProductUrls(string $html, string $baseUrl): array
    {
        $urls = [];
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decodedHtml = str_replace(['\\/', '\/'], '/', $decodedHtml);

        $patterns = [
            '#https?://sklep\.wojdak\.pl/produkt/[^\s"\'<>)]+#iu',
            '#["\'](?:url|product_url|canonical_url|href)["\']\s*:\s*["\'](https?://sklep\.wojdak\.pl/produkt/[^"\'<>]+|/produkt/[^"\'<>]+)["\']#iu',
            '#(?<![a-z0-9/_-])/produkt/[a-z0-9][a-z0-9\-]*(?:/)?#iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $decodedHtml, $matches) === false) {
                continue;
            }

            $rawMatches = $matches[1] ?? $matches[0];

            foreach ($rawMatches as $match) {
                $url = $this->normalizeProductUrl($this->trimRawUrlMatch((string) $match), $baseUrl);

                if ($url === null) {
                    continue;
                }

                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * @return array<int, string>
     */
    private function extractPaginationUrls(string $html, string $baseUrl, string $rootCategoryUrl): array
    {
        $urls = [];

        try {
            $crawler = new Crawler($html, $baseUrl);

            $crawler->filter('.woocommerce-pagination a[href], a.page-numbers[href]')->each(
                function (Crawler $node) use (&$urls, $baseUrl, $rootCategoryUrl): void {
                    $href = $node->attr('href');

                    if (! is_string($href) || trim($href) === '') {
                        return;
                    }

                    $url = $this->normalizeCategoryPageUrl($href, $baseUrl, $rootCategoryUrl);

                    if ($url === null) {
                        return;
                    }

                    $urls[$url] = true;
                }
            );
        } catch (Throwable) {
            return [];
        }

        return array_keys($urls);
    }

    private function trimRawUrlMatch(string $url): string
    {
        return trim(rtrim($url, '.,;:)]}'), '"\'');
    }

    private function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isWojdakShopUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! str_starts_with($path, '/kategoria-produktu/')) {
            return null;
        }

        return 'https://'.self::WOJDAK_SHOP_HOST.$this->normalizePath($path);
    }

    private function normalizeCategoryPageUrl(string $url, string $baseUrl, string $rootCategoryUrl): ?string
    {
        $normalized = $this->normalizeCategoryUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $rootPath = $this->normalizeCategoryRootPath((string) parse_url($rootCategoryUrl, PHP_URL_PATH));
        $pagePath = $this->normalizePath((string) parse_url($normalized, PHP_URL_PATH));

        if ($pagePath === $rootPath) {
            return $normalized;
        }

        if (preg_match('#^'.preg_quote(rtrim($rootPath, '/'), '#').'/page/[1-9][0-9]*/$#', $pagePath) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isWojdakShopUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! $this->looksLikeProductPath($path)) {
            return null;
        }

        return 'https://'.self::WOJDAK_SHOP_HOST.$this->normalizePath($path);
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with(mb_strtolower($url), 'mailto:')
            || str_starts_with(mb_strtolower($url), 'tel:')
            || str_starts_with(mb_strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::WOJDAK_SHOP_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! isset($base['scheme'], $base['host'])) {
                return null;
            }

            $basePath = $base['path'] ?? '/';
            $directory = str_ends_with($basePath, '/')
                ? rtrim($basePath, '/')
                : rtrim(dirname($basePath), '/');

            $url = $base['scheme'].'://'.$base['host'].$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        return 'https://'.$parts['host'].$path;
    }

    private function looksLikeProductPath(string $path): bool
    {
        $path = mb_strtolower($this->normalizePath($path));

        return preg_match('#^/produkt/[a-z0-9][a-z0-9\-]*/$#', $path) === 1;
    }

    private function normalizeCategoryRootPath(string $path): string
    {
        $path = $this->normalizePath($path);

        return preg_replace('#/page/[1-9][0-9]*/$#', '/', $path) ?: $path;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function isWojdakShopUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && mb_strtolower($host) === self::WOJDAK_SHOP_HOST;
    }
}
