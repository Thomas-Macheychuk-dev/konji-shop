<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

final class Reh4MatProductUrlScraper
{
    private const REH4MAT_HOST = 'www.reh4mat.com';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly Reh4MatCategoryScraper $categoryScraper,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;
        $this->categoryScraper->withProgressCallback($callback);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);
        $this->categoryScraper->withTimeout($this->timeoutSeconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    /**
     * Convenience wrapper for callers/tests that only need product URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [Reh4MatCategoryScraper::DEFAULT_CATEGORY_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * Discover product links from the allowed Reh4Mat category hierarchy.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     source: string,
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int, pages_scraped: int}>,
     *     source_categories: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>,
     *     category_discovery: array<string, mixed>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $startUrls = [Reh4MatCategoryScraper::DEFAULT_CATEGORY_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $this->emit('Discovering Reh4Mat category hierarchy...');
        $categoryDiscovery = $this->categoryScraper->scrape($startUrls);
        $this->emit('Product-scraping categories found: '.count($categoryDiscovery['product_category_urls'] ?? []));

        return $this->scrapeFromDiscoveredCategories($categoryDiscovery, $pageLimit, $categoryLimit);
    }

    /**
     * Scrape product links from an existing Reh4Mat category discovery JSON payload.
     *
     * @param  array<string, mixed>  $categoryDiscovery
     * @return array{
     *     source: string,
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int, pages_scraped: int}>,
     *     source_categories: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>,
     *     category_discovery: array<string, mixed>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrapeFromDiscoveredCategories(array $categoryDiscovery, ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        $categories = $this->categoryRecordsFromDiscoveryResult($categoryDiscovery);

        return $this->scrapeCategoryRecords(
            $categories,
            $this->stringList($categoryDiscovery['visited_urls'] ?? []),
            $this->stringMap($categoryDiscovery['failed_urls'] ?? []),
            $pageLimit,
            $categoryLimit,
            $categoryDiscovery,
        );
    }

    /**
     * Scrape product links from explicit Reh4Mat category URLs without running category discovery first.
     * Useful for testing one category before running all categories.
     *
     * @param  array<int, string>  $categoryUrls
     * @return array{
     *     source: string,
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int, pages_scraped: int}>,
     *     source_categories: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>,
     *     category_discovery: array<string, mixed>|null,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrapeCategories(array $categoryUrls, ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        $categories = [];

        foreach ($categoryUrls as $categoryUrl) {
            $url = $this->normalizeCategoryPageUrl($categoryUrl);

            if ($url === null) {
                continue;
            }

            $categories[$url] = [
                'name' => $this->labelFromUrl($url),
                'url' => $url,
                'top_category_name' => null,
                'top_category_url' => null,
                'path' => [],
            ];
        }

        return $this->scrapeCategoryRecords(array_values($categories), [], [], $pageLimit, $categoryLimit, null);
    }

    /**
     * @param  array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>  $categories
     * @param  array<int, string>  $initialVisitedUrls
     * @param  array<string, string>  $initialFailedUrls
     * @param  array<string, mixed>|null  $categoryDiscovery
     * @return array{
     *     source: string,
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, category_path: array<int, string>, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int, pages_scraped: int}>,
     *     source_categories: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>,
     *     category_discovery: array<string, mixed>|null,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    private function scrapeCategoryRecords(
        array $categories,
        array $initialVisitedUrls,
        array $initialFailedUrls,
        ?int $pageLimit,
        ?int $categoryLimit,
        ?array $categoryDiscovery,
    ): array {
        $visited = array_fill_keys($initialVisitedUrls, true);
        $failed = $initialFailedUrls;
        $products = [];
        $categoryResults = [];

        $totalCategories = count($categories);
        $categoryNumber = 0;

        foreach ($categories as $category) {
            if ($categoryLimit !== null && $categoryNumber >= $categoryLimit) {
                $this->emit('Category limit reached: '.$categoryLimit);
                break;
            }

            $categoryNumber++;
            $categoryUrl = $this->normalizeCategoryPageUrl($category['url']);

            if ($categoryUrl === null) {
                continue;
            }

            $categoryName = $category['name'] !== null && $category['name'] !== ''
                ? $category['name']
                : $this->labelFromUrl($categoryUrl);

            $this->emit('Scraping category '.$categoryNumber.'/'.$totalCategories.': '.$categoryName);
            $this->emit('  '.$categoryUrl);

            $categoryPageUrls = [];
            $categoryProductUrls = [];
            $nextUrl = $categoryUrl;
            $pagesScraped = 0;

            while ($nextUrl !== null && ! isset($categoryPageUrls[$nextUrl])) {
                if ($pageLimit !== null && $pagesScraped >= $pageLimit) {
                    break;
                }

                $categoryPageUrls[$nextUrl] = true;
                $visited[$nextUrl] = true;
                $pagesScraped++;

                $this->emit('  Fetching page '.$pagesScraped.': '.$nextUrl);
                $html = $this->fetchBody($nextUrl, $failed);

                if ($html === null) {
                    break;
                }

                $pageProducts = $this->extractProducts($html, $nextUrl);
                $this->emit('  Page '.$pagesScraped.' product links: '.count($pageProducts));

                $newProductUrlsOnPage = 0;

                foreach ($pageProducts as $product) {
                    $url = $product['url'];
                    $isNewForCategory = ! isset($categoryProductUrls[$url]);
                    $categoryProductUrls[$url] = true;

                    if ($isNewForCategory) {
                        $newProductUrlsOnPage++;
                    }

                    if (! isset($products[$url])) {
                        $products[$url] = [
                            'url' => $url,
                            'name' => $product['name'],
                            'category_name' => $categoryName,
                            'category_url' => $categoryUrl,
                            'top_category_name' => $category['top_category_name'] ?? $categoryName,
                            'top_category_url' => $category['top_category_url'] ?? $categoryUrl,
                            'category_path' => $category['path'],
                        ];

                        continue;
                    }

                    if ($products[$url]['name'] === '' && $product['name'] !== '') {
                        $products[$url]['name'] = $product['name'];
                    }
                }

                if ($pageProducts === []) {
                    $this->emit('  Stopping pagination: page has no product links.');
                    break;
                }

                if ($newProductUrlsOnPage === 0) {
                    $this->emit('  Stopping pagination: page added no new product links.');
                    break;
                }

                $nextUrl = $this->extractNextCategoryPageUrl($html, $nextUrl);
            }

            $this->emit('  Category total product links: '.count($categoryProductUrls));

            $categoryResults[] = [
                'name' => $categoryName,
                'url' => $categoryUrl,
                'top_category_name' => $category['top_category_name'] ?? $categoryName,
                'top_category_url' => $category['top_category_url'] ?? $categoryUrl,
                'category_path' => $category['path'],
                'visited_urls' => array_keys($categoryPageUrls),
                'product_urls' => array_keys($categoryProductUrls),
                'product_count' => count($categoryProductUrls),
                'pages_scraped' => $pagesScraped,
            ];
        }

        return [
            'source' => 'reh4mat',
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'category_results' => $categoryResults,
            'source_categories' => $categories,
            'category_discovery' => $categoryDiscovery,
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
     * @return array<int, array{url: string, name: string}>
     */
    private function extractProducts(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);

        if (! $xpath instanceof DOMXPath) {
            return [];
        }

        $queries = [
            '//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " child_page ")]//a[@href and contains(concat(" ", normalize-space(@class), " "), " childpagea ")]',
            '//*[@id="content"]//a[@href and contains(concat(" ", normalize-space(@rel), " "), " bookmark ")]',
            '//article//a[@href and contains(concat(" ", normalize-space(@rel), " "), " bookmark ")]',
            '//*[@id="content"]//h2/a[@href]',
            '//article//h2/a[@href]',
        ];

        $products = [];

        foreach ($queries as $query) {
            $anchors = $xpath->query($query);

            if ($anchors === false || $anchors->length === 0) {
                continue;
            }

            foreach ($anchors as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }

                $url = $this->normalizeProductUrl($anchor->getAttribute('href'), $baseUrl);

                if ($url === null) {
                    continue;
                }

                $name = $this->productNameFromAnchor($anchor);

                if (! isset($products[$url])) {
                    $products[$url] = [
                        'url' => $url,
                        'name' => $name,
                    ];

                    continue;
                }

                if ($products[$url]['name'] === '' && $name !== '') {
                    $products[$url]['name'] = $name;
                }
            }

            if ($products !== []) {
                break;
            }
        }

        return array_values($products);
    }

    private function productNameFromAnchor(DOMElement $anchor): string
    {
        $title = $this->normalizeLabel($anchor->getAttribute('title'));

        if ($title !== '') {
            return $title;
        }

        foreach ($anchor->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $alt = $this->normalizeLabel($image->getAttribute('alt'));

            if ($alt !== '') {
                return $alt;
            }
        }

        $cardHeading = $this->productNameFromCardHeading($anchor);

        if ($cardHeading !== '') {
            return $cardHeading;
        }

        return $this->normalizeLabel($anchor->textContent ?? '');
    }

    private function productNameFromCardHeading(DOMElement $anchor): string
    {
        $node = $anchor->parentNode;

        while ($node instanceof DOMElement) {
            if (str_contains(' '.$node->getAttribute('class').' ', ' child_page-container ')
                || str_contains(' '.$node->getAttribute('class').' ', ' child_page ')) {
                foreach ($node->getElementsByTagName('h4') as $heading) {
                    if (! $heading instanceof DOMElement) {
                        continue;
                    }

                    $name = $this->normalizeLabel($heading->textContent ?? '');

                    if ($name !== '') {
                        return $name;
                    }
                }
            }

            $node = $node->parentNode;
        }

        return '';
    }

    private function extractNextCategoryPageUrl(string $html, string $baseUrl): ?string
    {
        $xpath = $this->xpath($html);

        if (! $xpath instanceof DOMXPath) {
            return null;
        }

        $queries = [
            '//link[@href and contains(concat(" ", translate(normalize-space(@rel), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " next ")]',
            '//*[@id="content"]//a[@href and contains(concat(" ", normalize-space(@class), " "), " next ")]',
            '//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " nav-previous ")]//a[@href]',
            '//*[@id="content"]//a[@href and contains(normalize-space(.), "Starsze")]',
            '//*[@id="content"]//a[@href and contains(normalize-space(.), "Następ")]',
            '//*[@id="content"]//a[@href and contains(@aria-label, "Następ")]',
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $nextUrl = $this->normalizeCategoryPageUrl($node->getAttribute('href'), $baseUrl);

                if ($nextUrl !== null && $nextUrl !== $this->normalizeCategoryPageUrl($baseUrl)) {
                    return $nextUrl;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $categoryDiscovery
     * @return array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>
     */
    private function categoryRecordsFromDiscoveryResult(array $categoryDiscovery): array
    {
        $leafUrls = $this->stringList($categoryDiscovery['product_category_urls'] ?? []);
        $categories = is_array($categoryDiscovery['categories'] ?? null) ? $categoryDiscovery['categories'] : [];
        $categoriesByUrl = [];
        $rootUrlsByName = [];

        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeCategoryPageUrl((string) ($category['url'] ?? ''));

            if ($url === null) {
                continue;
            }

            $categoriesByUrl[$url] = $category;
            $path = $this->categoryPath($category);

            if (count($path) === 1) {
                $rootUrlsByName[$path[0]] = $url;
            }
        }

        $records = [];

        foreach ($leafUrls as $leafUrl) {
            $url = $this->normalizeCategoryPageUrl($leafUrl);

            if ($url === null) {
                continue;
            }

            $category = $categoriesByUrl[$url] ?? null;
            $path = is_array($category) ? $this->categoryPath($category) : [];
            $name = is_array($category) && is_string($category['name'] ?? null)
                ? $this->normalizeLabel($category['name'])
                : $this->labelFromUrl($url);
            $topCategoryName = $path[0] ?? $name;

            $records[$url] = [
                'name' => $name,
                'url' => $url,
                'top_category_name' => $topCategoryName,
                'top_category_url' => $rootUrlsByName[$topCategoryName] ?? $url,
                'path' => $path,
            ];
        }

        return array_values($records);
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<int, string>
     */
    private function categoryPath(array $category): array
    {
        if (! is_array($category['path'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $part): string => $this->normalizeLabel((string) $part),
            $category['path'],
        ), static fn (string $part): bool => $part !== ''));
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isReh4MatUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (count($segments) < 3 || ($segments[0] ?? '') !== 'produkt') {
            return null;
        }

        if ($this->isExcludedProductPath($path)) {
            return null;
        }

        return 'https://'.self::REH4MAT_HOST.$path.'/';
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isReh4MatUrl($url)) {
            return null;
        }

        return 'https://'.self::REH4MAT_HOST.$this->normalizePath((string) parse_url($url, PHP_URL_PATH)).'/';
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
            $url = 'https://'.self::REH4MAT_HOST.$url;
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

        return 'https://'.mb_strtolower((string) $parts['host']).$this->normalizePath((string) ($parts['path'] ?? '/'));
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function isExcludedProductPath(string $path): bool
    {
        $excludedPrefixes = [
            '/produkt/feed',
            '/produkt/page',
            '/produkt/wyroby-na-zamowienie',
            '/wp-content',
            '/uploads',
            '/wyroby-na-zamowienie',
        ];

        foreach ($excludedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function xpath(string $html): ?DOMXPath
    {
        $document = new DOMDocument;

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        return $loaded ? new DOMXPath($document) : null;
    }

    private function labelFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = (string) end($segments);

        if ($slug === '' || preg_match('/^\d+$/', $slug) === 1) {
            $slug = count($segments) >= 2 ? (string) $segments[count($segments) - 2] : $slug;
        }

        return $this->normalizeLabel(mb_convert_case(str_replace(['-', '_'], ' ', $slug), MB_CASE_TITLE, 'UTF-8'));
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = str_replace("\xc2\xa0", ' ', $label);
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function isReh4MatUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'reh4mat.com' || $host === self::REH4MAT_HOST;
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
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopReh4MatScraper/1.0; +https://konji.pl)',
        ];
    }
}
