<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class WojdakProductUrlScraper
{
    private const WOJDAK_HOST = 'wojdak.pl';

    public function __construct(
        private readonly WojdakCategoryUrlScraper $categoryUrlScraper,
    ) {
    }

    /**
     * Convenience wrapper for callers/tests that only need product URLs.
     *
     * When no category URLs are passed, phase 1 category discovery is run first.
     *
     * @param  array<int, string>  $categoryUrls
     * @param  array<int, string>  $rootCategoryUrls
     * @return array<int, string>
     */
    public function discover(
        array $categoryUrls = [],
        array $rootCategoryUrls = WojdakCategoryUrlScraper::DEFAULT_ROOT_CATEGORY_URLS,
        bool $discoverCategories = true,
        ?int $limit = null,
    ): array {
        return $this->scrape(
            categoryUrls: $categoryUrls,
            rootCategoryUrls: $rootCategoryUrls,
            discoverCategories: $discoverCategories,
            limit: $limit,
        )['product_urls'];
    }

    /**
     * @param  array<int, string>  $categoryUrls
     * @param  array<int, string>  $rootCategoryUrls
     * @return array{
     *     product_urls: array<int, string>,
     *     category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $categoryUrls = [],
        array $rootCategoryUrls = WojdakCategoryUrlScraper::DEFAULT_ROOT_CATEGORY_URLS,
        bool $discoverCategories = true,
        ?int $limit = null,
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

            foreach ($categoryDiscoveryResult['visited_urls'] as $visitedUrl) {
                $visited[$visitedUrl] = true;
            }

            $failed = array_replace($failed, $categoryDiscoveryResult['failed_urls']);
        }

        foreach (array_keys($categoryUrls) as $categoryUrl) {
            if (isset($visited[$categoryUrl])) {
                continue;
            }

            $visited[$categoryUrl] = true;
            $html = $this->fetchBody($categoryUrl, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractProductUrls($html, $categoryUrl) as $productUrl) {
                $productUrls[$productUrl] = true;

                if ($limit !== null && count($productUrls) >= $limit) {
                    break 2;
                }
            }
        }

        $discoveredProductUrls = array_keys($productUrls);

        if ($limit !== null) {
            $discoveredProductUrls = array_slice($discoveredProductUrls, 0, $limit);
        }

        return [
            'product_urls' => $discoveredProductUrls,
            'category_urls' => array_keys($categoryUrls),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
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
            '#https?://(?:www\.)?wojdak\.pl/product/[^\s"\'<>\)]+#iu',
            '#["\'](?:url|product_url|canonical_url|href)["\']\s*:\s*["\'](https?://(?:www\.)?wojdak\.pl/product/[^"\'<>]+|/product/[^"\'<>]+)["\']#iu',
            '#(?<![a-z0-9/_-])/product/[a-z0-9][a-z0-9\-]*(?:/)?#iu',
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

    private function trimRawUrlMatch(string $url): string
    {
        return trim(rtrim($url, '.,;:)]}'), '"\'');
    }

    private function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isWojdakUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! str_starts_with($path, '/produkty/')) {
            return null;
        }

        return 'https://'.self::WOJDAK_HOST.$this->normalizePath($path);
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isWojdakUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! $this->looksLikeProductPath($path)) {
            return null;
        }

        return 'https://'.self::WOJDAK_HOST.$this->normalizePath($path);
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
            $url = 'https://'.self::WOJDAK_HOST.$url;
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

        return $parts['scheme'].'://'.$parts['host'].$path;
    }

    private function looksLikeProductPath(string $path): bool
    {
        $path = mb_strtolower($this->normalizePath($path));

        return preg_match('#^/product/[a-z0-9][a-z0-9\-]*/$#', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function isWojdakUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === self::WOJDAK_HOST || $host === 'www.'.self::WOJDAK_HOST;
    }
}
