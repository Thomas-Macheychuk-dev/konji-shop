<?php

declare(strict_types=1);

namespace App\Services\Butterfly;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ButterflyCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://butterfly-mag.com';

    private const BUTTERFLY_HOST = 'butterfly-mag.com';

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
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * Discover Butterfly category hierarchy from the Shoper menu.
     *
     * Butterfly keeps product categories under the hidden "NASZE PRODUKTY"/menu wrapper.
     * Parent categories can list products as well as subcategories, so every discovered `/pl/c/...`
     * category is returned as a product-scraping category URL.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     source: string,
     *     start_urls: array<int, string>,
     *     top_categories: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     category_urls: array<int, string>,
     *     product_category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $visited = [];
        $failed = [];
        $topCategoriesByUrl = [];
        $normalizedStartUrls = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isButterflyUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Butterfly category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractRootCategoryTree($html, $url) as $rootCategory) {
                $topCategoriesByUrl[(string) $rootCategory['url']] = $rootCategory;
            }
        }

        $topCategories = array_values($topCategoriesByUrl);
        $flatCategories = $this->flattenCategories($topCategories);
        $flatCategories = $this->deduplicateFlatCategories($flatCategories);
        $categoryUrls = array_values(array_unique(array_map(
            static fn (array $category): string => (string) $category['url'],
            $flatCategories,
        )));

        return [
            'source' => 'butterfly',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $flatCategories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRootCategoryTree(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
            $rootMenus = $crawler->filter('ul.menu-list');
        } catch (Throwable) {
            return [];
        }

        $roots = [];

        $rootMenus->each(function (Crawler $menu) use (&$roots, $baseUrl): void {
            $menuElement = $menu->getNode(0);

            if (! $menuElement instanceof DOMElement) {
                return;
            }

            foreach ($this->directChildElements($menuElement, 'li') as $menuItem) {
                if ($this->isProductMenuWrapper($menuItem)) {
                    foreach ($this->directSubmenuLists($menuItem) as $submenu) {
                        foreach ($this->directChildElements($submenu, 'li') as $rootItem) {
                            $root = $this->categoryFromListItem(
                                item: $rootItem,
                                baseUrl: $baseUrl,
                                parentExternalCategoryId: null,
                                parentPath: [],
                            );

                            if ($root !== null) {
                                $roots[] = $root;
                            }
                        }
                    }

                    continue;
                }

                $root = $this->categoryFromListItem(
                    item: $menuItem,
                    baseUrl: $baseUrl,
                    parentExternalCategoryId: null,
                    parentPath: [],
                );

                if ($root !== null) {
                    $roots[] = $root;
                }
            }
        });

        return $roots;
    }

    /**
     * @param  array<int, string>  $parentPath
     * @return array<string, mixed>|null
     */
    private function categoryFromListItem(
        DOMElement $item,
        string $baseUrl,
        ?string $parentExternalCategoryId,
        array $parentPath,
    ): ?array {
        $anchor = $this->firstHeadingAnchor($item);

        if (! $anchor instanceof DOMElement) {
            return null;
        }

        $name = $this->textContent($anchor);
        $url = $this->normalizeCategoryUrl($anchor->getAttribute('href'), $baseUrl);

        if ($name === '' || $url === null) {
            return null;
        }

        $externalCategoryId = $this->externalCategoryIdFromListItem($item) ?? $this->externalCategoryIdFromUrl($url);
        $children = [];

        foreach ($this->directSubmenuLists($item) as $submenu) {
            foreach ($this->directChildElements($submenu, 'li') as $childItem) {
                $child = $this->categoryFromListItem(
                    item: $childItem,
                    baseUrl: $baseUrl,
                    parentExternalCategoryId: $externalCategoryId,
                    parentPath: [...$parentPath, $name],
                );

                if ($child !== null) {
                    $children[] = $child;
                }
            }
        }

        return $this->buildCategory(
            name: $name,
            sourceName: $name,
            url: $url,
            parentExternalCategoryId: $parentExternalCategoryId,
            parentPath: $parentPath,
            level: count($parentPath) + 1,
            externalCategoryId: $externalCategoryId,
            children: $children,
        );
    }

    /**
     * @param  array<int, string>  $parentPath
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function buildCategory(
        string $name,
        string $sourceName,
        string $url,
        ?string $parentExternalCategoryId,
        array $parentPath,
        int $level,
        ?string $externalCategoryId,
        array $children,
    ): array {
        return [
            'source' => 'butterfly',
            'external_category_id' => $externalCategoryId,
            'name' => $name,
            'source_name' => $sourceName,
            'url' => $url,
            'slug' => $this->slugFromUrl($url, $name),
            'level' => $level,
            'parent_external_category_id' => $parentExternalCategoryId,
            'path' => [...$parentPath, $name],
            'children' => $children,
        ];
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directChildElements(DOMElement $element, ?string $tagName = null): array
    {
        $children = [];
        $expectedTagName = $tagName === null ? null : mb_strtolower($tagName);

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($expectedTagName !== null && mb_strtolower($child->tagName) !== $expectedTagName) {
                continue;
            }

            $children[] = $child;
        }

        return $children;
    }

    private function firstHeadingAnchor(DOMElement $item): ?DOMElement
    {
        foreach (['h3', 'p'] as $headingTag) {
            foreach ($this->directChildElements($item, $headingTag) as $heading) {
                foreach ($this->directChildElements($heading, 'a') as $anchor) {
                    if ($anchor->hasAttribute('href')) {
                        return $anchor;
                    }
                }
            }
        }

        foreach ($this->directChildElements($item, 'a') as $anchor) {
            if ($anchor->hasAttribute('href')) {
                return $anchor;
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directSubmenuLists(DOMElement $item): array
    {
        $lists = [];

        foreach ($this->directChildElements($item, 'div') as $submenuContainer) {
            if (! $this->hasClass($submenuContainer, 'submenu')) {
                continue;
            }

            foreach ($this->directChildElements($submenuContainer, 'ul') as $list) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function flattenCategories(array $categories): array
    {
        $flat = [];

        foreach ($categories as $category) {
            $children = $category['children'] ?? [];
            $categoryWithoutChildren = $category;
            unset($categoryWithoutChildren['children']);

            $flat[] = $categoryWithoutChildren;

            if (is_array($children) && $children !== []) {
                array_push($flat, ...$this->flattenCategories($children));
            }
        }

        return $flat;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateFlatCategories(array $categories): array
    {
        $seen = [];
        $unique = [];

        foreach ($categories as $category) {
            $url = (string) ($category['url'] ?? '');

            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $unique[] = $category;
        }

        return $unique;
    }

    private function isProductMenuWrapper(DOMElement $item): bool
    {
        $id = $item->getAttribute('id');

        if (preg_match('/^hcategory_0+$/', $id) === 1) {
            return true;
        }

        $anchor = $this->firstHeadingAnchor($item);

        if (! $anchor instanceof DOMElement) {
            return false;
        }

        $name = $this->normalizeComparableName($this->textContent($anchor));
        $href = trim($anchor->getAttribute('href'));

        return in_array($name, ['nasze produkty', 'menu', 'produkty'], true)
            && ($href === '' || $href === '#' || str_contains($href, '#box_mainproducts'));
    }

    private function normalizeComparableName(string $name): string
    {
        $name = $this->text($name);
        $name = Str::ascii($name);
        $name = mb_strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    private function textContent(DOMElement $element): string
    {
        foreach ($element->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || mb_strtolower($childNode->tagName) !== 'span') {
                continue;
            }

            return $this->text($childNode->textContent ?? '');
        }

        return $this->text($element->textContent ?? '');
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeCategoryUrl(string $href, string $baseUrl): ?string
    {
        $url = $this->normalizeUrl($href, $baseUrl);

        if ($url === null || ! $this->isButterflyUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if ($path === '/' || ! str_starts_with(mb_strtolower($path), '/pl/c/')) {
            return null;
        }

        return 'https://'.self::BUTTERFLY_HOST.$path;
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
            $url = 'https://'.self::BUTTERFLY_HOST.$url;
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

            $url = $base['scheme'].'://'.$base['host'].$directory.'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $normalizedPath = $this->normalizePath($path);

        return 'https://'.$host.($normalizedPath === '/' ? '' : $normalizedPath);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function isButterflyUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::BUTTERFLY_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            self::BUTTERFLY_HOST, 'www.'.self::BUTTERFLY_HOST => self::BUTTERFLY_HOST,
            default => null,
        };
    }

    private function slugFromUrl(string $url, string $fallbackName): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $lastSegment = end($segments);

        if (is_string($lastSegment) && $lastSegment !== '') {
            return Str::slug($lastSegment);
        }

        return Str::slug($fallbackName);
    }

    private function externalCategoryIdFromListItem(DOMElement $item): ?string
    {
        $id = $item->getAttribute('id');

        if (preg_match('/hcategory_(\d+)/', $id, $matches) !== 1) {
            return null;
        }

        if ($matches[1] === '0') {
            return null;
        }

        return ltrim($matches[1], '0') ?: '0';
    }

    private function externalCategoryIdFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path)));
        $lastSegment = (string) end($segments);

        if (preg_match('/^\d+$/', $lastSegment) === 1) {
            return $lastSegment;
        }

        return Str::slug($path);
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return str_contains(' '.$element->getAttribute('class').' ', ' '.$class.' ');
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopButterflyScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
