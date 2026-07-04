<?php

declare(strict_types=1);

namespace App\Services\Timago;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class TimagoProductUrlScraper
{
    private const TIMAGO_HOST = 'www.timago.com';

    /**
     * Timago product pages use `/pl/product-slug.html` URLs. Product extraction is scoped away
     * from the header Oferta menu, because that menu contains repeated "Polecamy" product links.
     */
    private const PRODUCT_SCOPE_SELECTORS = [
        '.product__container',
        '.products',
        '.product-list',
        '.main',
        'main',
    ];

    private const PRODUCT_LINK_SELECTORS = [
        'a.product__item[href]',
        'a[href$=".html"]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly TimagoCategoryUrlScraper $categoryScraper,
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
    public function discover(array $startUrls = [TimagoCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(
        array $startUrls = [TimagoCategoryUrlScraper::DEFAULT_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $this->emit('Discovering Timago category hierarchy...');
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

            $this->emit('Scraping Timago category '.$categoryNumber.'/'.$totalCategories.': '.$categoryName);
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

                foreach ($pageProducts as $productUrl => $productName) {
                    $categoryProductUrls[$productUrl] = true;

                    if (isset($products[$productUrl])) {
                        continue;
                    }

                    $products[$productUrl] = [
                        'url' => $productUrl,
                        'name' => $productName,
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
            'source' => 'timago',
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
     * @return array<string, string>
     */
    public function extractProducts(string $html, string $baseUrl = TimagoCategoryUrlScraper::DEFAULT_URL): array
    {
        $products = [];

        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return $this->extractProductsFromHtmlFallback($html, $baseUrl);
        }

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

        return $products !== [] ? $products : $this->extractProductsFromHtmlFallback($html, $baseUrl);
    }

    /**
     * @param  array<string, string>  $products
     */
    private function extractProductsFromScope(Crawler $scope, string $baseUrl, array &$products): void
    {
        foreach (self::PRODUCT_LINK_SELECTORS as $selector) {
            $scope->filter($selector)->each(function (Crawler $link) use (&$products, $baseUrl): void {
                if ($this->isInsideExcludedProductLinkContext($link)) {
                    return;
                }

                $href = $link->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeProductUrl($href, $baseUrl);

                if ($url === null || isset($products[$url])) {
                    return;
                }

                $products[$url] = $this->extractProductName($link);
            });

            if ($products !== []) {
                return;
            }
        }
    }

    /**
     * Timago category pages are regular HTML, but their product-card markup is not as stable as
     * Shoper suppliers. Keep a conservative fallback so tests and live scraping do not depend on
     * one exact product card class. Header/footer/navigation markup is removed first so Oferta
     * menu "Polecamy" links are still ignored.
     *
     * @return array<string, string>
     */
    private function extractProductsFromHtmlFallback(string $html, string $baseUrl): array
    {
        $html = $this->removeExcludedProductLinkHtml($html);
        $products = [];

        preg_match_all(
            '#<a\b[^>]*\bhref=[\'"](?P<href>[^\'"]+\.html(?:\?[^\'"]*)?)[\'"][^>]*>(?P<body>.*?)</a>#isu',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $url = $this->normalizeProductUrl((string) ($match['href'] ?? ''), $baseUrl);

            if ($url === null || isset($products[$url])) {
                continue;
            }

            $products[$url] = $this->extractProductNameFromAnchorHtml((string) ($match[0] ?? ''), (string) ($match['body'] ?? ''));
        }

        return $products;
    }

    private function removeExcludedProductLinkHtml(string $html): string
    {
        $html = preg_replace('#<(header|footer|nav)\b[^>]*>.*?</\1>#isu', '', $html) ?? $html;
        $html = preg_replace('#<div\b[^>]*class=[\'"][^\'"]*\bnav__level-3-hit\b[^\'"]*[\'"][^>]*>.*?</div>#isu', '', $html) ?? $html;
        $html = preg_replace('#<div\b[^>]*class=[\'"][^\'"]*\bbreadcrumbs\b[^\'"]*[\'"][^>]*>.*?</div>#isu', '', $html) ?? $html;

        return $html;
    }

    private function extractProductNameFromAnchorHtml(string $anchorHtml, string $bodyHtml): string
    {
        foreach (['h3', 'h4'] as $tag) {
            if (preg_match('#<'.$tag.'\b[^>]*>(.*?)</'.$tag.'>#isu', $bodyHtml, $match) === 1) {
                $name = $this->normalizeText(strip_tags((string) $match[1]));

                if ($name !== '') {
                    return $name;
                }
            }
        }

        if (preg_match('#\btitle=[\'"](?P<title>[^\'"]+)[\'"]#isu', $anchorHtml, $match) === 1) {
            $name = $this->normalizeText((string) $match['title']);

            if ($name !== '') {
                return $name;
            }
        }

        $name = $this->normalizeText(strip_tags($bodyHtml));

        return $name !== '' ? $name : 'Timago product';
    }

    private function isInsideExcludedProductLinkContext(Crawler $link): bool
    {
        $excludedAncestorSelectors = [
            'header',
            'footer',
            'nav',
            '.nav__level-3-hit',
            '.breadcrumbs',
        ];

        foreach ($excludedAncestorSelectors as $selector) {
            if ($link->ancestors()->filter($selector)->count() > 0) {
                return true;
            }
        }

        return false;
    }

    private function extractProductName(Crawler $link): string
    {
        foreach (['h3', 'h4', '.product__name', '.productname', '.name'] as $selector) {
            $node = $link->filter($selector)->first();

            if ($node->count() > 0) {
                $text = $this->normalizeText($node->text(''));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        $title = $link->attr('title');

        if (is_string($title) && trim($title) !== '') {
            return $this->normalizeText($title);
        }

        $text = $this->normalizeText($link->text(''));

        return $text !== '' ? $text : 'Timago product';
    }

    private function extractNextCategoryPageUrl(string $html, string $currentUrl): ?string
    {
        $crawler = new Crawler($html, $currentUrl);
        $currentRoot = $this->categoryRootUrl($currentUrl);
        $currentPage = $this->categoryPageNumber($currentUrl);
        $nextUrl = null;
        $nextPage = null;

        foreach (['link[rel="next"][href]', 'a[rel="next"][href]', '.pagination a[href]', '.paginacja a[href]', '.pager a[href]'] as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use ($currentUrl, $currentRoot, $currentPage, &$nextUrl, &$nextPage): void {
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

                $text = $this->normalizeText($node->text(''));
                $class = strtolower((string) $node->attr('class'));
                $parentClass = strtolower((string) ($node->ancestors()->first()->attr('class') ?? ''));

                if (
                    $candidatePage === $currentPage + 1
                    || str_contains($text, '»')
                    || str_contains($text, '›')
                    || str_contains($text, 'następ')
                    || str_contains($class, 'next')
                    || str_contains($parentClass, 'next')
                ) {
                    if ($nextPage === null || $candidatePage < $nextPage) {
                        $nextPage = $candidatePage;
                        $nextUrl = $candidate;
                    }
                }
            });

            if ($nextUrl !== null) {
                return $nextUrl;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }

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

        if ($absolute === null || ! $this->isTimagoUrl($absolute)) {
            return null;
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);
        $path = preg_replace('#/+#', '/', '/'.ltrim($path, '/')) ?: $path;

        if (! preg_match('#^/pl/[A-Za-z0-9][A-Za-z0-9\-_]*\.html$#', $path)) {
            return null;
        }

        return 'https://'.self::TIMAGO_HOST.$path;
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isTimagoUrl($absolute)) {
            return null;
        }

        $path = $this->normalizeCategoryPath((string) parse_url($absolute, PHP_URL_PATH));

        if (! preg_match('#^/pl/[a-z0-9\-]+(?:/[a-z0-9\-]+)*/$#i', $path)) {
            return null;
        }

        if (str_contains($path, '.html') || trim($path, '/') === 'pl/nowosci') {
            return null;
        }

        $query = (string) parse_url($absolute, PHP_URL_QUERY);

        return 'https://'.self::TIMAGO_HOST.$path.($query !== '' ? '?'.$query : '');
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
            $url = 'https://'.self::TIMAGO_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null || $baseUrl === '') {
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

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) ? '?'.(string) $parts['query'] : '';

        return 'https://'.$host.($path === '/' ? '' : $path).$query;
    }

    private function categoryRootUrl(string $url): string
    {
        $path = $this->normalizeCategoryPath((string) parse_url($url, PHP_URL_PATH));

        return 'https://'.self::TIMAGO_HOST.$path;
    }

    private function categoryPageNumber(string $url): int
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (['page', 'strona', 'p'] as $key) {
            if (isset($query[$key]) && (int) $query[$key] > 0) {
                return (int) $query[$key];
            }
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        foreach (array_reverse($segments) as $segment) {
            if (preg_match('/^[1-9][0-9]*$/', $segment)) {
                return (int) $segment;
            }
        }

        return 1;
    }

    private function labelFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $slug = end($segments) ?: 'Timago category';

        return $this->normalizeText(str_replace('-', ' ', rawurldecode((string) $slug)));
    }

    private function isTimagoUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::TIMAGO_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            self::TIMAGO_HOST, 'timago.com' => self::TIMAGO_HOST,
            default => null,
        };
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function normalizeCategoryPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        $path = rtrim($path, '/') ?: '/';

        return $path === '/' ? '/' : $path.'/';
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
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

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = $item;
            }
        }

        return array_values(array_unique($strings));
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

        $strings = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $strings[$key] = $item;
            }
        }

        return $strings;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopTimagoScraper/1.0; +https://konji.pl)',
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
