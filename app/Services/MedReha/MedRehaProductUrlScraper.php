<?php

declare(strict_types=1);

namespace App\Services\MedReha;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MedRehaProductUrlScraper
{
    private const MEDREHA_HOST = 'sklep.medreha.pl';

    /**
     * MedReha is a Shoper store. Product pages can use `/pl/p/.../123` URLs
     * or custom SEO URLs such as `/ortezy-stabilizatory/pas-ledzwiowy-gorset`.
     * Product link extraction is therefore scoped to Shoper product card markup
     * before URL normalization is applied.
     */
    private const PRODUCT_LINK_SELECTORS = [
        '#box_mainproducts .product a.prodimage[href]',
        '#box_mainproducts .product a.prodname[href]',
        '#box_mainproducts .product .productname a[href]',
        '#box_mainproducts .product .name a[href]',
        '#box_mainproducts div.product a[href]',
        '.products .product a.prodimage[href]',
        '.products .product a.prodname[href]',
        '.products .product .productname a[href]',
        '.products .product .name a[href]',
        '.products div.product a[href]',
        '.product-list .product a[href]',
        '.product-list a.prodimage[href]',
        '.product-list a.prodname[href]',
        'a.prodimage[href]',
        'a.prodname[href]',
        '.prodname a[href]',
        '.productname a[href]',
        '.product .name a[href]',
        'h2.product-name a[href]',
        'h3.product-name a[href]',
        '.product h2 a[href]',
        '.product h3 a[href]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly MedRehaCategoryUrlScraper $categoryScraper,
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
    public function discover(array $startUrls = [MedRehaCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * Discover product links from the allowed MedReha category hierarchy.
     *
     * @param  array<int, string>  $startUrls
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
    public function scrape(
        array $startUrls = [MedRehaCategoryUrlScraper::DEFAULT_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $this->emit('Discovering MedReha category hierarchy...');
        $categoryDiscovery = $this->categoryScraper->scrape($startUrls);
        $this->emit('Product-scraping categories found: '.count($categoryDiscovery['product_category_urls'] ?? []));

        return $this->scrapeFromDiscoveredCategories($categoryDiscovery, $pageLimit, $categoryLimit);
    }

    /**
     * Scrape product links from an existing MedReha category discovery JSON payload.
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
     * Scrape product links from explicit MedReha category URLs without running category discovery first.
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

            $rootUrl = $this->categoryRootUrl($url);
            $categories[$rootUrl] = [
                'name' => $this->labelFromUrl($rootUrl),
                'url' => $rootUrl,
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

            $categoryUrl = $this->categoryRootUrl($categoryUrl);
            $categoryName = $category['name'] !== null && $category['name'] !== ''
                ? $category['name']
                : $this->labelFromUrl($categoryUrl);

            $this->emit('Scraping MedReha category '.$categoryNumber.'/'.$totalCategories.': '.$categoryName);
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
            'source' => 'medreha',
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
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $products = [];

        foreach (self::PRODUCT_LINK_SELECTORS as $selector) {
            try {
                $anchors = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            if ($anchors->count() === 0) {
                continue;
            }

            foreach ($this->extractProductsFromAnchors($anchors, $baseUrl) as $product) {
                $url = $product['url'];

                if (! isset($products[$url])) {
                    $products[$url] = $product;

                    continue;
                }

                if ($products[$url]['name'] === '' && $product['name'] !== '') {
                    $products[$url]['name'] = $product['name'];
                }
            }
        }

        return array_values($products);
    }

    /**
     * @return array<int, array{url: string, name: string}>
     */
    private function extractProductsFromAnchors(Crawler $anchors, string $baseUrl): array
    {
        $products = [];

        $anchors->each(function (Crawler $node) use (&$products, $baseUrl): void {
            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $url = $this->normalizeProductUrl($href, $baseUrl);

            if ($url === null) {
                return;
            }

            $name = $this->extractProductName($node);

            if (! isset($products[$url])) {
                $products[$url] = [
                    'url' => $url,
                    'name' => $name,
                ];

                return;
            }

            if ($products[$url]['name'] === '' && $name !== '') {
                $products[$url]['name'] = $name;
            }
        });

        return array_values($products);
    }

    private function extractProductName(Crawler $node): string
    {
        $name = $this->normalizeLabel($node->text(''));

        if ($name !== '') {
            return $name;
        }

        try {
            $image = $node->filter('img[alt]')->first();

            if ($image->count() > 0) {
                $name = $this->normalizeLabel((string) $image->attr('alt'));

                if ($name !== '') {
                    return $name;
                }
            }
        } catch (Throwable) {
            // Ignore malformed image nodes and fall back to title/URL labels.
        }

        $title = $this->normalizeLabel((string) $node->attr('title'));

        if ($title !== '') {
            return $title;
        }

        try {
            $card = $node->ancestors()->filter('div.product, article.product, li.product')->first();

            if ($card->count() > 0) {
                foreach (['a.prodname', '.prodname a', '.productname a', '.name a', 'h2 a', 'h3 a'] as $selector) {
                    $labelNode = $card->filter($selector)->first();

                    if ($labelNode->count() === 0) {
                        continue;
                    }

                    $name = $this->normalizeLabel($labelNode->text(''));

                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        } catch (Throwable) {
            // Ignore malformed card markup and fall back to URL labels.
        }

        $href = $node->attr('href');

        return is_string($href) ? $this->labelFromUrl($href) : '';
    }

    private function extractNextCategoryPageUrl(string $html, string $baseUrl): ?string
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return null;
        }

        foreach ([
            'link[rel="next"][href]',
            'a[rel="next"][href]',
            '.paginator li.next a[href]',
            '.pagination li.next a[href]',
            '.pages li.next a[href]',
            'a.next[href]',
            'a.page-next[href]',
            'a[title*="następ"][href]',
            'a[title*="Następ"][href]',
            'a[aria-label*="następ"][href]',
            'a[aria-label*="Następ"][href]',
        ] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            foreach ($nodes as $node) {
                $candidate = $this->normalizeCategoryPageUrl($node->getAttribute('href'), $baseUrl);

                if ($candidate !== null && $this->categoryRootUrl($candidate) === $this->categoryRootUrl($baseUrl)) {
                    return $candidate;
                }
            }
        }

        return $this->extractNextNumberedCategoryPageUrl($crawler, $baseUrl);
    }

    private function extractNextNumberedCategoryPageUrl(Crawler $crawler, string $baseUrl): ?string
    {
        $currentRoot = $this->categoryRootUrl($baseUrl);
        $currentPage = $this->categoryPageNumber($baseUrl);
        $nextUrl = null;
        $nextPage = null;

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use ($baseUrl, $currentRoot, $currentPage, &$nextUrl, &$nextPage): void {
                $href = $node->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $candidate = $this->normalizeCategoryPageUrl($href, $baseUrl);

                if ($candidate === null || $this->categoryRootUrl($candidate) !== $currentRoot) {
                    return;
                }

                $candidatePage = $this->categoryPageNumber($candidate);

                if ($candidatePage <= $currentPage) {
                    return;
                }

                $label = $this->normalizeLabel($node->text(''));
                $class = mb_strtolower((string) $node->attr('class'));
                $title = mb_strtolower((string) $node->attr('title'));
                $aria = mb_strtolower((string) $node->attr('aria-label'));

                $looksLikePagination = preg_match('/^\\d+$/', $label) === 1
                    || in_array($label, ['>', '»', '›'], true)
                    || str_contains($class, 'next')
                    || str_contains($class, 'page')
                    || str_contains($title, 'następ')
                    || str_contains($aria, 'następ');

                if (! $looksLikePagination) {
                    return;
                }

                if ($nextPage === null || $candidatePage < $nextPage) {
                    $nextPage = $candidatePage;
                    $nextUrl = $candidate;
                }
            });
        } catch (Throwable) {
            return null;
        }

        return $nextUrl;
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

            $url = $this->categoryRootUrl($url);
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

            $url = $this->categoryRootUrl($url);
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

        if ($url === null || ! $this->isMedRehaUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if ($this->isExcludedProductPath($path)) {
            return null;
        }

        if (preg_match('#^/pl/p/[A-Za-z0-9][A-Za-z0-9\-_]*/[1-9][0-9]*$#', $path) === 1) {
            return 'https://'.self::MEDREHA_HOST.$path;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (count($segments) < 2 || $this->lastPathSegmentIsNumeric($path)) {
            return null;
        }

        return 'https://'.self::MEDREHA_HOST.$path;
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isMedRehaUrl($url)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if ($this->isExcludedCategoryPath($path)) {
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

            $url = $base['scheme'].'://'.$base['host'].$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
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
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function categoryRootUrl(string $url): string
    {
        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('#^(/pl/c/[^/]+/[1-9][0-9]*)/[1-9][0-9]*$#', $path, $matches) === 1) {
            $path = $matches[1];
        } elseif (preg_match('#^(.+)/[1-9][0-9]*$#', $path, $matches) === 1 && ! str_starts_with($path, '/pl/c/')) {
            $path = $matches[1];
        }

        return 'https://'.self::MEDREHA_HOST.$path;
    }

    private function categoryPageNumber(string $url): int
    {
        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('#^/pl/c/[^/]+/[1-9][0-9]*/([1-9][0-9]*)$#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        if (! str_starts_with($path, '/pl/c/') && preg_match('#/([1-9][0-9]*)$#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        return 1;
    }

    private function isExcludedProductPath(string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        $excludedPrefixes = [
            '/pl/c',
            '/c/',
            '/category',
            '/pl/i',
            '/pl/s',
            '/pl/login',
            '/pl/reg',
            '/pl/basket',
            '/pl/contact',
            '/pl/newsletter',
            '/login',
            '/reg',
            '/basket',
            '/cart',
            '/koszyk',
            '/search',
            '/s',
            '/ajaxbasket',
            '/producent',
            '/producer',
            '/userdata',
            '/skins',
            '/libraries',
            '/assets',
        ];

        foreach ($excludedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedCategoryPath(string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        $excludedPrefixes = [
            '/pl/p',
            '/pl/i',
            '/pl/s',
            '/pl/login',
            '/pl/reg',
            '/pl/basket',
            '/pl/contact',
            '/pl/newsletter',
            '/login',
            '/reg',
            '/basket',
            '/cart',
            '/koszyk',
            '/search',
            '/s',
            '/ajaxbasket',
            '/producer',
            '/producent',
            '/userdata',
            '/skins',
            '/libraries',
            '/assets',
        ];

        foreach ($excludedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function lastPathSegmentIsNumeric(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = end($segments);

        return is_string($last) && preg_match('/^[1-9][0-9]*$/', $last) === 1;
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

    private function isMedRehaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::MEDREHA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            'medreha.pl', 'www.medreha.pl', self::MEDREHA_HOST => self::MEDREHA_HOST,
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMedRehaScraper/1.0; +https://konji.pl)',
        ];
    }
}
