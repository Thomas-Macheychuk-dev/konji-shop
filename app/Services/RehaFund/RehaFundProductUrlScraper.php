<?php

declare(strict_types=1);

namespace App\Services\RehaFund;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class RehaFundProductUrlScraper
{
    private const REHAFUND_HOST = 'sklep.rehafund.pl';

    /**
     * RehaFund is a Comarch store. Product URLs use the `/3-...` marker, while
     * category URLs use `/produkty/.../2-{id}`. Product cards are rendered with
     * `minibox-product-ui` and `product-link-ui` anchors on category pages.
     */
    private const PRODUCT_SCOPE_SELECTORS = [
        '.products-grid-ui',
        '.product-list-ui',
        '.products-list-ui',
        '.products-container-ui',
        '.main-content-ui',
        '#main',
    ];

    private const PRODUCT_LINK_SELECTORS = [
        '.minibox-product-ui a.product-link-ui[href]',
        'a.product-link-ui[href]',
        '.product-name-ui a[href]',
        'h2 a[href*="/3-"]',
        'h3 a[href*="/3-"]',
        'a[href*="/3-"]',
        'a[href*="3-"]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly RehaFundCategoryUrlScraper $categoryScraper,
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
    public function discover(array $startUrls = [RehaFundCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(
        array $startUrls = [RehaFundCategoryUrlScraper::DEFAULT_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $this->emit('Discovering RehaFund category hierarchy...');
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
                'top_category_name' => null,
                'top_category_url' => null,
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

            $this->emit('Scraping RehaFund category '.$categoryNumber.'/'.$totalCategories.': '.$categoryName);
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
            'source' => 'rehafund',
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
    public function extractProducts(string $html, string $baseUrl = RehaFundCategoryUrlScraper::DEFAULT_URL): array
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
            $this->extractProductsFromScope($crawler, $baseUrl, $products);
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
     * Comarch category pages occasionally vary the product-card wrapper enough
     * that DomCrawler selectors can miss otherwise valid product links. Keep a
     * conservative raw-HTML fallback for `/3-...` product URLs, after removing
     * site chrome where header/footer/category links may live.
     *
     * @param  array<string, array{url: string, name: string}>  $products
     */
    private function extractProductsFromHtmlFallback(string $html, string $baseUrl, array &$products): void
    {
        $html = preg_replace('#<header\b[^>]*>.*?</header>#isu', '', $html) ?? $html;
        $html = preg_replace('#<footer\b[^>]*>.*?</footer>#isu', '', $html) ?? $html;
        $html = preg_replace('#<nav\b[^>]*class=(?P<quote>["\'])(?:(?!\k<quote>).)*category-column-ui(?:(?!\k<quote>).)*\k<quote>[^>]*>.*?</nav>#isu', '', $html) ?? $html;

        if (preg_match_all('#<a\b[^>]*href=(?P<quote>["\'])(?P<href>[^"\']*3-[^"\']*)\k<quote>[^>]*>(?P<body>.*?)</a>#isu', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false) {
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
    }

    private function extractProductNameFromHtmlFallback(string $html, int $anchorOffset, string $anchorBody, string $href): string
    {
        $text = $this->normalizeText(strip_tags($anchorBody));

        if ($text !== '') {
            return $text;
        }

        $nearbyHtml = substr($html, $anchorOffset, 1500) ?: '';

        foreach ([
            '#<h[1-6]\b[^>]*class=(?P<quote>["\'])(?:(?!\k<quote>).)*product-name-ui(?:(?!\k<quote>).)*\k<quote>[^>]*>(?P<text>.*?)</h[1-6]>#isu',
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
        foreach (['header', 'footer', 'nav.category-column-ui', '.category-column-ui', '.breadcrumbs-ui', '.filters-ui'] as $selector) {
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
            $card = $link->ancestors()->filter('.minibox-product-ui, .product-item-ui, .product-row-ui, .product-ui')->first();

            if ($card->count() > 0) {
                foreach (['.product-name-ui', '.product-caption-ui h3', 'h2', 'h3', 'img[alt]'] as $selector) {
                    $node = $card->filter($selector)->first();

                    if ($node->count() === 0) {
                        continue;
                    }

                    $value = $selector === 'img[alt]' ? (string) $node->attr('alt') : $node->text('', true);
                    $name = $this->normalizeText($value);

                    if ($name !== '') {
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

        if ($text !== '') {
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

        return is_string($href) ? $this->labelFromUrl($href) : 'RehaFund product';
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
            '.pagination-ui a[href]',
            '.pagination a[href]',
            '.paginator-ui a[href]',
            '.paginator a[href]',
            '.pages-ui a[href]',
            '.pager a[href]',
            'a[aria-label*="następ"][href]',
            'a[aria-label*="Następ"][href]',
            'a[title*="następ"][href]',
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
                'top_category_name' => $path[0] ?? $this->nullableString($category['name'] ?? null),
                'top_category_url' => null,
                'path' => $path !== [] ? $path : [$this->labelFromUrl($rootUrl)],
            ];
        }

        foreach ($categoryByUrl as $url => $category) {
            $topName = $category['top_category_name'];

            if ($topName === null) {
                continue;
            }

            foreach ($categoryByUrl as $candidate) {
                if ($candidate['name'] === $topName && count($candidate['path']) === 1) {
                    $categoryByUrl[$url]['top_category_url'] = $candidate['url'];
                    break;
                }
            }
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
                'top_category_name' => null,
                'top_category_url' => null,
                'path' => [$this->labelFromUrl($rootUrl)],
            ];
        }

        return array_values($records);
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isRehaFundUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (str_starts_with($path, '/produkty/')) {
            return null;
        }

        if (preg_match('#/3-\d+(?:-\d+)*$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::REHAFUND_HOST.$path;
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isRehaFundUrl($absolute)) {
            return null;
        }

        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        if (! str_starts_with($path, '/produkty/') || preg_match('#/2-\d+$#', $path) !== 1) {
            return null;
        }

        $query = (string) parse_url($absolute, PHP_URL_QUERY);

        return 'https://'.self::REHAFUND_HOST.$path.($query !== '' ? '?'.$query : '');
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
            $url = 'https://'.self::REHAFUND_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            // RehaFund sets a document-level <base href="//sklep.rehafund.pl/">, so
            // relative catalogue/product links should be resolved from the site root.
            $url = 'https://'.self::REHAFUND_HOST.'/'.ltrim($url, '/');
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

        return rtrim($path, '/') ?: '/';
    }

    private function categoryRootUrl(string $url): string
    {
        $absolute = $this->normalizeCategoryPageUrl($url) ?? $url;
        $path = $this->normalizePath((string) parse_url($absolute, PHP_URL_PATH));

        return 'https://'.self::REHAFUND_HOST.$path;
    }

    private function categoryPageNumber(string $url): int
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (['page', 'pageId', 'strona', 'p', 'pageNumber'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                return max(1, (int) $query[$key]);
            }
        }

        if (preg_match('#/(?:strona|page)/(\d+)$#', (string) parse_url($url, PHP_URL_PATH), $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }

    private function isRehaFundUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::REHAFUND_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            self::REHAFUND_HOST => self::REHAFUND_HOST,
            default => null,
        };
    }

    private function labelFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $segment = $segments === [] ? 'rehafund' : (string) end($segments);

        if (preg_match('/^(?:2|3)-\d+(?:-\d+)*$/', $segment) === 1 && count($segments) > 1) {
            $segment = $segments[count($segments) - 2];
        }

        return Str::headline(str_replace('-', ' ', $segment));
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopRehaFundProductUrlScraper/1.0; +https://konji.pl)',
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
                $items[] = $item;
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
