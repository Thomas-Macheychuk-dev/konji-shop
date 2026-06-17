<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class Reh4MatCategoryScraper
{
    public const DEFAULT_CATEGORY_URL = 'https://www.reh4mat.com/produkt/';

    private const REH4MAT_HOSTS = [
        'reh4mat.com',
        'www.reh4mat.com',
    ];

    /**
     * Only these top catalogue categories and their descendants are relevant for Konji Shop.
     * The values are the canonical root names written to JSON/output.
     *
     * @var array<string, string>
     */
    private const ALLOWED_ROOT_CATEGORY_NAMES = [
        'konczyna dolna' => 'KOŃCZYNA DOLNA',
        'konczyna gorna' => 'KOŃCZYNA GÓRNA',
        'kregoslup' => 'KRĘGOSŁUP',
        'tulow' => 'TUŁÓW',
        'miednica' => 'MIEDNICA',
        'ortezy pediatryczne' => 'ORTEZY PEDIATRYCZNE',
        'ortezy i akcesoria pediatryczne' => 'ORTEZY PEDIATRYCZNE',
        'pionizacja' => 'PIONIZACJA',
        'stabilizacja' => 'STABILIZACJA',
        'wyroby przeciwodlezynowe' => 'WYROBY PRZECIWODLEŻYNOWE',
        'pozostale wyroby medyczne' => 'POZOSTAŁE WYROBY MEDYCZNE',
        'akcesoria' => 'AKCESORIA',
    ];

    /** @var array<int, string> */
    private const OUTPUT_ROOT_CATEGORY_ORDER = [
        'KOŃCZYNA DOLNA',
        'KOŃCZYNA GÓRNA',
        'KRĘGOSŁUP',
        'TUŁÓW',
        'MIEDNICA',
        'ORTEZY PEDIATRYCZNE',
        'PIONIZACJA',
        'STABILIZACJA',
        'WYROBY PRZECIWODLEŻYNOWE',
        'POZOSTAŁE WYROBY MEDYCZNE',
        'AKCESORIA',
    ];

    /** @var array<int, string> */
    private const EXCLUDED_CATEGORY_NAMES = [
        'katalog',
        'wyroby na zamowienie',
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
     *     skipped_categories: array<int, array<string, string>>,
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_CATEGORY_URL]): array
    {
        $visited = [];
        $failed = [];
        $skipped = [];
        $candidateRootsByCanonicalName = [];
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
            $this->emit('Fetching Reh4Mat category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractRootCategoryTree($html, $url, $skipped) as $rootCategory) {
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
            'source' => 'reh4mat',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $flatCategories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $productCategoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
            'skipped_categories' => $skipped,
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
     * @param  array<int, array<string, string>>  $skipped
     * @return array<int, array<string, mixed>>
     */
    private function extractRootCategoryTree(string $html, string $baseUrl, array &$skipped): array
    {
        $document = new DOMDocument;

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $xpath = new DOMXPath($document);
        $rootUrlsByCanonicalName = $this->extractFrontMenuRootUrls($xpath, $baseUrl, $skipped);
        $roots = [];

        $productMenus = $xpath->query($this->productDropdownRootXPath());

        if ($productMenus !== false && $productMenus->length > 0) {
            foreach ($productMenus as $productMenu) {
                if (! $productMenu instanceof DOMElement) {
                    continue;
                }

                foreach ($this->directListItems($productMenu) as $item) {
                    $category = $this->rootCategoryFromListItem($item, $baseUrl, $rootUrlsByCanonicalName, $skipped);

                    if ($category !== null) {
                        $roots[] = $category;
                    }
                }
            }
        }

        if ($roots !== []) {
            return $roots;
        }

        foreach (self::OUTPUT_ROOT_CATEGORY_ORDER as $canonicalName) {
            if (! isset($rootUrlsByCanonicalName[$canonicalName])) {
                continue;
            }

            $roots[] = $this->buildCategory(
                name: $canonicalName,
                sourceName: $canonicalName,
                url: $rootUrlsByCanonicalName[$canonicalName],
                parentExternalCategoryId: null,
                parentPath: [],
                level: 1,
                externalCategoryId: $this->externalCategoryIdFromUrl($rootUrlsByCanonicalName[$canonicalName]),
                children: [],
            );
        }

        return $roots;
    }

    /**
     * @param  array<int, array<string, string>>  $skipped
     * @return array<string, string>
     */
    private function extractFrontMenuRootUrls(DOMXPath $xpath, string $baseUrl, array &$skipped): array
    {
        $rootUrls = [];
        $anchors = $xpath->query('//*[@id="front-menu"]//a[.//*[contains(concat(" ", normalize-space(@class), " "), " main-menu-desc ")]]');

        if ($anchors === false) {
            return [];
        }

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $name = $this->textContent($anchor);
            $canonicalName = $this->canonicalAllowedRootName($name);

            if ($canonicalName === null) {
                if ($this->isExcludedCategoryName($name)) {
                    $skipped[] = [
                        'name' => $name,
                        'url' => $this->normalizeUrl($anchor->getAttribute('href'), $baseUrl) ?? $anchor->getAttribute('href'),
                        'reason' => 'excluded_category',
                    ];
                }

                continue;
            }

            $url = $this->normalizeUrl($anchor->getAttribute('href'), $baseUrl);

            if ($url === null || ! $this->isReh4MatUrl($url)) {
                continue;
            }

            $rootUrls[$canonicalName] = $url;
        }

        return $rootUrls;
    }

    private function productDropdownRootXPath(): string
    {
        return '//*[@id="menu-glowne-menu"]//li[contains(concat(" ", normalize-space(@class), " "), " produktycssmenu ")]'
            .'/ul[contains(concat(" ", normalize-space(@class), " "), " sub-menu ")]';
    }

    /**
     * @param  array<string, string>  $rootUrlsByCanonicalName
     * @param  array<int, array<string, string>>  $skipped
     * @return array<string, mixed>|null
     */
    private function rootCategoryFromListItem(
        DOMElement $item,
        string $baseUrl,
        array $rootUrlsByCanonicalName,
        array &$skipped,
    ): ?array {
        $anchor = $this->directAnchor($item);

        if (! $anchor instanceof DOMElement) {
            return null;
        }

        $sourceName = $this->textContent($anchor);
        $canonicalName = $this->canonicalAllowedRootName($sourceName);
        $rawUrl = $anchor->getAttribute('href');
        $url = $this->normalizeUrl($rawUrl, $baseUrl);

        if ($canonicalName === null) {
            if ($this->isExcludedCategoryName($sourceName)) {
                $skipped[] = [
                    'name' => $sourceName,
                    'url' => $url ?? $rawUrl,
                    'reason' => 'excluded_category',
                ];
            }

            return null;
        }

        if ($url === null || ! $this->isReh4MatUrl($url)) {
            $url = $rootUrlsByCanonicalName[$canonicalName] ?? null;
        }

        if ($url === null) {
            return null;
        }

        $externalCategoryId = $this->externalCategoryIdFromListItem($item) ?? $this->externalCategoryIdFromUrl($url);
        $children = [];

        foreach ($this->directSubmenus($item) as $submenu) {
            foreach ($this->directListItems($submenu) as $childItem) {
                $child = $this->childCategoryFromListItem(
                    item: $childItem,
                    baseUrl: $baseUrl,
                    parentExternalCategoryId: $externalCategoryId,
                    parentPath: [$canonicalName],
                    skipped: $skipped,
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
     * @param  array<int, array<string, string>>  $skipped
     * @return array<string, mixed>|null
     */
    private function childCategoryFromListItem(
        DOMElement $item,
        string $baseUrl,
        ?string $parentExternalCategoryId,
        array $parentPath,
        array &$skipped,
    ): ?array {
        $anchor = $this->directAnchor($item);

        if (! $anchor instanceof DOMElement) {
            return null;
        }

        $name = $this->textContent($anchor);
        $rawUrl = $anchor->getAttribute('href');
        $url = $this->normalizeUrl($rawUrl, $baseUrl);

        if ($this->isExcludedCategoryName($name)) {
            $skipped[] = [
                'name' => $name,
                'url' => $url ?? $rawUrl,
                'reason' => 'excluded_category',
            ];

            return null;
        }

        if ($url === null || ! $this->isReh4MatUrl($url)) {
            $skipped[] = [
                'name' => $name,
                'url' => $url ?? $rawUrl,
                'reason' => 'external_or_invalid_url',
            ];

            return null;
        }

        $externalCategoryId = $this->externalCategoryIdFromListItem($item) ?? $this->externalCategoryIdFromUrl($url);
        $children = [];

        foreach ($this->directSubmenus($item) as $submenu) {
            foreach ($this->directListItems($submenu) as $grandChildItem) {
                $grandChild = $this->childCategoryFromListItem(
                    item: $grandChildItem,
                    baseUrl: $baseUrl,
                    parentExternalCategoryId: $externalCategoryId,
                    parentPath: [...$parentPath, $name],
                    skipped: $skipped,
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
            'source' => 'reh4mat',
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
    private function directListItems(DOMElement $list): array
    {
        $items = [];

        foreach ($list->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || mb_strtolower($childNode->tagName) !== 'li') {
                continue;
            }

            $items[] = $childNode;
        }

        return $items;
    }

    private function directAnchor(DOMElement $item): ?DOMElement
    {
        foreach ($item->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement) {
                continue;
            }

            if (mb_strtolower($childNode->tagName) === 'a') {
                return $childNode;
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directSubmenus(DOMElement $item): array
    {
        $submenus = [];

        foreach ($item->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || mb_strtolower($childNode->tagName) !== 'ul') {
                continue;
            }

            if (! $this->hasClass($childNode, 'sub-menu')) {
                continue;
            }

            $submenus[] = $childNode;
        }

        return $submenus;
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
     * @param  array<int, array<string, mixed>>  $topCategories
     * @return array<int, array<string, mixed>>
     */
    private function flattenCategories(array $topCategories): array
    {
        $flat = [];

        foreach ($topCategories as $category) {
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

    private function isExcludedCategoryName(string $name): bool
    {
        return in_array($this->normalizeComparableName($name), self::EXCLUDED_CATEGORY_NAMES, true);
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
        return $this->text($element->textContent ?? '');
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeUrl(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || $href === '#') {
            return null;
        }

        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? 'www.reh4mat.com';

        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }

        $path = $base['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        if ($directory === '') {
            $directory = '/';
        }

        return $scheme.'://'.$host.rtrim($directory, '/').'/'.ltrim($href, '/');
    }

    private function isReh4MatUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::REH4MAT_HOSTS, true);
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

        if (preg_match('/menu-item-(\d+)/', $id, $matches) !== 1) {
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopReh4MatScraper/1.0; +https://konji.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
