<?php

declare(strict_types=1);

namespace App\Services\RehaFund;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class RehaFundCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://sklep.rehafund.pl/';

    private const REHAFUND_HOST = 'sklep.rehafund.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    private int $maxPages = 250;

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

    public function withMaxPages(int $pages): self
    {
        $this->maxPages = max(1, $pages);

        return $this;
    }

    /**
     * Convenience wrapper for callers/tests that only need product-scraping category URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * Discover RehaFund category hierarchy from the Comarch category sidebar.
     *
     * RehaFund exposes catalogue categories under the left-hand `Kategorie` navigation.
     * Category URLs follow the Comarch shape `/produkty/.../2-{id}`; product URLs use
     * `/3-...` and are excluded here. Parent category pages can also list products, so
     * every discovered category URL is returned as a product-scraping URL for the next
     * pipeline step.
     *
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
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $visited = [];
        $failed = [];
        $normalizedStartUrls = [];
        $categoriesByUrl = [];
        $queue = [];
        $queued = [];
        $order = 0;

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isRehaFundUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;
            $queue[] = $url;
            $queued[$url] = true;
        }

        while ($queue !== [] && count($visited) < $this->maxPages) {
            $url = array_shift($queue);

            if (! is_string($url) || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching RehaFund category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractCategoryCandidates($html, $url) as $candidate) {
                $candidateUrl = $candidate['url'];

                if (! isset($categoriesByUrl[$candidateUrl])) {
                    $categoriesByUrl[$candidateUrl] = [
                        'source_name' => $candidate['name'],
                        'url' => $candidateUrl,
                        'product_count' => $candidate['product_count'],
                        'discovery_order' => $order++,
                    ];
                }

                if (! isset($visited[$candidateUrl], $queued[$candidateUrl]) && count($visited) + count($queue) < $this->maxPages) {
                    $queue[] = $candidateUrl;
                    $queued[$candidateUrl] = true;
                }
            }
        }

        $categories = $this->buildCategories($categoriesByUrl);
        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => $category['level'] === 1
        ));
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => $category['url'],
            $categories
        ));

        return [
            'source' => 'rehafund',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        try {
            $this->pauseBeforeRequest();

            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeoutSeconds)
                ->get($url);

            if (! $response->successful()) {
                $failed[$url] = 'HTTP '.$response->status();

                return null;
            }

            return $response->body();
        } catch (Throwable $exception) {
            $failed[$url] = $exception->getMessage();

            return null;
        }
    }

    /**
     * @return array<int, array{url: string, name: string, product_count: int|null}>
     */
    private function extractCategoryCandidates(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $scope = $this->categoryScope($crawler);
        $categoriesByUrl = [];

        $scope->filter('a[href]')->each(function (Crawler $node) use (&$categoriesByUrl, $baseUrl): void {
            $url = $this->normalizeUrl((string) $node->attr('href'), $baseUrl);

            if ($url === null || ! $this->isCategoryUrl($url)) {
                return;
            }

            $name = $this->categoryName($node);

            if ($name === '') {
                return;
            }

            $categoriesByUrl[$url] = [
                'url' => $url,
                'name' => $name,
                'product_count' => $this->productCount($node),
            ];
        });

        return array_values($categoriesByUrl);
    }

    private function categoryScope(Crawler $crawler): Crawler
    {
        foreach ([
            'nav.category-column-ui',
            '.categories-and-filters-container-ui',
            '.category-content-ui',
            '.sliding-categories-ui',
        ] as $selector) {
            $matches = $crawler->filter($selector);

            if ($matches->count() > 0) {
                return $matches;
            }
        }

        return $crawler;
    }

    private function categoryName(Crawler $node): string
    {
        $nameNode = $node->filter('.category-name-ui');
        $name = $nameNode->count() > 0
            ? $nameNode->first()->text('', true)
            : ((string) $node->attr('title') ?: $node->text('', true));

        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/\s*\(\d+\)\s*$/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function productCount(Crawler $node): ?int
    {
        $amountNode = $node->filter('.category-amount-ui');

        if ($amountNode->count() === 0) {
            return null;
        }

        $text = $amountNode->first()->text('', true);

        if (! preg_match('/\d+/u', $text, $matches)) {
            return null;
        }

        return (int) $matches[0];
    }

    /**
     * @param  array<string, array{source_name: string, url: string, product_count: int|null, discovery_order: int}>  $categoriesByUrl
     * @return array<int, array<string, mixed>>
     */
    private function buildCategories(array $categoriesByUrl): array
    {
        uasort(
            $categoriesByUrl,
            static fn (array $a, array $b): int => $a['discovery_order'] <=> $b['discovery_order']
        );

        $categoryPathByUrl = [];
        $urlByCategoryPath = [];

        foreach ($categoriesByUrl as $url => $category) {
            $pathSegments = $this->categoryPathSegments($url);
            $pathKey = implode('/', $pathSegments);

            $categoryPathByUrl[$url] = $pathSegments;
            $urlByCategoryPath[$pathKey] = $url;
        }

        $namesByUrl = [];

        foreach ($categoriesByUrl as $url => $category) {
            $namesByUrl[$url] = $category['source_name'];
        }

        $categories = [];

        foreach ($categoriesByUrl as $url => $category) {
            $pathSegments = $categoryPathByUrl[$url] ?? [];
            $level = max(1, count($pathSegments));
            $parentUrl = null;

            if (count($pathSegments) > 1) {
                $parentPathKey = implode('/', array_slice($pathSegments, 0, -1));
                $parentUrl = $urlByCategoryPath[$parentPathKey] ?? null;
            }

            $externalCategoryId = $this->externalCategoryIdFromUrl($url);
            $parentExternalCategoryId = is_string($parentUrl)
                ? $this->externalCategoryIdFromUrl($parentUrl)
                : null;

            $path = [];

            if (is_string($parentUrl) && isset($namesByUrl[$parentUrl])) {
                $path[] = $namesByUrl[$parentUrl];
            }

            $path[] = $category['source_name'];

            $categories[] = [
                'source' => 'rehafund',
                'external_category_id' => $externalCategoryId,
                'name' => $category['source_name'],
                'source_name' => $category['source_name'],
                'url' => $url,
                'slug' => Str::slug($category['source_name']),
                'level' => $level,
                'parent_url' => $parentUrl,
                'parent_external_category_id' => $parentExternalCategoryId,
                'path' => $path,
                'product_count' => $category['product_count'],
            ];
        }

        return $categories;
    }

    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || Str::startsWith($url, ['#', 'javascript:', 'mailto:', 'tel:'])) {
            return null;
        }

        if (Str::startsWith($url, '//')) {
            $url = 'https:'.$url;
        } elseif (Str::startsWith($url, '/')) {
            $url = 'https://'.self::REHAFUND_HOST.$url;
        } elseif (! Str::startsWith($url, ['http://', 'https://'])) {
            $baseParts = parse_url($baseUrl);
            $baseHost = is_string($baseParts['host'] ?? null) ? $baseParts['host'] : self::REHAFUND_HOST;
            $url = 'https://'.$baseHost.'/'.ltrim($url, '/');
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
        $normalizedPath = $this->normalizePath($path);

        return 'https://'.$host.($normalizedPath === '/' ? '/' : $normalizedPath);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function isCategoryUrl(string $url): bool
    {
        if (! $this->isRehaFundUrl($url)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! str_starts_with($path, '/produkty/')) {
            return false;
        }

        if (preg_match('#/3-[0-9-]+$#', $path) === 1) {
            return false;
        }

        return preg_match('#/2-\d+$#', $path) === 1;
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

    /**
     * @return array<int, string>
     */
    private function categoryPathSegments(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if ($segments === [] || $segments[0] !== 'produkty') {
            return [];
        }

        array_shift($segments);

        if ($segments !== [] && preg_match('/^2-\d+$/', (string) end($segments)) === 1) {
            array_pop($segments);
        }

        return array_values(array_map(
            static fn (string $segment): string => Str::slug($segment),
            $segments
        ));
    }

    private function externalCategoryIdFromUrl(string $url): string
    {
        if (preg_match('#/2-(\d+)$#', (string) parse_url($url, PHP_URL_PATH), $matches) === 1) {
            return $matches[1];
        }

        $segments = $this->categoryPathSegments($url);

        return $segments === [] ? Str::slug($url) : implode('/', $segments);
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopRehaFundScraper/1.0; +https://konji.pl)',
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
