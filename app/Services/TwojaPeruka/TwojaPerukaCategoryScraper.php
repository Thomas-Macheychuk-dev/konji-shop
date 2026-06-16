<?php

declare(strict_types=1);

namespace App\Services\TwojaPeruka;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TwojaPerukaCategoryScraper
{
    public const DEFAULT_CATEGORY_URL = 'https://twojaperuka.pl/peruki';

    private const TWOJAPERUKA_HOST = 'twojaperuka.pl';

    /**
     * Only these root categories and their descendants are relevant for Konji Shop import.
     * The order here is the output order used by the console command and JSON payload.
     */
    private const ALLOWED_ROOT_CATEGORY_NAMES = [
        'Zagęszczanie włosów',
        'Peruki',
        'Toppery',
        'Turbany i chusty',
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
    public function scrape(array $startUrls = [self::DEFAULT_CATEGORY_URL]): array
    {
        $visited = [];
        $failed = [];
        $candidateRootsByName = [];
        $normalizedStartUrls = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_CATEGORY_URL);

            if ($url === null) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching TwojaPeruka category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractRootCategoryTree($html, $url) as $rootCategory) {
                $normalizedName = $this->normalizeComparableName((string) $rootCategory['name']);

                if (! $this->isAllowedRootCategoryName($normalizedName)) {
                    continue;
                }

                $candidateRootsByName[$normalizedName][] = $rootCategory;
            }
        }

        $topCategories = $this->selectAllowedRootCategories($candidateRootsByName);
        $flatCategories = $this->flattenCategories($topCategories);
        $categoryUrls = array_values(array_unique(array_map(
            static fn (array $category): string => (string) $category['url'],
            $flatCategories,
        )));
        $productCategoryUrls = $this->collectLeafCategoryUrls($topCategories);

        return [
            'source' => 'twojaperuka',
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
        $document = new DOMDocument;

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $xpath = new DOMXPath($document);
        $rootMenus = $xpath->query($this->rootMenuXPath());

        if ($rootMenus === false || $rootMenus->length === 0) {
            return [];
        }

        $roots = [];

        foreach ($rootMenus as $rootMenu) {
            if (! $rootMenu instanceof DOMElement) {
                continue;
            }

            foreach ($this->directCategoryItems($rootMenu) as $item) {
                $category = $this->categoryFromListItem($item, $baseUrl, null, [], 1);

                if ($category === null) {
                    continue;
                }

                $roots[] = $category;
            }
        }

        return $roots;
    }

    private function rootMenuXPath(): string
    {
        return '//*[contains(concat(" ", normalize-space(@class), " "), " sft-sidebar-menu ")]'
            .'//ul[contains(concat(" ", normalize-space(@class), " "), " sft-category-menu--level-1 ")]';
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directCategoryItems(DOMElement $list): array
    {
        $items = [];

        foreach ($list->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || mb_strtolower($childNode->tagName) !== 'li') {
                continue;
            }

            if (! $this->hasClass($childNode, 'sft-category-menu__item')) {
                continue;
            }

            $items[] = $childNode;
        }

        return $items;
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
        int $level,
    ): ?array {
        $anchor = $this->directCategoryAnchor($item);

        if (! $anchor instanceof DOMElement) {
            return null;
        }

        $href = $anchor->getAttribute('href');
        $url = $this->normalizeUrl($href, $baseUrl);

        if ($url === null || ! $this->isTwojaPerukaUrl($url)) {
            return null;
        }

        $name = $this->categoryNameFromAnchor($anchor);

        if ($name === '') {
            return null;
        }

        $externalCategoryId = $this->externalCategoryIdFromListItem($item) ?? $this->externalCategoryIdFromUrl($url);
        $path = [...$parentPath, $name];
        $children = [];

        foreach ($this->directSubcategoryMenus($item) as $submenu) {
            foreach ($this->directCategoryItems($submenu) as $childItem) {
                $child = $this->categoryFromListItem($childItem, $baseUrl, $externalCategoryId, $path, $level + 1);

                if ($child !== null) {
                    $children[] = $child;
                }
            }
        }

        return [
            'external_category_id' => $externalCategoryId,
            'name' => $name,
            'url' => $url,
            'slug' => $this->slugFromUrl($url),
            'level' => $level,
            'parent_external_category_id' => $parentExternalCategoryId,
            'path' => $path,
            'children' => $this->deduplicateSiblingCategories($children),
        ];
    }

    private function directCategoryAnchor(DOMElement $item): ?DOMElement
    {
        foreach ($item->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || ! $this->hasClass($childNode, 'sft-category-link')) {
                continue;
            }

            foreach ($childNode->getElementsByTagName('a') as $anchor) {
                if ($anchor instanceof DOMElement && $anchor->hasAttribute('href')) {
                    return $anchor;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directSubcategoryMenus(DOMElement $item): array
    {
        $menus = [];

        foreach ($item->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || mb_strtolower($childNode->tagName) !== 'ul') {
                continue;
            }

            if (! $this->hasClass($childNode, 'sft-subcategory-menu')) {
                continue;
            }

            $menus[] = $childNode;
        }

        return $menus;
    }

    private function categoryNameFromAnchor(DOMElement $anchor): string
    {
        $title = $this->normalizeLabel($anchor->getAttribute('title'));

        if ($title !== '') {
            return $title;
        }

        foreach ($anchor->getElementsByTagName('p') as $paragraph) {
            if ($paragraph instanceof DOMElement && $this->hasClass($paragraph, 'head')) {
                return $this->normalizeLabel($paragraph->textContent ?? '');
            }
        }

        return $this->normalizeLabel($anchor->textContent ?? '');
    }

    private function externalCategoryIdFromListItem(DOMElement $item): ?string
    {
        $id = $item->getAttribute('id');

        if (preg_match('/^sft-category-item-(\d+)$/', $id, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function externalCategoryIdFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/(\d+)/?$#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $candidateRootsByName
     * @return array<int, array<string, mixed>>
     */
    private function selectAllowedRootCategories(array $candidateRootsByName): array
    {
        $selected = [];

        foreach (self::ALLOWED_ROOT_CATEGORY_NAMES as $allowedName) {
            $normalizedAllowedName = $this->normalizeComparableName($allowedName);
            $candidates = $candidateRootsByName[$normalizedAllowedName] ?? [];

            if ($candidates === []) {
                continue;
            }

            usort($candidates, function (array $first, array $second): int {
                return $this->descendantCount($second) <=> $this->descendantCount($first);
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
        $flattened = [];

        foreach ($categories as $category) {
            $children = is_array($category['children'] ?? null) ? $category['children'] : [];
            $flatCategory = $category;
            unset($flatCategory['children']);
            $flattened[] = $flatCategory;

            foreach ($this->flattenCategories($children) as $childCategory) {
                $flattened[] = $childCategory;
            }
        }

        return $this->deduplicateFlatCategories($flattened);
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, string>
     */
    private function collectLeafCategoryUrls(array $categories): array
    {
        $urls = [];

        foreach ($categories as $category) {
            $children = is_array($category['children'] ?? null) ? $category['children'] : [];

            if ($children === []) {
                $urls[] = (string) $category['url'];

                continue;
            }

            foreach ($this->collectLeafCategoryUrls($children) as $childUrl) {
                $urls[] = $childUrl;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSiblingCategories(array $categories): array
    {
        $deduplicated = [];

        foreach ($categories as $category) {
            $key = $this->categoryDeduplicationKey($category);
            $existing = $deduplicated[$key] ?? null;

            if ($existing === null || $this->descendantCount($category) > $this->descendantCount($existing)) {
                $deduplicated[$key] = $category;
            }
        }

        return array_values($deduplicated);
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateFlatCategories(array $categories): array
    {
        $deduplicated = [];

        foreach ($categories as $category) {
            $key = $this->categoryDeduplicationKey($category);
            $deduplicated[$key] = $category;
        }

        return array_values($deduplicated);
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function categoryDeduplicationKey(array $category): string
    {
        $externalCategoryId = $category['external_category_id'] ?? null;

        if (is_string($externalCategoryId) && $externalCategoryId !== '') {
            return 'id:'.$externalCategoryId;
        }

        return 'url:'.(string) $category['url'];
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function descendantCount(array $category): int
    {
        $children = is_array($category['children'] ?? null) ? $category['children'] : [];
        $count = count($children);

        foreach ($children as $child) {
            if (is_array($child)) {
                $count += $this->descendantCount($child);
            }
        }

        return $count;
    }

    private function isAllowedRootCategoryName(string $normalizedName): bool
    {
        foreach (self::ALLOWED_ROOT_CATEGORY_NAMES as $allowedName) {
            if ($this->normalizeComparableName($allowedName) === $normalizedName) {
                return true;
            }
        }

        return false;
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
            $url = 'https://'.self::TWOJAPERUKA_HOST.$url;
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

        $path = $this->normalizePath($parts['path'] ?? '/');

        return 'https://'.mb_strtolower((string) $parts['host']).$path;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function isTwojaPerukaUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === self::TWOJAPERUKA_HOST || $host === 'www.'.self::TWOJAPERUKA_HOST;
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if ($segments === []) {
            return '';
        }

        $lastSegment = (string) end($segments);

        if (preg_match('/^\d+$/', $lastSegment) === 1 && count($segments) >= 2) {
            return (string) $segments[count($segments) - 2];
        }

        return $lastSegment;
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function normalizeComparableName(string $name): string
    {
        return mb_strtolower($this->normalizeLabel($name), 'UTF-8');
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        $classes = ' '.$this->normalizeLabel($element->getAttribute('class')).' ';

        return str_contains($classes, ' '.$class.' ');
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
}
