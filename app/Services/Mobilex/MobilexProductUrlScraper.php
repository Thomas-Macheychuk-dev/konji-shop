<?php

declare(strict_types=1);

namespace App\Services\Mobilex;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MobilexProductUrlScraper
{
    private const MOBILEX_HOST = 'mobilex.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly MobilexCategoryUrlScraper $categoryScraper,
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
    public function discover(array $startUrls = [MobilexCategoryUrlScraper::DEFAULT_PRODUCTS_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * Discover Mobilex product links from the product-category hierarchy.
     *
     * Product links are scraped from the product-scraping category URLs returned by
     * MobilexCategoryUrlScraper::scrapeHierarchy(): lower categories when they exist,
     * otherwise the top category itself.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int}>,
     *     source_categories: array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(array $startUrls = [MobilexCategoryUrlScraper::DEFAULT_PRODUCTS_URL], ?int $pageLimit = null, ?int $categoryLimit = null): array
    {
        $this->emit('Discovering Mobilex category hierarchy...');
        $hierarchy = $this->categoryScraper->scrapeHierarchy($startUrls);
        $this->emit('Product-scraping categories found: '.count($hierarchy['categories']));

        return $this->scrapeCategoryRecords($hierarchy['categories'], $hierarchy['visited_urls'], $hierarchy['failed_urls'], $pageLimit, $categoryLimit);
    }

    /**
     * Scrape product links from explicit category URLs, without first discovering the hierarchy.
     * This is useful for testing one category page while developing the importer.
     *
     * @param  array<int, string>  $categoryUrls
     * @return array{
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int}>,
     *     source_categories: array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null}>,
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
                'parent_name' => null,
                'parent_url' => null,
            ];
        }

        return $this->scrapeCategoryRecords(array_values($categories), [], [], $pageLimit, $categoryLimit);
    }

    /**
     * @param  array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null}>  $categories
     * @param  array<int, string>  $initialVisitedUrls
     * @param  array<string, string>  $initialFailedUrls
     * @return array{
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string, top_category_name: string|null, top_category_url: string|null}>,
     *     category_results: array<int, array{name: string|null, url: string, top_category_name: string|null, top_category_url: string|null, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int}>,
     *     source_categories: array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    private function scrapeCategoryRecords(array $categories, array $initialVisitedUrls, array $initialFailedUrls, ?int $pageLimit, ?int $categoryLimit = null): array
    {
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

            $this->emit('Scraping category '.$categoryNumber.'/'.$totalCategories.': '.$category['name']);
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
                            'category_name' => $category['name'],
                            'category_url' => $categoryUrl,
                            'top_category_name' => $category['parent_name'] ?? $category['name'],
                            'top_category_url' => $category['parent_url'] ?? $categoryUrl,
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
                'name' => $category['name'],
                'url' => $categoryUrl,
                'top_category_name' => $category['parent_name'] ?? $category['name'],
                'top_category_url' => $category['parent_url'] ?? $categoryUrl,
                'visited_urls' => array_keys($categoryPageUrls),
                'product_urls' => array_keys($categoryProductUrls),
                'product_count' => count($categoryProductUrls),
            ];
        }

        return [
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'category_results' => $categoryResults,
            'source_categories' => array_values($categories),
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

    /**
     * @return array<int, array{url: string, name: string}>
     */
    private function extractProducts(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
            $anchors = $crawler->filter('article.produkty a[href], .loops-wrapper.produkty article a[href], .builder-posts-wrap article.produkty a[href]');

            if ($anchors->count() === 0) {
                $anchors = $crawler->filter('a[href]');
            }

            return $this->extractProductsFromAnchors($anchors, $baseUrl);
        } catch (Throwable) {
            return [];
        }
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

        return '';
    }

    private function extractNextCategoryPageUrl(string $html, string $baseUrl): ?string
    {
        try {
            $crawler = new Crawler($html, $baseUrl);

            foreach (['link[rel="next"][href]', 'a[rel="next"][href]', 'a.next[href]', 'a.next.page-numbers[href]'] as $selector) {
                $node = $crawler->filter($selector)->first();

                if ($node->count() === 0) {
                    continue;
                }

                $href = $node->attr('href');

                if (! is_string($href)) {
                    continue;
                }

                return $this->normalizeCategoryPageUrl($href, $baseUrl);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isMobilexUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/produkty/[a-z0-9][a-z0-9\-/]*/$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::MOBILEX_HOST.$path;
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isMobilexUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if ($path === '/obuwie-scholl/'
            || preg_match('#^/kategoria-produktu/[a-z0-9][a-z0-9\-]*/(?:page/[1-9][0-9]*/)?$#', $path) === 1) {
            return 'https://'.self::MOBILEX_HOST.$path;
        }

        return null;
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
            $url = 'https://'.self::MOBILEX_HOST.$url;
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

        $path = $parts['path'] ?? '/';

        return 'https://'.mb_strtolower((string) $parts['host']).$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function labelFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = (string) end($segments);

        if ($slug === '' || $slug === 'page') {
            return '';
        }

        return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
    }

    private function isMobilexUrl(string $url): bool
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_HOST)) === self::MOBILEX_HOST;
    }
}
