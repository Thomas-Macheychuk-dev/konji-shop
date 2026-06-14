<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class PerukaProductUrlScraper
{
    private const PERUKA_HOST = 'www.peruka.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    public function __construct(
        private readonly PerukaCategoryUrlScraper $categoryScraper,
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
     * @param  array<int, string>  $categoryUrls
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $categoryUrls = [], array $startUrls = [PerukaCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape(categoryUrls: $categoryUrls, startUrls: $startUrls)['product_urls'];
    }

    /**
     * Discover Peruka product URLs from category pages.
     *
     * Product links are expected in anchors such as:
     * <a href="/orbit-chocolate-mix.html" class="product_name">...</a>
     *
     * @param  array<int, string>  $categoryUrls Explicit Peruka category URLs to scan. If empty, categories are discovered from $startUrls.
     * @param  array<int, string>  $startUrls Peruka pages used for category discovery when $categoryUrls is empty.
     * @return array{
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string}>,
     *     category_urls: array<int, string>,
     *     category_results: array<int, array{name: string|null, url: string, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $categoryUrls = [],
        array $startUrls = [PerukaCategoryUrlScraper::DEFAULT_URL],
        bool $discoverCategories = true,
        ?int $limit = null,
        ?int $maxPages = null,
        ?int $categoryLimit = null,
    ): array {
        $categories = [];
        $visited = [];
        $failed = [];

        foreach ($categoryUrls as $categoryUrl) {
            $url = $this->normalizeCategoryPageUrl($categoryUrl);

            if ($url === null) {
                continue;
            }

            $rootCategoryUrl = $this->rootCategoryUrl($url);
            $categories[$rootCategoryUrl] = [
                'name' => $this->labelFromUrl($rootCategoryUrl),
                'url' => $rootCategoryUrl,
            ];
        }

        if ($categories === [] && $discoverCategories) {
            $this->emit('Discovering Peruka categories...');
            $categoryDiscoveryResult = $this->categoryScraper->scrape($startUrls);
            $visited = array_fill_keys($categoryDiscoveryResult['visited_urls'], true);
            $failed = $categoryDiscoveryResult['failed_urls'];

            foreach ($categoryDiscoveryResult['categories'] as $category) {
                $categories[$category['url']] = [
                    'name' => $category['name'],
                    'url' => $category['url'],
                ];
            }
        }

        $this->emit('Peruka categories selected for product-link scraping: '.count($categories));

        return $this->scrapeCategoryRecords(array_values($categories), $visited, $failed, $limit, $maxPages, $categoryLimit);
    }

    /**
     * @param  array<int, array{name: string|null, url: string}>  $categories
     * @param  array<string, true>  $initialVisited
     * @param  array<string, string>  $initialFailed
     * @return array{
     *     product_urls: array<int, string>,
     *     products: array<int, array{url: string, name: string, category_name: string|null, category_url: string}>,
     *     category_urls: array<int, string>,
     *     category_results: array<int, array{name: string|null, url: string, visited_urls: array<int, string>, product_urls: array<int, string>, product_count: int}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    private function scrapeCategoryRecords(array $categories, array $initialVisited, array $initialFailed, ?int $limit, ?int $maxPages, ?int $categoryLimit): array
    {
        $visited = $initialVisited;
        $failed = $initialFailed;
        $products = [];
        $categoryResults = [];
        $categoryUrls = [];
        $totalCategories = count($categories);
        $categoryNumber = 0;

        foreach ($categories as $category) {
            if ($categoryLimit !== null && $categoryNumber >= $categoryLimit) {
                $this->emit('Category limit reached: '.$categoryLimit);
                break;
            }

            if ($limit !== null && count($products) >= $limit) {
                break;
            }

            $categoryNumber++;
            $categoryUrl = $this->normalizeCategoryPageUrl($category['url']);

            if ($categoryUrl === null) {
                continue;
            }

            $categoryUrls[$this->rootCategoryUrl($categoryUrl)] = true;
            $this->emit('Scraping Peruka category '.$categoryNumber.'/'.$totalCategories.': '.($category['name'] ?? $categoryUrl));
            $this->emit('  '.$categoryUrl);

            $categoryPageUrls = [];
            $categoryProductUrls = [];
            $nextUrl = $categoryUrl;
            $pagesScraped = 0;

            while ($nextUrl !== null && ! isset($categoryPageUrls[$nextUrl])) {
                if ($limit !== null && count($products) >= $limit) {
                    break;
                }

                if ($maxPages !== null && $pagesScraped >= $maxPages) {
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
                    $url = $product['url'];
                    $categoryProductUrls[$url] = true;

                    if (! isset($products[$url])) {
                        $products[$url] = [
                            'url' => $url,
                            'name' => $product['name'],
                            'category_name' => $category['name'] ?? null,
                            'category_url' => $this->rootCategoryUrl($categoryUrl),
                        ];
                    } elseif ($products[$url]['name'] === '' && $product['name'] !== '') {
                        $products[$url]['name'] = $product['name'];
                    }

                    if ($limit !== null && count($products) >= $limit) {
                        break;
                    }
                }

                if ($limit !== null && count($products) >= $limit) {
                    break;
                }

                if ($pageProducts === []) {
                    $this->emit('  Stopping pagination: page has no product links.');
                    break;
                }

                $nextUrl = $this->extractNextCategoryPageUrl($html, $nextUrl);
            }

            $this->emit('  Category total product links: '.count($categoryProductUrls));

            $categoryResults[] = [
                'name' => $category['name'] ?? null,
                'url' => $this->rootCategoryUrl($categoryUrl),
                'visited_urls' => array_keys($categoryPageUrls),
                'product_urls' => array_keys($categoryProductUrls),
                'product_count' => count($categoryProductUrls),
            ];
        }

        return [
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'category_urls' => array_keys($categoryUrls),
            'category_results' => $categoryResults,
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
            $anchors = $crawler->filter('a.product_name[href], .product-list p.name a[href], .product-list .image a[href]');

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

        $href = $node->attr('href');

        return is_string($href) ? $this->labelFromUrl($href) : '';
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

        if ($url === null || ! $this->isPerukaUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/[a-z0-9][a-z0-9\-]*\.html$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::PERUKA_HOST.$path;
    }

    private function normalizeCategoryPageUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isPerukaUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/category/[a-z0-9][a-z0-9\-]*(?:/[1-9][0-9]*)?$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::PERUKA_HOST.$path;
    }

    private function rootCategoryUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);
        $path = preg_replace('#^(/category/[a-z0-9][a-z0-9\-]*)/[1-9][0-9]*$#', '$1', $path) ?: $path;

        return 'https://'.self::PERUKA_HOST.$path;
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
            $url = 'https://'.self::PERUKA_HOST.$url;
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

        $path = $parts['path'] ?? '/';

        return 'https://'.$host.$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
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

        if ($slug === '') {
            return '';
        }

        $slug = preg_replace('/\.html$/', '', $slug) ?: $slug;

        return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
    }

    private function isPerukaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::PERUKA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            'peruka.pl', self::PERUKA_HOST => self::PERUKA_HOST,
            default => null,
        };
    }
}
