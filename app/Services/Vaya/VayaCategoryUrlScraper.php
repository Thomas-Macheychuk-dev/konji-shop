<?php

declare(strict_types=1);

namespace App\Services\Vaya;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class VayaCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://www.vaya.com.pl/';

    private const VAYA_HOST = 'www.vaya.com.pl';

    /**
     * Numeric-looking PHP array keys are stored as integers at runtime.
     *
     * @var array<int|string, string>
     */
    private const TARGET_TOP_CATEGORIES = [
        '14' => 'Wkładki ortopedyczne',
        '122' => 'Produkty Medyczne',
        '125' => 'Kompresy żelowe',
        '38' => 'Scholl',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

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
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * Discover the selected Vaya Shoper category trees from the shop navigation.
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
     *     failed_urls: array<string, string>
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $normalizedStartUrls = [];
        $visited = [];
        $failed = [];
        $categoriesByUrl = [];
        $discoveryOrder = 0;

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isVayaUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Vaya category navigation page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractTargetCategories($html, $url) as $category) {
                $categoryUrl = (string) $category['url'];

                if (! isset($categoriesByUrl[$categoryUrl])) {
                    $category['discovery_order'] = $discoveryOrder++;
                    $categoriesByUrl[$categoryUrl] = $category;

                    continue;
                }

                if ((bool) $category['has_children'] && ! (bool) $categoriesByUrl[$categoryUrl]['has_children']) {
                    $category['discovery_order'] = $categoriesByUrl[$categoryUrl]['discovery_order'];
                    $categoriesByUrl[$categoryUrl] = $category;
                }
            }
        }

        $categories = array_values($categoriesByUrl);

        usort(
            $categories,
            static fn (array $left, array $right): int => ((int) $left['discovery_order']) <=> ((int) $right['discovery_order'])
        );

        $parentIds = [];

        foreach ($categories as $category) {
            $parentId = $category['parent_external_category_id'];

            if (is_string($parentId) && $parentId !== '') {
                $parentIds[$parentId] = true;
            }
        }

        foreach ($categories as &$category) {
            $externalId = (string) $category['external_category_id'];
            $category['has_children'] = isset($parentIds[$externalId]);
            $category['is_product_category'] = ! $category['has_children'];
            unset($category['discovery_order']);
        }
        unset($category);

        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => ((int) $category['level']) === 1
        ));
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories
        ));
        $productCategoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            array_filter(
                $categories,
                static fn (array $category): bool => (bool) $category['is_product_category']
            )
        ));

        return [
            'source' => 'vaya',
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
        $this->pauseBeforeRequest();

        try {
            $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
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
    private function extractTargetCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $categories = [];

        foreach (self::TARGET_TOP_CATEGORIES as $expectedExternalId => $targetName) {
            $expectedExternalId = (string) $expectedExternalId;
            $rootAnchor = $this->findTargetRootAnchor($crawler, $expectedExternalId, $targetName);

            if (! $rootAnchor instanceof DOMElement) {
                continue;
            }

            $rootListItem = $this->closestListItem($rootAnchor);

            if (! $rootListItem instanceof DOMElement) {
                continue;
            }

            $this->collectCategoryBranch(
                listItem: $rootListItem,
                baseUrl: $baseUrl,
                expectedExternalId: $expectedExternalId,
                parent: null,
                path: [],
                topCategory: null,
                categories: $categories,
            );
        }

        return array_values($categories);
    }

    private function findTargetRootAnchor(Crawler $crawler, string $externalId, string $name): ?DOMElement
    {
        $byId = $crawler->filter('#headercategory'.$externalId)->first();
        $node = $byId->count() > 0 ? $byId->getNode(0) : null;

        if ($node instanceof DOMElement) {
            return $node;
        }

        $matched = null;

        $crawler->filter('a[href][title], a[id^="headercategory"][href]')->each(function (Crawler $anchor) use (&$matched, $name): void {
            if ($matched instanceof DOMElement) {
                return;
            }

            $label = $this->normalizeLabel((string) ($anchor->attr('title') ?: $anchor->text('')));

            if (mb_strtolower($label) !== mb_strtolower($name)) {
                return;
            }

            $node = $anchor->getNode(0);

            if ($node instanceof DOMElement) {
                $matched = $node;
            }
        });

        return $matched;
    }

    /**
     * @param  array<string, mixed>|null  $parent
     * @param  array<int, string>  $path
     * @param  array<string, mixed>|null  $topCategory
     * @param  array<string, array<string, mixed>>  $categories
     */
    private function collectCategoryBranch(
        DOMElement $listItem,
        string $baseUrl,
        ?string $expectedExternalId,
        ?array $parent,
        array $path,
        ?array $topCategory,
        array &$categories,
    ): void {
        $anchor = $this->firstDirectCategoryAnchor($listItem);

        if (! $anchor instanceof DOMElement) {
            return;
        }

        $category = $this->categoryFromAnchor(
            anchor: $anchor,
            listItem: $listItem,
            baseUrl: $baseUrl,
            expectedExternalId: $expectedExternalId,
            parent: $parent,
            path: $path,
            topCategory: $topCategory,
        );

        if ($category === null) {
            return;
        }

        $children = $this->directChildCategoryListItems($listItem);
        $category['has_children'] = $children !== [];
        $category['is_product_category'] = $children === [];
        $categories[(string) $category['url']] = $category;
        $resolvedTopCategory = $topCategory ?? $category;

        foreach ($children as $childListItem) {
            $this->collectCategoryBranch(
                listItem: $childListItem,
                baseUrl: $baseUrl,
                expectedExternalId: null,
                parent: $category,
                path: (array) $category['path'],
                topCategory: $resolvedTopCategory,
                categories: $categories,
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $parent
     * @param  array<int, string>  $path
     * @param  array<string, mixed>|null  $topCategory
     * @return array<string, mixed>|null
     */
    private function categoryFromAnchor(
        DOMElement $anchor,
        DOMElement $listItem,
        string $baseUrl,
        ?string $expectedExternalId,
        ?array $parent,
        array $path,
        ?array $topCategory,
    ): ?array {
        $url = $this->normalizeCategoryUrl($anchor->getAttribute('href'), $baseUrl);

        if ($url === null) {
            return null;
        }

        $name = $this->normalizeLabel(
            $anchor->getAttribute('title') !== ''
                ? $anchor->getAttribute('title')
                : (string) $anchor->textContent
        );

        if ($name === '') {
            $name = $this->labelFromUrl($url);
        }

        $externalCategoryId = $this->externalCategoryId($anchor, $listItem, $url) ?? $expectedExternalId;

        if ($name === '' || $externalCategoryId === null) {
            return null;
        }

        $categoryPath = [...$path, $name];
        $topCategoryName = $topCategory['name'] ?? $name;
        $topCategoryUrl = $topCategory['url'] ?? $url;
        $topCategoryExternalId = $topCategory['external_category_id'] ?? $externalCategoryId;

        return [
            'source' => 'vaya',
            'external_category_id' => $externalCategoryId,
            'name' => $name,
            'source_name' => $name,
            'url' => $url,
            'slug' => $this->slugFromUrl($url),
            'level' => count($categoryPath),
            'parent_external_category_id' => $parent['external_category_id'] ?? null,
            'parent_name' => $parent['name'] ?? null,
            'parent_url' => $parent['url'] ?? null,
            'top_category_external_id' => $topCategoryExternalId,
            'top_category_name' => $topCategoryName,
            'top_category_url' => $topCategoryUrl,
            'path' => $categoryPath,
            'product_count' => null,
        ];
    }

    private function closestListItem(DOMElement $element): ?DOMElement
    {
        $current = $element;

        while ($current instanceof DOMElement) {
            if (mb_strtolower($current->tagName) === 'li') {
                return $current;
            }

            $parent = $current->parentNode;
            $current = $parent instanceof DOMElement ? $parent : null;
        }

        return null;
    }

    private function firstDirectCategoryAnchor(DOMElement $listItem): ?DOMElement
    {
        foreach ($this->directChildElements($listItem) as $child) {
            if (mb_strtolower($child->tagName) === 'a' && $child->hasAttribute('href')) {
                return $child;
            }

            if (mb_strtolower($child->tagName) !== 'h3') {
                continue;
            }

            foreach ($this->directChildElements($child, 'a') as $anchor) {
                if ($anchor->hasAttribute('href')) {
                    return $anchor;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directChildCategoryListItems(DOMElement $listItem): array
    {
        $children = [];

        foreach ($this->directChildElements($listItem) as $child) {
            if (mb_strtolower($child->tagName) === 'ul') {
                $children = [...$children, ...$this->directChildElements($child, 'li')];

                continue;
            }

            if (mb_strtolower($child->tagName) !== 'div' || ! $this->elementHasClass($child, 'submenu')) {
                continue;
            }

            foreach ($this->directChildElements($child, 'ul') as $menu) {
                $children = [...$children, ...$this->directChildElements($menu, 'li')];
            }
        }

        return $children;
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

    private function elementHasClass(DOMElement $element, string $className): bool
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];

        return in_array($className, $classes, true);
    }

    private function externalCategoryId(DOMElement $anchor, DOMElement $listItem, string $url): ?string
    {
        foreach ([$anchor->getAttribute('id'), $listItem->getAttribute('id')] as $candidate) {
            if (preg_match('/(?:headercategory|hcategory_)(\d+)/', $candidate, $matches) === 1) {
                return $matches[1];
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/pl/c/[^/]+/(\d+)$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isVayaUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '/' || str_starts_with($path, '/pl/p/')) {
            return null;
        }

        return 'https://'.self::VAYA_HOST.$this->normalizePath($path);
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
            $url = 'https://'.self::VAYA_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! is_array($base) || ! isset($base['host'])) {
                return null;
            }

            $basePath = (string) ($base['path'] ?? '/');
            $baseDirectory = str_ends_with($basePath, '/') ? rtrim($basePath, '/') : dirname($basePath);
            $url = 'https://'.self::VAYA_HOST.'/'.trim($baseDirectory.'/'.$url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        return 'https://'.$host.$this->normalizePath((string) ($parts['path'] ?? '/'));
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function labelFromUrl(string $url): string
    {
        $slug = $this->slugFromUrl($url);

        return $slug === ''
            ? ''
            : mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if ($segments === []) {
            return '';
        }

        if (count($segments) >= 4 && $segments[0] === 'pl' && $segments[1] === 'c') {
            return (string) $segments[count($segments) - 2];
        }

        return (string) end($segments);
    }

    private function isVayaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::VAYA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host === 'vaya.com.pl' ? self::VAYA_HOST : null;
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopVayaScraper/1.0; +https://konji.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
