<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

final class WojdakCategoryUrlScraper
{
    public const DEFAULT_CATEGORY_URLS = [
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/',
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-meskie/',
    ];

    /**
     * Backwards-compatible alias for older command/service callers.
     * Wojdak product discovery now starts from hard-coded WooCommerce shop categories.
     *
     * @var array<int, string>
     */
    public const DEFAULT_ROOT_CATEGORY_URLS = self::DEFAULT_CATEGORY_URLS;

    /**
     * Backwards-compatible alias for older tests/callers.
     *
     * @var array<int, string>
     */
    public const HARD_CODED_CATEGORY_URLS = self::DEFAULT_CATEGORY_URLS;

    private const WOJDAK_SHOP_HOST = 'sklep.wojdak.pl';

    /**
     * Convenience wrapper for callers/tests that only need category URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(
        array $startUrls = self::DEFAULT_CATEGORY_URLS,
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
        array $startUrls = self::DEFAULT_CATEGORY_URLS,
        bool $includeHardCodedCategories = true,
    ): array {
        $categoryUrls = [];
        $sourceUrls = $includeHardCodedCategories
            ? array_merge($startUrls, self::DEFAULT_CATEGORY_URLS)
            : $startUrls;

        foreach ($sourceUrls as $url) {
            $normalized = $this->normalizeCategoryUrl($url);

            if ($normalized === null) {
                continue;
            }

            $categoryUrls[$normalized] = true;
        }

        return [
            'category_urls' => array_keys($categoryUrls),
            'visited_urls' => [],
            'failed_urls' => [],
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

        if (! $this->isWojdakShopHost((string) $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/kategoria-produktu/')) {
            return null;
        }

        return 'https://'.self::WOJDAK_SHOP_HOST.$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function isWojdakShopHost(string $host): bool
    {
        return mb_strtolower($host) === self::WOJDAK_SHOP_HOST;
    }
}
