<?php

declare(strict_types=1);

namespace App\Services\Mobilex;

use Closure;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MobilexCategoryUrlScraper
{
    public const DEFAULT_PRODUCTS_URL = 'https://mobilex.pl/produkty/';

    private const MOBILEX_HOST = 'mobilex.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

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

    /**
     * Convenience wrapper for callers/tests that only need product-scraping category URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_PRODUCTS_URL]): array
    {
        return $this->scrapeHierarchy($startUrls)['category_urls'];
    }

    /**
     * Phase 1: discover only the top-level Mobilex categories from the products page.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     category_urls: array<int, string>,
     *     categories: array<int, array{name: string, url: string}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_PRODUCTS_URL]): array
    {
        return $this->scrapeTopLevel($startUrls);
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     category_urls: array<int, string>,
     *     categories: array<int, array{name: string, url: string}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrapeTopLevel(array $startUrls = [self::DEFAULT_PRODUCTS_URL]): array
    {
        $categories = [];
        $visited = [];
        $failed = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_PRODUCTS_URL);

            if ($url === null) {
                continue;
            }

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Mobilex top-level category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractTopLevelCategories($html, $url) as $category) {
                $categories[$category['url']] = $category;
            }
        }

        return [
            'category_urls' => array_keys($categories),
            'categories' => array_values($categories),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * Phase 2: discover top-level categories and their lower categories.
     *
     * `category_urls` contains the URLs that should be used later for product-link scraping:
     * - lower-category URLs when a top category has children;
     * - the top-category URL when it has no children.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     category_urls: array<int, string>,
     *     product_category_urls: array<int, string>,
     *     categories: array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null}>,
     *     top_categories: array<int, array{name: string, url: string, children: array<int, array{name: string, url: string}>, product_category_urls: array<int, string>}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrapeHierarchy(array $startUrls = [self::DEFAULT_PRODUCTS_URL]): array
    {
        $topLevel = $this->scrapeTopLevel($startUrls);
        $visited = array_fill_keys($topLevel['visited_urls'], true);
        $failed = $topLevel['failed_urls'];
        $topCategories = [];
        $productCategories = [];

        foreach ($topLevel['categories'] as $topCategory) {
            $topUrl = $topCategory['url'];
            $children = [];

            if (! isset($visited[$topUrl])) {
                $visited[$topUrl] = true;
                $this->emit('Checking lower categories for '.$topCategory['name'].': '.$topUrl);
                $html = $this->fetchBody($topUrl, $failed);

                if ($html !== null) {
                    $children = $this->extractLowerCategories($html, $topUrl);
                }
            }

            $productCategoryUrls = $children === []
                ? [$topUrl]
                : array_map(static fn (array $child): string => $child['url'], $children);

            $topCategories[] = [
                'name' => $topCategory['name'],
                'url' => $topUrl,
                'children' => $children,
                'product_category_urls' => $productCategoryUrls,
            ];

            if ($children === []) {
                $productCategories[$topUrl] = [
                    'name' => $topCategory['name'],
                    'url' => $topUrl,
                    'parent_name' => null,
                    'parent_url' => null,
                ];

                continue;
            }

            foreach ($children as $child) {
                $productCategories[$child['url']] = [
                    'name' => $child['name'],
                    'url' => $child['url'],
                    'parent_name' => $topCategory['name'],
                    'parent_url' => $topUrl,
                ];
            }
        }

        $productCategoryUrls = array_keys($productCategories);

        return [
            'category_urls' => $productCategoryUrls,
            'product_category_urls' => $productCategoryUrls,
            'categories' => array_values($productCategories),
            'top_categories' => $topCategories,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $this->pauseBeforeRequest();

        try {
            $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
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


    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
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
     * @return array<int, array{name: string, url: string}>
     */
    private function extractTopLevelCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
            $anchors = $crawler->filter('ul.custom-taxonomy-list a[href]');

            if ($anchors->count() === 0) {
                return [];
            }

            return $this->extractCategoriesFromAnchors($anchors, $baseUrl, true);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    private function extractLowerCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);

            if ($this->isSchollLandingPage($baseUrl)) {
                return $this->extractSchollLowerCategories($crawler, $baseUrl);
            }

            $anchors = $crawler->filter('ul.custom-taxonomy-list a[href]');

            if ($anchors->count() === 0) {
                return [];
            }

            return $this->extractCategoriesFromAnchors($anchors, $baseUrl, false);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    private function extractCategoriesFromAnchors(Crawler $anchors, string $baseUrl, bool $useProductsPageBoundaries): array
    {
        $categories = [];
        $insideCategoryList = ! $useProductsPageBoundaries;

        $anchors->each(function (Crawler $node) use (&$categories, &$insideCategoryList, $baseUrl, $useProductsPageBoundaries): void {
            $label = $this->normalizeLabel($node->text(''));

            if ($useProductsPageBoundaries && $this->isStartBoundary($label)) {
                $insideCategoryList = true;

                return;
            }

            if (! $insideCategoryList) {
                return;
            }

            if ($useProductsPageBoundaries && $this->isEndBoundary($label)) {
                $insideCategoryList = false;

                return;
            }

            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $url = $this->normalizeCategoryUrl($href, $baseUrl);

            if ($url === null) {
                return;
            }

            $categories[$url] = [
                'name' => $label,
                'url' => $url,
            ];
        });

        return array_values($categories);
    }

    /**
     * Obuwie Scholl is a landing page, not a taxonomy archive. Its lower categories are
     * tile links under #kafle-sholl, with the visible name in the first following <h2>.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function extractSchollLowerCategories(Crawler $crawler, string $baseUrl): array
    {
        $tiles = $crawler->filter('#kafle-sholl a[href]');

        if ($tiles->count() === 0) {
            return [];
        }

        $categories = [];

        $tiles->each(function (Crawler $node) use (&$categories, $baseUrl): void {
            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $url = $this->normalizeCategoryUrl($href, $baseUrl);

            if ($url === null) {
                return;
            }

            $domNode = $node->getNode(0);

            if (! $domNode instanceof DOMElement) {
                return;
            }

            $label = $this->nearestFollowingHeadingLabel($domNode) ?? $this->labelFromCategoryUrl($url);
            $label = $this->normalizeLabel($label);

            if ($label === '') {
                return;
            }

            $categories[$url] = [
                'name' => $label,
                'url' => $url,
            ];
        });

        return array_values($categories);
    }

    private function nearestFollowingHeadingLabel(DOMElement $anchor): ?string
    {
        $document = $anchor->ownerDocument;

        if ($document === null) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('following::*[self::h2 or self::a[@href]][1]', $anchor);

        if ($nodes === false) {
            return null;
        }

        $nextRelevantNode = $nodes->item(0);

        if (! $nextRelevantNode instanceof DOMElement || mb_strtolower($nextRelevantNode->tagName) !== 'h2') {
            return null;
        }

        return $this->normalizeLabel($nextRelevantNode->textContent ?? '');
    }

    private function labelFromCategoryUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = (string) end($segments);
        $label = str_replace('-', ' ', $slug);

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isMobilexUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (! $this->looksLikeCategoryPath($path)) {
            return null;
        }

        return 'https://'.self::MOBILEX_HOST.$path;
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
            $url = 'https://'.self::MOBILEX_HOST.$url;
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

        return 'https://'.mb_strtolower((string) $parts['host']).$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function isStartBoundary(string $label): bool
    {
        return $this->normalizeBoundaryLabel($label) === 'nowosci';
    }

    private function isEndBoundary(string $label): bool
    {
        return $this->normalizeBoundaryLabel($label) === 'serwis';
    }

    private function normalizeBoundaryLabel(string $label): string
    {
        $label = mb_strtolower($this->normalizeLabel($label));
        $label = strtr($label, [
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ż' => 'z',
            'ź' => 'z',
        ]);

        return $label;
    }

    private function isSchollLandingPage(string $url): bool
    {
        return $this->normalizeUrl($url) === 'https://'.self::MOBILEX_HOST.'/obuwie-scholl/';
    }

    private function looksLikeCategoryPath(string $path): bool
    {
        if (preg_match('#^/kategoria-produktu/[a-z0-9][a-z0-9\-/]*/$#', $path) === 1) {
            return true;
        }

        return $path === '/obuwie-scholl/';
    }

    private function isMobilexUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && mb_strtolower($host) === self::MOBILEX_HOST;
    }
}
