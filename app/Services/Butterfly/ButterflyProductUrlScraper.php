<?php

declare(strict_types=1);

namespace App\Services\Butterfly;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ButterflyProductUrlScraper
{
    private const BUTTERFLY_HOST = 'butterfly-mag.com';

    /**
     * Butterfly is a Shoper store. Product pages use `/pl/p/.../123` URLs in Shoper product cards.
     * Extraction is intentionally scoped to product-list markup so menu/footer links do not pollute the result.
     */
    private const PRODUCT_CARD_SELECTORS = [
        '#box_mainproducts .product',
        '.products .product',
        '.product-list .product',
        '.product-main-wrap',
    ];

    private const PRODUCT_LINK_SELECTORS = [
        'a.prodimage[href]',
        'a.prodname[href]',
        '.productname a[href]',
        '.name a[href]',
        'h2.product-name a[href]',
        'h3.product-name a[href]',
        'h2 a[href]',
        'h3 a[href]',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly ButterflyCategoryUrlScraper $categoryScraper,
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
    public function discover(array $startUrls = [ButterflyCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
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
        array $startUrls = [ButterflyCategoryUrlScraper::DEFAULT_URL],
        ?int $pageLimit = null,
        ?int $categoryLimit = null,
    ): array {
        $this->emit('Discovering Butterfly category hierarchy...');
        $categoryDiscovery = $this->categoryScraper->scrape($startUrls);
        $this->emit('Product-scraping categories found: '.count($categoryDiscovery['product_category_urls'] ?? []));

        return $this->scrapeFromDiscoveredCategories($categoryDiscovery, $pageLimit, $categoryLimit);
    }

    /**
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

            $this->emit('Scraping Butterfly category '.$categoryNumber.'/'.$totalCategories.': '.$categoryName);
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
            'source' => 'butterfly',
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
    public function extractProducts(string $html, string $baseUrl = ButterflyCategoryUrlScraper::DEFAULT_URL): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $products = [];

        foreach (self::PRODUCT_CARD_SELECTORS as $cardSelector) {
            $crawler->filter($cardSelector)->each(function (Crawler $card) use (&$products, $baseUrl): void {
                $this->extractProductFromCard($card, $baseUrl, $products);
            });

            if ($products !== []) {
                break;
            }
        }

        return $products;
    }

    /**
     * @param  array<string, string>  $products
     */
    private function extractProductFromCard(Crawler $card, string $baseUrl, array &$products): void
    {
        foreach (self::PRODUCT_LINK_SELECTORS as $selector) {
            $link = $card->filter($selector)->first();

            if ($link->count() === 0) {
                continue;
            }

            $href = $link->attr('href');

            if (! is_string($href)) {
                continue;
            }

            $url = $this->normalizeProductUrl($href, $baseUrl);

            if ($url === null) {
                continue;
            }

            $products[$url] = $this->extractProductName($card, $link);

            return;
        }
    }

    private function extractProductName(Crawler $card, Crawler $link): string
    {
        foreach (['.productname', 'a.prodname', '.name', 'h2', 'h3'] as $selector) {
            $node = $card->filter($selector)->first();

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

        return $text !== '' ? $text : 'Butterfly product';
    }

    private function extractNextCategoryPageUrl(string $html, string $currentUrl): ?string
    {
        $crawler = new Crawler($html, $currentUrl);
        $currentRoot = $this->categoryRootUrl($currentUrl);
        $currentPage = $this->categoryPageNumber($currentUrl);
        $nextUrl = null;
        $nextPage = null;

        foreach (['link[rel="next"][href]', 'a[rel="next"][href]', '.paginator li.last a[href]', '.paginator a[href]', '.pagination a[href]'] as $selector) {
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
                    || str_contains($class, 'last')
                    || str_contains($parentClass, 'next')
                    || str_contains($parentClass, 'last')
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

        return $nextUrl;
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
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopButterflyScraper/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
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

        if ($absolute === null || ! $this->isButterflyUrl($absolute)) {
            return null;
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);
        $path = rtrim($path, '/');

        if (! preg_match('#^/pl/p/[A-Za-z0-9][A-Za-z0-9\-_]*/[1-9][0-9]*$#', $path)) {
            return null;
        }

        return 'https://'.self::BUTTERFLY_HOST.$path;
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $absolute = $this->normalizeAbsoluteUrl($url, $baseUrl);

        if ($absolute === null || ! $this->isButterflyUrl($absolute)) {
            return null;
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);
        $path = rtrim($path, '/');

        if (! preg_match('#^/pl/c/[A-Za-z0-9][A-Za-z0-9\-_]*/[1-9][0-9]*(?:/[1-9][0-9]*)?(?:/[A-Za-z0-9\-_]+)?$#', $path)) {
            return null;
        }

        return 'https://'.self::BUTTERFLY_HOST.$path;
    }

    private function normalizeAbsoluteUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url));

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::BUTTERFLY_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null || $baseUrl === '') {
                return null;
            }

            $url = rtrim($baseUrl, '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return 'https://'.$this->normalizeHost((string) $parts['host']).$this->normalizePath((string) $parts['path']);
    }

    private function categoryRootUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));

        if (count($segments) >= 4 && $segments[0] === 'pl' && $segments[1] === 'c') {
            return 'https://'.self::BUTTERFLY_HOST.'/'.implode('/', array_slice($segments, 0, 4));
        }

        return rtrim($url, '/');
    }

    private function categoryPageNumber(string $url): int
    {
        $root = $this->categoryRootUrl($url);

        if ($root === rtrim($url, '/')) {
            return 1;
        }

        $rootPath = (string) parse_url($root, PHP_URL_PATH);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $suffix = trim(substr($path, strlen($rootPath)), '/');
        $segments = array_values(array_filter(explode('/', $suffix), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            if (preg_match('/^[1-9][0-9]*$/', $segment)) {
                return (int) $segment;
            }
        }

        return 1;
    }

    private function labelFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $slug = $segments[2] ?? end($segments) ?: 'Butterfly category';

        return $this->normalizeText(str_replace('-', ' ', rawurldecode((string) $slug)));
    }

    private function isButterflyUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::BUTTERFLY_HOST;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);

        return match ($host) {
            'www.butterfly-mag.com', self::BUTTERFLY_HOST => self::BUTTERFLY_HOST,
            default => $host,
        };
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return $path === '' ? '/' : $path;
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
