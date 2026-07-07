<?php

declare(strict_types=1);

namespace App\Services\Apolonia;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ApoloniaCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://www.apolonia.com.pl/';

    public const ODZIEZ_MEDYCZNA_URL = 'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html';

    public const OBUWIE_MEDYCZNE_URL = 'https://www.apolonia.com.pl/pol_m_Obuwie-medyczne-230.html';

    private const APOLONIA_HOST = 'www.apolonia.com.pl';

    /**
     * @var list<string>
     */
    private const TARGET_TOP_CATEGORY_NAMES = [
        'Odzież medyczna',
        'Obuwie medyczne',
    ];

    /**
     * @var list<string>
     */
    private const TARGET_TOP_CATEGORY_URLS = [
        self::ODZIEZ_MEDYCZNA_URL,
        self::OBUWIE_MEDYCZNE_URL,
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    private int $maxPages = 20;

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

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withMaxPages(int $pages): self
    {
        $this->maxPages = max(1, $pages);

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     source: string,
     *     start_urls: array<int, string>,
     *     top_categories: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     category_urls: array<int, string>,
     *     product_category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $visited = [];
        $failed = [];
        $normalizedStartUrls = [];
        $categoriesByUrl = [];
        $order = 0;

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeAbsoluteUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isApoloniaUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (count($visited) >= $this->maxPages) {
                break;
            }

            $visited[$url] = true;
            $this->emit('Fetching Apolonia category/menu page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractCategoryCandidates($html, $url) as $candidate) {
                if (! isset($categoriesByUrl[$candidate['url']])) {
                    $candidate['discovery_order'] = $order++;
                    $categoriesByUrl[$candidate['url']] = $candidate;
                }
            }
        }

        foreach (self::TARGET_TOP_CATEGORY_URLS as $fallbackUrl) {
            if (! isset($categoriesByUrl[$fallbackUrl])) {
                $categoriesByUrl[$fallbackUrl] = [
                    'url' => $fallbackUrl,
                    'name' => $this->labelFromCategoryUrl($fallbackUrl),
                    'source_name' => $this->labelFromCategoryUrl($fallbackUrl),
                    'external_category_id' => $this->categoryIdFromUrl($fallbackUrl),
                    'slug' => $this->categorySlugFromUrl($fallbackUrl),
                    'level' => 1,
                    'path' => [$this->labelFromCategoryUrl($fallbackUrl)],
                    'parent_external_category_id' => null,
                    'top_category_name' => $this->labelFromCategoryUrl($fallbackUrl),
                    'top_category_url' => $fallbackUrl,
                    'discovery_order' => $order++,
                ];
            }
        }

        $categories = $this->finalizeCategories($categoriesByUrl);
        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => (int) $category['level'] === 1
        ));
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => $category['url'],
            $categories
        ));
        $productCategoryUrls = array_values(array_intersect(self::TARGET_TOP_CATEGORY_URLS, $categoryUrls));

        if ($productCategoryUrls === []) {
            $productCategoryUrls = self::TARGET_TOP_CATEGORY_URLS;
        }

        return [
            'source' => 'apolonia',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $productCategoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        try {
            $this->pauseBeforeRequest();

            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeoutSeconds)
                ->get($url);

            if (! $response->successful()) {
                $failed[$url] = 'HTTP '.$response->status();

                return null;
            }

            return $response->body();
        } catch (Throwable $exception) {
            $failed[$url] = $exception->getMessage();

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCategoryCandidates(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $candidates = [];

        foreach ($this->targetTopCategoryScopes($crawler, $baseUrl) as $scope) {
            $scope->filter('a.nav-link[href], a[href*="pol_m_Odziez-medyczna"], a[href*="pol_m_Obuwie-medyczne"]')->each(function (Crawler $link) use (&$candidates, $baseUrl): void {
                $href = $link->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeCategoryUrl($href, $baseUrl);

                if ($url === null || ! $this->isTargetCategoryUrl($url)) {
                    return;
                }

                $name = $this->categoryName($link, $url);

                if ($name === '') {
                    return;
                }

                $candidates[$url] = [
                    'url' => $url,
                    'name' => $name,
                    'source_name' => $name,
                    'external_category_id' => $this->categoryIdFromUrl($url),
                    'slug' => $this->categorySlugFromUrl($url),
                ];
            });
        }

        if ($candidates === []) {
            $crawler->filter('a[href]')->each(function (Crawler $link) use (&$candidates, $baseUrl): void {
                $href = $link->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeCategoryUrl($href, $baseUrl);

                if ($url === null || ! $this->isTargetCategoryUrl($url)) {
                    return;
                }

                $name = $this->categoryName($link, $url);
                $candidates[$url] = [
                    'url' => $url,
                    'name' => $name !== '' ? $name : $this->labelFromCategoryUrl($url),
                    'source_name' => $name !== '' ? $name : $this->labelFromCategoryUrl($url),
                    'external_category_id' => $this->categoryIdFromUrl($url),
                    'slug' => $this->categorySlugFromUrl($url),
                ];
            });
        }

        return array_values($candidates);
    }

    /**
     * @return array<int, Crawler>
     */
    private function targetTopCategoryScopes(Crawler $crawler, string $baseUrl): array
    {
        $scopes = [];

        foreach (['#menu_categories .navbar-nav > li.nav-item', 'nav#menu_categories li.nav-item', '.navbar-nav > li.nav-item'] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $nodes->each(function (Crawler $node) use (&$scopes, $baseUrl): void {
                $topLink = $node->filter('[class~="nav-link"][class~="--l1"]')->first();

                if ($topLink->count() === 0) {
                    return;
                }

                $href = $topLink->attr('href');
                $url = is_string($href) ? $this->normalizeCategoryUrl($href, $baseUrl) : null;
                $label = $this->normalizeText($topLink->text('', true));

                if ($url !== null && in_array($url, self::TARGET_TOP_CATEGORY_URLS, true)) {
                    $scopes[] = $node;

                    return;
                }

                if (in_array($label, self::TARGET_TOP_CATEGORY_NAMES, true)) {
                    $scopes[] = $node;
                }
            });

            if ($scopes !== []) {
                break;
            }
        }

        return $scopes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $categoriesByUrl
     * @return array<int, array<string, mixed>>
     */
    private function finalizeCategories(array $categoriesByUrl): array
    {
        $nameBySlug = [];
        $urlBySlugPath = [];

        foreach ($categoriesByUrl as $url => $category) {
            $slugPath = $this->categorySlugPathFromUrl($url);
            $lastSlug = $slugPath !== [] ? (string) end($slugPath) : $this->categorySlugFromUrl($url);

            if (is_string($category['name'] ?? null) && trim($category['name']) !== '') {
                $nameBySlug[$lastSlug] = $this->normalizeText((string) $category['name']);
            }

            $urlBySlugPath[implode('_', $slugPath)] = $url;
        }

        $categories = [];

        foreach ($categoriesByUrl as $url => $category) {
            $slugPath = $this->categorySlugPathFromUrl($url);
            $path = [];

            foreach ($slugPath as $slug) {
                $path[] = $nameBySlug[$slug] ?? Str::headline(str_replace('-', ' ', $slug));
            }

            if ($path === []) {
                $path = [$this->labelFromCategoryUrl($url)];
            }

            $level = count($path);
            $parentExternalCategoryId = null;

            if ($level > 1) {
                $parentSlugPath = array_slice($slugPath, 0, -1);
                $parentUrl = $urlBySlugPath[implode('_', $parentSlugPath)] ?? null;
                $parentExternalCategoryId = is_string($parentUrl) ? $this->categoryIdFromUrl($parentUrl) : null;
            }

            $categories[] = [
                'source' => 'apolonia',
                'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryIdFromUrl($url)),
                'name' => (string) end($path),
                'source_name' => (string) ($category['source_name'] ?? end($path)),
                'url' => $url,
                'slug' => $this->categorySlugFromUrl($url),
                'level' => $level,
                'parent_external_category_id' => $parentExternalCategoryId,
                'path' => $path,
                'top_category_name' => $path[0],
                'top_category_url' => $urlBySlugPath[$slugPath[0] ?? ''] ?? $url,
                'product_count' => null,
                'discovery_order' => (int) ($category['discovery_order'] ?? 0),
            ];
        }

        usort($categories, static fn (array $left, array $right): int => ($left['discovery_order'] <=> $right['discovery_order']) ?: ($left['url'] <=> $right['url']));

        return array_map(static function (array $category): array {
            unset($category['discovery_order']);

            return $category;
        }, $categories);
    }

    private function categoryName(Crawler $link, string $url): string
    {
        $name = $this->normalizeText((string) $link->attr('title'));

        if ($name === '') {
            $name = $this->normalizeText($link->text('', true));
        }

        return $name !== '' ? $name : $this->labelFromCategoryUrl($url);
    }

    public function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isApoloniaUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (preg_match('~^/pol_m_[^?]+-\d+\.html$~u', $path) !== 1) {
            return null;
        }

        return 'https://'.self::APOLONIA_HOST.$path;
    }

    private function isTargetCategoryUrl(string $url): bool
    {
        $slugPath = $this->categorySlugPathFromUrl($url);
        $topSlug = $slugPath[0] ?? null;

        return in_array($topSlug, ['Odziez-medyczna', 'Obuwie-medyczne'], true);
    }

    private function normalizeAbsoluteUrl(string $url, ?string $baseUrl = null): ?string
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
            $url = 'https://'.self::APOLONIA_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $baseUrl ??= self::DEFAULT_URL;
            $baseParts = parse_url($baseUrl);
            $baseHost = is_string($baseParts['host'] ?? null) ? $this->normalizeHost((string) $baseParts['host']) : self::APOLONIA_HOST;
            $basePath = is_string($baseParts['path'] ?? null) ? dirname((string) $baseParts['path']) : '/';
            $basePath = $basePath === '.' ? '/' : $basePath;
            $url = 'https://'.($baseHost ?? self::APOLONIA_HOST).'/'.ltrim(trim($basePath, '/').'/'.$url, '/');
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.$host.$path.$query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @return list<string>
     */
    private function categorySlugPathFromUrl(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('/^pol_m_(?P<slug>.+)-\d+\.html$/u', $path, $matches) !== 1) {
            return [];
        }

        return array_values(array_filter(explode('_', (string) $matches['slug'])));
    }

    private function categorySlugFromUrl(string $url): string
    {
        $slugPath = $this->categorySlugPathFromUrl($url);
        $slug = $slugPath !== [] ? (string) end($slugPath) : 'apolonia-category';

        return Str::slug($slug) ?: 'apolonia-category';
    }

    private function categoryIdFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('/-(\d+)\.html$/', $path, $matches) === 1) {
            return (string) $matches[1];
        }

        return substr(sha1($url), 0, 12);
    }

    private function labelFromCategoryUrl(string $url): string
    {
        $slug = $this->categorySlugFromUrl($url);

        return Str::headline(str_replace('-', ' ', $slug));
    }

    private function isApoloniaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::APOLONIA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            self::APOLONIA_HOST, 'apolonia.com.pl' => self::APOLONIA_HOST,
            default => null,
        };
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopApoloniaCategoryScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
