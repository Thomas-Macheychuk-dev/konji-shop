<?php

declare(strict_types=1);

namespace App\Services\MedReha;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MedRehaCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://sklep.medreha.pl/pl/c/';

    private const MEDREHA_HOST = 'sklep.medreha.pl';

    /**
     * Only these catalogue roots and their descendants are relevant for Konji Shop.
     * The values are the canonical root names written to JSON/output.
     *
     * @var array<string, string>
     */
    private const ALLOWED_ROOT_CATEGORY_NAMES = [
        'ortezy i stabilizatory' => 'ORTEZY I STABILIZATORY',
        'sprzet rehabilitacyjny' => 'SPRZĘT REHABILITACYJNY',
        'sprzet medyczny' => 'SPRZĘT MEDYCZNY',
        'sprzet pomocniczy dla osob starszych' => 'SPRZĘT POMOCNICZY DLA OSÓB STARSZYCH',
        'sprzet sportowy' => 'SPRZĘT SPORTOWY',
    ];

    /** @var array<int, string> */
    private const OUTPUT_ROOT_CATEGORY_ORDER = [
        'ORTEZY I STABILIZATORY',
        'SPRZĘT REHABILITACYJNY',
        'SPRZĘT MEDYCZNY',
        'SPRZĘT POMOCNICZY DLA OSÓB STARSZYCH',
        'SPRZĘT SPORTOWY',
    ];

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
     * Discover MedReha category hierarchy from the Shoper menu.
     *
     * `category_urls` contains every allowed root/lower category URL.
     * `product_category_urls` contains leaf category URLs that should be used later for product-link scraping.
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
        $candidateRootsByCanonicalName = [];
        $normalizedStartUrls = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isMedRehaUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching MedReha category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractRootCategoryTree($html, $url) as $rootCategory) {
                $candidateRootsByCanonicalName[(string) $rootCategory['name']][] = $rootCategory;
            }
        }

        $topCategories = $this->selectAllowedRootCategories($candidateRootsByCanonicalName);
        $flatCategories = $this->flattenCategories($topCategories);
        $categoryUrls = array_values(array_unique(array_map(
            static fn (array $category): string => (string) $category['url'],
            $flatCategories,
        )));
        $productCategoryUrls = $this->collectLeafCategoryUrls($topCategories);

        return [
            'source' => 'medreha',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $flatCategories,
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
                $firstLevelList = $this->firstSubmenuList($menuItem, 'level1');

                if ($firstLevelList === null) {
                    continue;
                }

                foreach ($this->directChildElements($firstLevelList, 'li') as $rootItem) {
                    $root = $this->rootCategoryFromListItem($rootItem, $baseUrl);

                    if ($root !== null) {
                        $roots[] = $root;
                    }
                }
            }
        });

        return $roots;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rootCategoryFromListItem(DOMElement $item, string $baseUrl): ?array
    {
        $anchor = $this->firstHeadingAnchor($item);

        if (! $anchor instanceof DOMElement) {
            return null;
        }

        $sourceName = $this->textContent($anchor);
        $canonicalName = $this->canonicalAllowedRootName($sourceName);

        if ($canonicalName === null) {
            return null;
        }

        $url = $this->normalizeCategoryUrl($anchor->getAttribute('href'), $baseUrl);

        if ($url === null) {
            return null;
        }

        $externalCategoryId = $this->externalCategoryIdFromListItem($item) ?? $this->externalCategoryIdFromUrl($url);
        $children = [];

        foreach ($this->directSubmenuLists($item) as $submenu) {
            foreach ($this->directChildElements($submenu, 'li') as $childItem) {
                $child = $this->childCategoryFromListItem(
                    item: $childItem,
                    baseUrl: $baseUrl,
                    parentExternalCategoryId: $externalCategoryId,
                    parentPath: [$canonicalName],
                );

                if ($child !== null) {
                    $children[] = $child;
                }
            }
        }

        return $this->buildCategory(
            name: $canonicalName,
            sourceName: $sourceName,
            url: $url,
            parentExternalCategoryId: null,
            parentPath: [],
            level: 1,
            externalCategoryId: $externalCategoryId,
            children: $children,
        );
    }

    /**
     * @param  array<int, string>  $parentPath
     * @return array<string, mixed>|null
     */
    private function childCategoryFromListItem(
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
            foreach ($this->directChildElements($submenu, 'li') as $grandChildItem) {
                $grandChild = $this->childCategoryFromListItem(
                    item: $grandChildItem,
                    baseUrl: $baseUrl,
                    parentExternalCategoryId: $externalCategoryId,
                    parentPath: [...$parentPath, $name],
                );

                if ($grandChild !== null) {
                    $children[] = $grandChild;
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
            'source' => 'medreha',
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
        foreach ($this->directChildElements($item, 'h3') as $heading) {
            foreach ($this->directChildElements($heading, 'a') as $anchor) {
                if ($anchor->hasAttribute('href')) {
                    return $anchor;
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

    private function firstSubmenuList(DOMElement $item, string $levelClass): ?DOMElement
    {
        foreach ($this->directSubmenuLists($item) as $list) {
            if ($this->hasClass($list, $levelClass)) {
                return $list;
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
     * @param  array<string, array<int, array<string, mixed>>>  $candidateRootsByCanonicalName
     * @return array<int, array<string, mixed>>
     */
    private function selectAllowedRootCategories(array $candidateRootsByCanonicalName): array
    {
        $selected = [];

        foreach (self::OUTPUT_ROOT_CATEGORY_ORDER as $canonicalName) {
            $candidates = $candidateRootsByCanonicalName[$canonicalName] ?? [];

            if ($candidates === []) {
                continue;
            }

            usort($candidates, static function (array $left, array $right): int {
                return count($right['children'] ?? []) <=> count($left['children'] ?? []);
            });

            $selected[] = $candidates[0];
        }

        return $selected;
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
     * @return array<int, string>
     */
    private function collectLeafCategoryUrls(array $categories): array
    {
        $urls = [];

        foreach ($categories as $category) {
            $children = $category['children'] ?? [];

            if (is_array($children) && $children !== []) {
                array_push($urls, ...$this->collectLeafCategoryUrls($children));

                continue;
            }

            $urls[] = (string) $category['url'];
        }

        return array_values(array_unique($urls));
    }

    private function canonicalAllowedRootName(string $name): ?string
    {
        return self::ALLOWED_ROOT_CATEGORY_NAMES[$this->normalizeComparableName($name)] ?? null;
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

        if ($url === null || ! $this->isMedRehaUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if ($path === '/') {
            return null;
        }

        return 'https://'.self::MEDREHA_HOST.$path;
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
            $url = 'https://'.self::MEDREHA_HOST.$url;
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

        return 'https://'.$host.$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function isMedRehaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::MEDREHA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            'medreha.pl', self::MEDREHA_HOST => self::MEDREHA_HOST,
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

        return $matches[1];
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMedRehaScraper/1.0; +https://konji.pl)',
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
