<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class WojdakCategoryUrlScraper
{
    public const DEFAULT_ROOT_CATEGORY_URLS = [
        'https://wojdak.pl/produkty/odziez-medyczna-damska/',
        'https://wojdak.pl/produkty/odziez-medyczna-meska/',
    ];

    public const HARD_CODED_CATEGORY_URLS = [
        'https://wojdak.pl/produkty/obuwie-damskie/',
        'https://wojdak.pl/produkty/obuwie-meskie/',
    ];

    private const WOJDAK_HOST = 'wojdak.pl';

    /**
     * Convenience wrapper for callers/tests that only need category URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(
        array $startUrls = self::DEFAULT_ROOT_CATEGORY_URLS,
        bool $includeHardCodedCategories = true,
    ): array {
        return $this->scrape(
            startUrls: $startUrls,
            includeHardCodedCategories: $includeHardCodedCategories,
        )['category_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $startUrls = self::DEFAULT_ROOT_CATEGORY_URLS,
        bool $includeHardCodedCategories = true,
    ): array {
        $rootUrls = $this->normalizeRootUrls($startUrls);
        $visited = [];
        $failed = [];
        $categoryUrls = [];

        foreach ($rootUrls as $rootUrl) {
            if (isset($visited[$rootUrl])) {
                continue;
            }

            $visited[$rootUrl] = true;
            $html = $this->fetchBody($rootUrl, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractCandidateCategoryUrls($html, $rootUrl) as $candidateUrl) {
                if (! $this->shouldKeepCategoryUrl($candidateUrl, $rootUrls)) {
                    continue;
                }

                $categoryUrls[$candidateUrl] = true;
            }
        }

        if ($includeHardCodedCategories) {
            foreach (self::HARD_CODED_CATEGORY_URLS as $hardCodedCategoryUrl) {
                $normalized = $this->normalizeCategoryUrl($hardCodedCategoryUrl);

                if ($normalized !== null) {
                    $categoryUrls[$normalized] = true;
                }
            }
        }

        return [
            'category_urls' => array_keys($categoryUrls),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    private function normalizeRootUrls(array $startUrls): array
    {
        $rootUrls = [];

        foreach ($startUrls as $startUrl) {
            $normalized = $this->normalizeCategoryUrl($startUrl);

            if ($normalized === null) {
                continue;
            }

            $rootUrls[$normalized] = true;
        }

        return array_keys($rootUrls);
    }

    /**
     * @return array<int, string>
     */
    private function extractCandidateCategoryUrls(string $html, string $baseUrl): array
    {
        $urls = [];

        try {
            $crawler = new Crawler($html, $baseUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                $href = $node->attr('href');

                if (! is_string($href) || trim($href) === '') {
                    return;
                }

                $url = $this->normalizeCategoryUrl($href, $baseUrl);

                if ($url === null || ! $this->isWojdakUrl($url)) {
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
     * @param  array<int, string>  $rootUrls
     */
    private function shouldKeepCategoryUrl(string $url, array $rootUrls): bool
    {
        if (! $this->isWojdakUrl($url)) {
            return false;
        }

        $path = $this->normalizedPath($url);

        if ($path === null || $path === '/produkty/') {
            return false;
        }

        foreach ($rootUrls as $rootUrl) {
            $rootPath = $this->normalizedPath($rootUrl);

            if ($rootPath === null || $path === $rootPath) {
                continue;
            }

            if (str_starts_with($path, $rootPath)) {
                return true;
            }
        }

        return false;
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

    private function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
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

        if (! str_starts_with($path, '/produkty/')) {
            return null;
        }

        return 'https://'.self::WOJDAK_HOST.$this->normalizePath($path);
    }

    private function normalizedPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $this->normalizePath($path);
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
