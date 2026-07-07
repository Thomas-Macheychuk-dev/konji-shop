<?php

declare(strict_types=1);

namespace App\Services\Apolonia;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ApoloniaProductUrlScraper
{
    private const APOLONIA_HOST = 'www.apolonia.com.pl';

    /**
     * IdoSell category pages render product cards below the content area. Product
     * URLs use the stable `/product-pol-{id}-{slug}.html` shape.
     */
    private const PRODUCT_SCOPE_SELECTORS = [
        '#content',
        'main',
        '#search',
        '#products',
        '.products',
        '.product_wrapper',
        '.product',
        'body',
    ];

    private const PRODUCT_LINK_SELECTORS = [
        'a.product__name[href]',
        '.product a.product__name[href]',
        '.product h2 a[href*="/product-pol-"]',
        '.product h3 a[href*="/product-pol-"]',
        'h2 a[href*="/product-pol-"]',
        'h3 a[href*="/product-pol-"]',
        'a[href*="/product-pol-"]',
        'a[href*="product-pol-"]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly ApoloniaCategoryUrlScraper $categoryScraper,
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
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [ApoloniaCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(
        array $startUrls = [ApoloniaCategoryUrlScraper::DEFAULT_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $this->emit('Discovering Apolonia category hierarchy...');
        $categoryDiscovery = $this->categoryScraper->scrape($startUrls);
        $this->emit('Product-scraping categories found: '.count($categoryDiscovery['product_category_urls'] ?? []));

        return $this->scrapeFromDiscoveredCategories($categoryDiscovery, $pageLimit, $categoryLimit);
    }

    /**
     * @param  array<string, mixed>  $categoryDiscovery
     * @return array<string, mixed>
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
     * @param  array<int, string>  $categoryUrls
     * @return array<string, mixed>
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
                'top_category_name' => $this->labelFromUrl($rootUrl),
                'top_category_url' => $rootUrl,
                'path' => [$this->labelFromUrl($rootUrl)],
            ];
        }

        return $this->scrapeCategoryRecords(array_values($categories), [], [], $pageLimit, $categoryLimit, null);
    }

    /**
     * @param  array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>  $categories
     * @param  array<int, string>  $initialVisitedUrls
     * @param  array<string, string>  $initialFailedUrls
     * @param  array<string, mixed>|null  $categoryDiscovery
     * @return array<string, mixed>
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

            $this->emit('Scraping Apolonia category '.$categoryNumber.'/'.$totalCategories.': '.$categoryName);
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

                foreach ($pageProducts as $product) {
                    $productUrl = $product['url'];
                    $categoryProductUrls[$productUrl] = true;

                    if (isset($products[$productUrl])) {
                        continue;
                    }

                    $products[$productUrl] = [
                        'url' => $productUrl,
                        'name' => $product['name'],
                        'category_name' => $categoryName,
                        'category_url' => $categoryUrl,
                        'top_category_name' => $category['top_category_name'],
                        'top_category_url' => $category['top_category_url'],
                        'category_path' => $category['path'],
                    ];
                }

                $nextUrl = $this->extractNextCategoryPageUrl($html, $nextUrl);
            }

            $categoryResults[] = [
                'name' => $categoryName,
                'url' => $categoryUrl,
                'top_category_name' => $category['top_category_name'],
                'top_category_url' => $category['top_category_url'],
                'category_path' => $category['path'],
                'visited_urls' => array_keys($categoryPageUrls),
                'product_urls' => array_keys($categoryProductUrls),
                'product_count' => count($categoryProductUrls),
                'pages_scraped' => $pagesScraped,
            ];
        }

        return [
            'source' => 'apolonia',
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
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeoutSeconds)
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
    public function extractProducts(string $html, string $baseUrl = ApoloniaCategoryUrlScraper::DEFAULT_URL): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $products = [];

        foreach (self::PRODUCT_SCOPE_SELECTORS as $scopeSelector) {
            try {
                $scopes = $crawler->filter($scopeSelector);
            } catch (Throwable) {
                continue;
            }

            if ($scopes->count() === 0) {
                continue;
            }

            $scopes->each(function (Crawler $scope) use (&$products, $baseUrl): void {
                $this->extractProductsFromScope($scope, $baseUrl, $products);
            });

            if ($products !== []) {
                break;
            }
        }

        if ($products === []) {
            $this->extractProductsFromHtmlFallback($html, $baseUrl, $products);
        }

        return array_values($products);
    }

    /**
     * @param  array<string, array{url: string, name: string}>  $products
     */
    private function extractProductsFromScope(Crawler $scope, string $baseUrl, array &$products): void
    {
        foreach (self::PRODUCT_LINK_SELECTORS as $selector) {
            try {
                $links = $scope->filter($selector);
            } catch (Throwable) {
                continue;
            }

            if ($links->count() === 0) {
                continue;
            }

            $links->each(function (Crawler $link) use (&$products, $baseUrl): void {
                if ($this->isInsideExcludedProductLinkContext($link)) {
                    return;
                }

                $href = $link->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeProductUrl($href, $baseUrl);

                if ($url === null) {
                    return;
                }

                $name = $this->extractProductName($link);

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
        }
    }

    /**
     * @param  array<string, array{url: string, name: string}>  $products
     */
    private function extractProductsFromHtmlFallback(string $html, string $baseUrl, array &$products): void
    {
        $html = preg_replace('#<header\b[^>]*>.*?</header>#isu', '', $html) ?? $html;
        $html = preg_replace('#<footer\b[^>]*>.*?</footer>#isu', '', $html) ?? $html;
        $html = preg_replace('#<nav\b[^>]*id=(?P<quote>["\'])menu_categories\k<quote>[^>]*>.*?</nav>#isu', '', $html) ?? $html;

        if (preg_match_all('#<a\b[^>]*href=(?P<quote>["\'])(?P<href>[^"\']*product-pol-\d+[^"\']*)\k<quote>[^>]*>(?P<body>.*?)</a>#isu', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches as $match) {
            $href = (string) ($match['href'][0] ?? '');
            $url = $this->normalizeProductUrl($href, $baseUrl);

            if ($url === null || isset($products[$url])) {
                continue;
            }

            $products[$url] = [
                'url' => $url,
                'name' => $this->extractProductNameFromHtmlFallback($html, (int) ($match[0][1] ?? 0), (string) ($match['body'][0] ?? ''), $href),
            ];
        }
    }

    private function extractProductNameFromHtmlFallback(string $html, int $anchorOffset, string $anchorBody, string $href): string
    {
        $text = $this->normalizeText(strip_tags($anchorBody));

        if ($text !== '' && ! str_contains(mb_strtolower($text), 'dodaj do')) {
            return $text;
        }

        $nearbyHtml = substr($html, max(0, $anchorOffset - 500), 2000) ?: '';

        foreach ([
            '#<h[1-6]\b[^>]*>\s*<a\b[^>]*href=["\'][^"\']*'.preg_quote($href, '#').'[^"\']*["\'][^>]*>(?P<text>.*?)</a>\s*</h[1-6]>#isu',
            '#<img\b[^>]*alt=(?P<quote>["\'])(?P<text>[^"\']+)\k<quote>[^>]*>#isu',
            '#<h[1-6]\b[^>]*>(?P<text>.*?)</h[1-6]>#isu',
        ] as $pattern) {
            if (preg_match($pattern, $nearbyHtml, $match) === 1) {
                $name = $this->normalizeText(strip_tags((string) ($match['text'] ?? '')));

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $this->labelFromUrl($href);
    }

    private function isInsideExcludedProductLinkContext(Crawler $link): bool
    {
        foreach (['header', 'footer', 'nav#menu_categories', '#menu_categories', '.breadcrumbs', '.breadcrumbs-ui', '#Filters', '.filters', '.filters__block'] as $selector) {
            try {
                if ($link->ancestors()->filter($selector)->count() > 0) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    private function extractProductName(Crawler $link): string
    {
        try {
            $card = $link->ancestors()->filter('.product, .product_wrapper, .product__wrapper, .product-item, .products__item')->first();

            if ($card->count() > 0) {
                foreach (['.product__name', 'h2', 'h3', 'img[alt]'] as $selector) {
                    $node = $card->filter($selector)->first();

                    if ($node->count() === 0) {
                        continue;
                    }

                    $value = $selector === 'img[alt]' ? (string) $node->attr('alt') : $node->text('', true);
                    $name = $this->normalizeText($value);

                    if ($name !== '' && ! str_contains(mb_strtolower($name), 'dodaj do')) {
                        return $name;
                    }
                }
            }
        } catch (Throwable) {
            // Fall back to link attributes/text.
        }

        $title = $this->normalizeText((string) $link->attr('title'));

        if ($title !== '') {
            return $title;
        }

        $text = $this->normalizeText($link->text('', true));

        if ($text !== '' && ! str_contains(mb_strtolower($text), 'dodaj do')) {
            return $text;
        }

        try {
            $image = $link->filter('img[alt]')->first();

            if ($image->count() > 0) {
                $alt = $this->normalizeText((string) $image->attr('alt'));

                if ($alt !== '') {
                    return $alt;
                }
            }
        } catch (Throwable) {
            // Fall back to URL label.
        }

        $href = $link->attr('href');

        return is_string($href) ? $this->labelFromUrl($href) : 'Apolonia product';
    }

    private function extractNextCategoryPageUrl(string $html, string $currentUrl): ?string
    {
        try {
            $crawler = new Crawler($html, $currentUrl);
        } catch (Throwable) {
            return null;
        }

        foreach ([
            'link[rel="next"][href]',
            'a[rel="next"][href]',
            '.pagination a[href]',
            '.pagination__item a[href]',
            '.pagination__element a[href]',
            '.pager a[href]',
            '.paginator a[href]',
            'a[href*="counter="]',
            'a[aria-label*="Następ"][href]',
            'a[title*="Następ"][href]',
        ] as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                continue;
            }

            $candidate = $this->bestNextUrlFromNodes($nodes, $currentUrl);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        try {
            return $this->bestNextUrlFromNodes($crawler->filter('a[href]'), $currentUrl);
        } catch (Throwable) {
            return null;
        }
    }

    private function bestNextUrlFromNodes(Crawler $nodes, string $currentUrl): ?string
    {
        $currentRoot = $this->categoryRootUrl($currentUrl);
        $currentPage = $this->categoryPageNumber($currentUrl);
        $nextUrl = null;
        $nextPage = null;

        $nodes->each(function (Crawler $node) use ($currentRoot, $currentPage, $currentUrl, &$nextUrl, &$nextPage): void {
            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $candidate = $this->normalizeCategoryPageUrl($href, $currentUrl);

            if ($candidate === null || $this->categoryRootUrl($candidate) !== $currentRoot) {
                return;
            }

            $candidatePage = $this->categoryPageNumber($candidate);

            if ($candidatePage <= $currentPage) {
                return;
            }

            $label = mb_strtolower($this->normalizeText($node->text('', true)));
            $class = mb_strtolower((string) $node->attr('class'));
            $title = mb_strtolower((string) $node->attr('title'));
            $aria = mb_strtolower((string) $node->attr('aria-label'));

            $looksLikeNext = $candidatePage === $currentPage + 1
                || str_contains($label, 'następ')
                || str_contains($label, '›')
                || str_contains($label, '»')
                || preg_match('/^\d+$/', $label) === 1
                || str_contains($class, 'next')
                || str_contains($class, 'page')
                || str_contains($title, 'następ')
                || str_contains($aria, 'następ');

            if (! $looksLikeNext) {
                return;
            }

            if ($nextPage === null || $candidatePage < $nextPage) {
                $nextPage = $candidatePage;
                $nextUrl = $candidate;
            }
        });

        return $nextUrl;
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, path: array<int, string>}>
     */
    private function categoryRecordsFromDiscoveryResult(array $discovery): array
    {
        $categoryByUrl = [];

        foreach (($discovery['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeCategoryPageUrl((string) ($category['url'] ?? ''));

            if ($url === null) {
                continue;
            }

            $rootUrl = $this->categoryRootUrl($url);
            $path = $this->stringList($category['path'] ?? []);

            $categoryByUrl[$rootUrl] = [
                'name' => $this->nullableString($category['name'] ?? null) ?? $this->labelFromUrl($rootUrl),
                'url' => $rootUrl,
                'top_category_name' => $path[0] ?? $this->nullableString($category['top_category_name'] ?? null),
                'top_category_url' => $this->nullableString($category['top_category_url'] ?? null),
                'path' => $path !== [] ? $path : [$this->labelFromUrl($rootUrl)],
            ];
        }

        $productCategoryUrls = $this->stringList($discovery['product_category_urls'] ?? []);
        $records = [];

        foreach ($productCategoryUrls as $categoryUrl) {
            $url = $this->normalizeCategoryPageUrl($categoryUrl);

            if ($url === null) {
                continue;
            }

            $rootUrl = $this->categoryRootUrl($url);
            $records[$rootUrl] = $categoryByUrl[$rootUrl] ?? [
                'name' => $this->labelFromUrl($rootUrl),
                'url' => $rootUrl,
                'top_category_name' => $this->labelFromUrl($rootUrl),
                'top_category_url' => $rootUrl,
                'path' => [$this->labelFromUrl($rootUrl)],
            ];
        }

        return array_values($records);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isApoloniaUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (preg_match('~^/product-pol-\d+-[^?]+\.html$~u', $path) !== 1) {
            return null;
        }

        return 'https://'.self::APOLONIA_HOST.$path;
    }

    public function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isApoloniaUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (preg_match('~^/pol_m_[^?]+-\d+\.html$~u', $path) !== 1) {
            return null;
        }

        $query = (string) parse_url($absolute, PHP_URL_QUERY);

        return 'https://'.self::APOLONIA_HOST.$path.($query !== '' ? '?'.$query : '');
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
            $baseUrl ??= ApoloniaCategoryUrlScraper::DEFAULT_URL;
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

    private function categoryRootUrl(string $url): string
    {
        $absolute = $this->normalizeCategoryPageUrl($url) ?? $url;
        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        return 'https://'.self::APOLONIA_HOST.$path;
    }

    private function categoryPageNumber(string $url): int
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (isset($query['counter']) && is_numeric($query['counter'])) {
            return max(1, ((int) $query['counter']) + 1);
        }

        foreach (['page', 'strona', 'p'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                return max(1, (int) $query[$key]);
            }
        }

        return 1;
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

    private function labelFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('/^product-pol-\d+-(?P<slug>.+)\.html$/u', $path, $matches) === 1) {
            return Str::headline(str_replace('-', ' ', (string) $matches['slug']));
        }

        if (preg_match('/^pol_m_(?P<slug>.+)-\d+\.html$/u', $path, $matches) === 1) {
            $parts = array_values(array_filter(explode('_', (string) $matches['slug'])));
            $slug = $parts !== [] ? (string) end($parts) : (string) $matches['slug'];

            return Str::headline(str_replace('-', ' ', $slug));
        }

        return 'Apolonia';
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopApoloniaProductUrlScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $items[$key] = $item;
            }
        }

        return $items;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = $this->normalizeText($value);

        return $value === '' ? null : $value;
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
