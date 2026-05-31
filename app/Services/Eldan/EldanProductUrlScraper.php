<?php

declare(strict_types=1);

namespace App\Services\Eldan;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class EldanProductUrlScraper
{
    public const DEFAULT_CATEGORY_URLS = [
        'https://eldan.pl/odziez-medyczna-damska',
        'https://eldan.pl/odziez-medyczna-meska',
        'https://eldan.pl/obuwie-medyczne',
        'https://eldan.pl/odziez-medyczna-dla-studentow',
    ];

    private const ELDAN_HOST = 'eldan.pl';

    /**
     * Eldan category pages are mostly SEO/navigation pages for non-JS clients.
     * Product URLs are usually exposed more reliably through XML sitemaps.
     */
    private const SITEMAP_URLS = [
        'https://eldan.pl/sitemap.xml',
        'https://eldan.pl/sitemap_index.xml',
        'https://eldan.pl/sitemap-index.xml',
        'https://eldan.pl/server-sitemap.xml',
        'https://eldan.pl/product-sitemap.xml',
        'https://eldan.pl/products-sitemap.xml',
        'https://eldan.pl/sitemap-products.xml',
        'https://eldan.pl/sitemap/product.xml',
        'https://eldan.pl/sitemap/products.xml',
    ];

    public function __construct(
        private readonly EldanProductPayloadExtractor $payloadExtractor,
    ) {
    }

    /**
     * Convenience wrapper for callers/tests that only need product URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(
        array $startUrls = self::DEFAULT_CATEGORY_URLS,
        int $maxDepth = 3,
        int $maxPages = 500,
        ?int $limit = null,
    ): array {
        return $this->scrape(
            startUrls: $startUrls,
            maxDepth: $maxDepth,
            maxPages: $maxPages,
            limit: $limit,
        )['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     product_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $startUrls = self::DEFAULT_CATEGORY_URLS,
        int $maxDepth = 3,
        int $maxPages = 500,
        ?int $limit = null,
    ): array {
        $queue = [];
        $visited = [];
        $failed = [];
        $productUrls = [];

        $sitemapResult = $this->discoverProductUrlsFromSitemaps(
            maxSitemapPages: min($maxPages, 50),
            limit: $limit,
        );

        $productUrls = array_fill_keys($sitemapResult['product_urls'], true);
        $visited = array_fill_keys($sitemapResult['visited_urls'], true);
        $failed = $sitemapResult['failed_urls'];

        if ($limit !== null && count($productUrls) >= $limit) {
            return [
                'product_urls' => array_slice(array_keys($productUrls), 0, $limit),
                'visited_urls' => array_keys($visited),
                'failed_urls' => $failed,
            ];
        }

        foreach ($startUrls as $url) {
            $normalized = $this->normalizeUrl($url);

            if ($normalized !== null && ! isset($visited[$normalized])) {
                $queue[] = [$normalized, 0];
            }
        }

        while ($queue !== [] && count($visited) < $maxPages) {
            [$url, $depth] = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            try {
                $response = Http::timeout(30)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $failed[$url] = $exception->getMessage();

                continue;
            }

            if (! $response->successful()) {
                $failed[$url] = 'HTTP '.$response->status();

                continue;
            }

            $html = $response->body();

            if ($this->isProductPage($html)) {
                $productUrls[$this->canonicalProductUrl($url)] = true;

                if ($limit !== null && count($productUrls) >= $limit) {
                    break;
                }

                continue;
            }

            $remainingLimit = $limit === null ? null : max($limit - count($productUrls), 0);

            if ($remainingLimit === null || $remainingLimit > 0) {
                $categoryApiResult = $this->discoverProductUrlsFromCategoryProductsApi(
                    html: $html,
                    categoryUrl: $url,
                    limit: $remainingLimit,
                );

                foreach ($categoryApiResult['product_urls'] as $productUrl) {
                    $productUrls[$productUrl] = true;
                }

                foreach ($categoryApiResult['visited_urls'] as $visitedUrl) {
                    $visited[$visitedUrl] = true;
                }

                $failed = array_replace($failed, $categoryApiResult['failed_urls']);

                if ($limit !== null && count($productUrls) >= $limit) {
                    break;
                }
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->extractCandidateUrls($html, $url) as $candidateUrl) {
                if (isset($visited[$candidateUrl])) {
                    continue;
                }

                $queue[] = [$candidateUrl, $depth + 1];
            }
        }

        $discoveredProductUrls = array_keys($productUrls);

        if ($limit !== null) {
            $discoveredProductUrls = array_slice($discoveredProductUrls, 0, $limit);
        }

        return [
            'product_urls' => $discoveredProductUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array{
     *     product_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    private function discoverProductUrlsFromSitemaps(int $maxSitemapPages, ?int $limit): array
    {
        $queue = self::SITEMAP_URLS;
        $visited = [];
        $failed = [];
        $productUrls = [];

        while ($queue !== [] && count($visited) < $maxSitemapPages) {
            $url = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            $body = $this->fetchBody($url, $failed);

            if ($body === null) {
                continue;
            }

            foreach ($this->extractSitemapLocUrls($body, $url) as $locUrl) {
                if ($this->looksLikeSitemapUrl($locUrl)) {
                    if (! isset($visited[$locUrl]) && count($visited) + count($queue) < $maxSitemapPages) {
                        $queue[] = $locUrl;
                    }

                    continue;
                }

                if (! $this->looksLikeProductUrl($locUrl)) {
                    continue;
                }

                $candidateHtml = $this->fetchBody($locUrl, $failed);

                if ($candidateHtml === null) {
                    continue;
                }

                $visited[$locUrl] = true;

                if (! $this->isProductPage($candidateHtml)) {
                    continue;
                }

                $productUrls[$this->canonicalProductUrl($locUrl)] = true;

                if ($limit !== null && count($productUrls) >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'product_urls' => array_keys($productUrls),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * Eldan category pages expose a Vue component such as:
     *
     * <v-category id="52" ...>
     *
     * The frontend then loads products from the category products API.
     *
     * @return array{
     *     product_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    private function discoverProductUrlsFromCategoryProductsApi(
        string $html,
        string $categoryUrl,
        ?int $limit,
    ): array {
        $categoryIds = $this->extractCategoryIds($html);

        if ($categoryIds === []) {
            return [
                'product_urls' => [],
                'visited_urls' => [],
                'failed_urls' => [],
            ];
        }

        $visited = [];
        $failed = [];
        $productUrls = [];

        foreach ($categoryIds as $categoryId) {
            $page = 1;
            $apiUrl = $this->categoryProductsApiUrl($categoryUrl, $categoryId, $page);

            while ($apiUrl !== null && ! isset($visited[$apiUrl])) {
                $visited[$apiUrl] = true;

                $body = $this->fetchBody($apiUrl, $failed);

                if ($body === null) {
                    break;
                }

                try {
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable $exception) {
                    $failed[$apiUrl] = 'Invalid JSON: '.$exception->getMessage();

                    break;
                }

                if (! is_array($payload)) {
                    $failed[$apiUrl] = 'Invalid JSON payload';

                    break;
                }

                foreach ($this->extractProductUrlsFromApiPayload($payload, $categoryUrl) as $productUrl) {
                    $productUrls[$productUrl] = true;

                    if ($limit !== null && count($productUrls) >= $limit) {
                        break 3;
                    }
                }

                $apiUrl = $this->nextCategoryProductsApiUrl($payload, $apiUrl, $page);
                $page++;
            }
        }

        return [
            'product_urls' => array_keys($productUrls),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function extractCategoryIds(string $html): array
    {
        $ids = [];
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $patterns = [
            '#<v-category\b[^>]*\bid=["\']?(\d+)["\']?[^>]*>#iu',
            '#\bdata-category-id=["\']?(\d+)["\']?#iu',
            '#["\']category_id["\']\s*:\s*["\']?(\d+)["\']?#iu',
            '#["\']categoryId["\']\s*:\s*["\']?(\d+)["\']?#iu',
            '#\bcategory_id\s*=\s*["\']?(\d+)["\']?#iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $decodedHtml, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $match) {
                $id = (int) $match;

                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function categoryProductsApiUrl(string $categoryUrl, int $categoryId, int $page): string
    {
        $parts = parse_url($categoryUrl);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? self::ELDAN_HOST;

        return $scheme.'://'.$host.'/api/products?category_id='.$categoryId.'&page='.$page;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nextCategoryProductsApiUrl(array $payload, string $currentUrl, int $currentPage): ?string
    {
        $next = $payload['links']['next']
            ?? $payload['next_page_url']
            ?? null;

        if (is_string($next) && trim($next) !== '') {
            return $this->normalizeUrl($next, $currentUrl);
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        $payloadCurrentPage = (int) ($meta['current_page'] ?? $payload['current_page'] ?? $currentPage);
        $lastPage = (int) ($meta['last_page'] ?? $payload['last_page'] ?? 0);

        if ($lastPage <= 0 || $payloadCurrentPage >= $lastPage) {
            return null;
        }

        return $this->replaceQueryParameter($currentUrl, 'page', (string) ($payloadCurrentPage + 1));
    }

    private function replaceQueryParameter(string $url, string $key, string $value): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);

        $query[$key] = $value;

        $path = $parts['path'] ?? '/';
        $queryString = http_build_query($query);

        return $parts['scheme'].'://'.$parts['host'].$path.($queryString !== '' ? '?'.$queryString : '');
    }

    /**
     * @param  mixed  $payload
     * @return array<int, string>
     */
    private function extractProductUrlsFromApiPayload(mixed $payload, string $baseUrl): array
    {
        $urls = [];

        $this->scanApiPayloadForProductUrls($payload, $urls, $baseUrl);

        return array_keys($urls);
    }

    /**
     * @param  mixed  $value
     * @param  array<string, bool>  $urls
     */
    private function scanApiPayloadForProductUrls(mixed $value, array &$urls, string $baseUrl): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ([
                     'url',
                     'href',
                     'product_url',
                     'canonical_url',
                     'url_key',
                     'slug',
                 ] as $key) {
            if (! isset($value[$key]) || ! is_scalar($value[$key])) {
                continue;
            }

            $url = $this->normalizeUrl((string) $value[$key], $baseUrl);

            if ($url === null || ! $this->isEldanUrl($url) || ! $this->looksLikeProductUrl($url)) {
                continue;
            }

            $urls[$this->canonicalProductUrl($url)] = true;
        }

        foreach ($value as $childValue) {
            if (is_array($childValue)) {
                $this->scanApiPayloadForProductUrls($childValue, $urls, $baseUrl);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractSitemapLocUrls(string $xml, string $baseUrl): array
    {
        $urls = [];

        if (preg_match_all('#<loc>\s*(.*?)\s*</loc>#isu', $xml, $matches) !== false) {
            foreach ($matches[1] as $rawUrl) {
                $url = $this->normalizeUrl(strip_tags((string) $rawUrl), $baseUrl);

                if ($url === null || ! $this->isEldanUrl($url)) {
                    continue;
                }

                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    private function looksLikeSitemapUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, 'sitemap')
            && (str_ends_with($path, '.xml') || str_contains($path, '.xml'));
    }

    private function looksLikeProductUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
        $query = mb_strtolower((string) parse_url($url, PHP_URL_QUERY));

        if ($path === '' || $path === '/') {
            return false;
        }

        foreach ($this->disallowedPathFragments() as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        if ($this->looksLikeDateOrAddressPath($path)) {
            return false;
        }

        if ($this->looksLikeNumericProductPath($path)) {
            return true;
        }

        if ($this->looksLikeCodeProductPath($path)) {
            return true;
        }

        return preg_match('#(?:^|&)v=\\d+(?:&|$)#', $query) === 1
            && ($this->looksLikeNumericProductPath($path) || $this->looksLikeCodeProductPath($path));
    }

    private function looksLikeNumericProductPath(string $path): bool
    {
        if (preg_match('#^/\\d{2,6}-([a-z][a-z0-9]+(?:-[a-z0-9]+)+)$#', $path, $matches) !== 1) {
            return false;
        }

        return preg_match('#[a-z]#', $matches[1]) === 1;
    }

    private function looksLikeCodeProductPath(string $path): bool
    {
        return preg_match('#^/[a-z][a-z0-9]*(?:-[a-z0-9]+)*-\\d{1,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+$#', $path) === 1;
    }

    private function looksLikeDateOrAddressPath(string $path): bool
    {
        return preg_match('#^/\\d{2}-\\d{3}$#', $path) === 1
            || preg_match('#^/\\d{4}-\\d{2}-\\d{2}t\\d{1,2}$#', $path) === 1
            || preg_match('#^/\\d{4}-\\d{2}-\\d{2}$#', $path) === 1;
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        try {
            $response = Http::timeout(30)
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
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function isProductPage(string $html): bool
    {
        try {
            $payload = $this->payloadExtractor->extract($html);
        } catch (Throwable) {
            return false;
        }

        return filled($payload['product']['id'] ?? null)
            && filled($payload['product']['name'] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function extractCandidateUrls(string $html, string $baseUrl): array
    {
        $urls = [];

        try {
            $crawler = new Crawler($html, $baseUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                $href = $node->attr('href');

                if (! is_string($href) || trim($href) === '') {
                    return;
                }

                $url = $this->normalizeUrl($href, $baseUrl);

                if ($url === null || ! $this->isEldanUrl($url)) {
                    return;
                }

                if (! $this->looksLikeCatalogUrl($url)) {
                    return;
                }

                $urls[$url] = true;
            });
        } catch (Throwable) {
            // Keep going: Eldan sometimes exposes catalogue data inside scripts
            // rather than plain anchors, so the raw scan below is still useful.
        }

        foreach ($this->extractRawCandidateUrls($html, $baseUrl) as $url) {
            $urls[$url] = true;
        }

        if ($this->htmlLooksPaginated($html)) {
            foreach ($this->generatedPaginationUrls($baseUrl) as $paginationUrl) {
                if ($this->looksLikeCatalogUrl($paginationUrl)) {
                    $urls[$paginationUrl] = true;
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * Eldan's category pages can contain product URLs inside JavaScript/JSON
     * payloads. Symfony DomCrawler only sees real <a href="..."> links, so
     * we also scan the raw HTML after decoding common escaping forms.
     *
     * @return array<int, string>
     */
    private function extractRawCandidateUrls(string $html, string $baseUrl): array
    {
        $urls = [];
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decodedHtml = str_replace(['\\/', '\/'], '/', $decodedHtml);

        foreach ($this->rawUrlPatterns() as $pattern) {
            if (preg_match_all($pattern, $decodedHtml, $matches) !== false) {
                $rawMatches = $matches[1] ?? $matches[0];

                foreach ($rawMatches as $match) {
                    $url = $this->normalizeUrl($this->trimRawUrlMatch((string) $match), $baseUrl);

                    if ($url === null || ! $this->isEldanUrl($url)) {
                        continue;
                    }

                    if (! $this->looksLikeCatalogUrl($url)) {
                        continue;
                    }

                    $urls[$url] = true;
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * @return array<int, string>
     */
    private function rawUrlPatterns(): array
    {
        return [
            '#https?://(?:www\.)?eldan\.pl/[^\s"\'<>\\)]+#iu',
            '#["\'](?:url|product_url|canonical_url|url_key|slug)["\']\s*:\s*["\'](https?://(?:www\.)?eldan\.pl/[^"\'<>]+|/[^"\'<>]+|\d{2,6}-[a-z0-9][a-z0-9\-]*(?:\?v=\d+)?|[a-z][a-z0-9]*(?:-[a-z0-9]+)*-\d{1,6}-[a-z0-9][a-z0-9\-]*(?:\?v=\d+)?)["\']#iu',
            '#(?<![a-z0-9/_-])/\d{2,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+(?:\?v=[0-9]+)?#iu',
            '#(?<![a-z0-9/_-])\d{2,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+(?:\?v=[0-9]+)?#iu',
            '#(?<![a-z0-9/_-])/[a-z][a-z0-9]*(?:-[a-z0-9]+)*-\d{1,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+(?:\?v=[0-9]+)?#iu',
            '#(?<![a-z0-9/_-])[a-z][a-z0-9]*(?:-[a-z0-9]+)*-\d{1,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+(?:\?v=[0-9]+)?#iu',
            '#(?<![a-z0-9])/(?:odziez|obuwie|bluzy|sukienki|fartuchy|spodnie|spodnice|polary|czepki|scrub)[a-z0-9\-]*(?:\?page=[0-9]+|\?p=[0-9]+)?#iu',
        ];
    }

    private function trimRawUrlMatch(string $url): string
    {
        return trim(rtrim($url, '.,;:)]}'), '"\'');
    }

    private function htmlLooksPaginated(string $html): bool
    {
        $html = mb_strtolower($html);

        return str_contains($html, 'pagination')
            || str_contains($html, 'rel="next"')
            || str_contains($html, "rel='next'")
            || str_contains($html, '?page=')
            || str_contains($html, '&page=')
            || str_contains($html, '?p=')
            || str_contains($html, '&p=');
    }

    /**
     * Eldan category pages may expose explicit pagination links, but this also
     * probes the two common query parameter styles without making the command
     * depend on one exact frontend implementation.
     *
     * @return array<int, string>
     */
    private function generatedPaginationUrls(string $baseUrl): array
    {
        $parts = parse_url($baseUrl);

        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return [];
        }

        parse_str($parts['query'] ?? '', $query);

        $currentPage = (int) ($query['page'] ?? $query['p'] ?? 1);

        if ($currentPage < 1) {
            $currentPage = 1;
        }

        $nextPage = $currentPage + 1;
        $base = $parts['scheme'].'://'.$parts['host'].$parts['path'];

        return [
            $base.'?page='.$nextPage,
            $base.'?p='.$nextPage,
        ];
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
            $url = 'https://'.self::ELDAN_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($this->looksLikeRelativeProductSlug($url)) {
                $url = 'https://'.self::ELDAN_HOST.'/'.$url;
            } else {
                if ($baseUrl === null) {
                    return null;
                }

                $base = parse_url($baseUrl);

                if (! isset($base['scheme'], $base['host'])) {
                    return null;
                }

                $basePath = $base['path'] ?? '/';
                $directory = rtrim(str_replace(basename($basePath), '', $basePath), '/');

                $url = $base['scheme'].'://'.$base['host'].$directory.'/'.$url;
            }
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $parts['scheme'].'://'.$parts['host'].$path.$query;
    }

    private function isEldanUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === self::ELDAN_HOST || $host === 'www.'.self::ELDAN_HOST;
    }

    private function looksLikeRelativeProductSlug(string $value): bool
    {
        $path = mb_strtolower(strtok($value, '?') ?: $value);

        return preg_match('#^\d{2,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+$#', $path) === 1
            || preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*-\d{1,6}-[a-z][a-z0-9]+(?:-[a-z0-9]+)+$#', $path) === 1;
    }

    private function looksLikeCatalogUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($path === '' || $path === '/') {
            return false;
        }

        foreach ($this->disallowedPathFragments() as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        if ($this->looksLikeProductUrl($url)) {
            return true;
        }

        foreach ($this->allowedCatalogFragments() as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalProductUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '/');
    }

    /**
     * @return array<int, string>
     */
    private function allowedCatalogFragments(): array
    {
        return [
            'odziez',
            'medycz',
            'obuwie',
            'buty',
            'klapki',
            'bluzy',
            'bluza',
            'sukien',
            'fartuch',
            'polar',
            'spodnie',
            'spodnica',
            'student',
            'czepki',
            'scrub',
            'koszul',
            'outlet',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function disallowedPathFragments(): array
    {
        return [
            '/account',
            '/customer',
            '/checkout',
            '/cart',
            '/koszyk',
            '/login',
            '/register',
            '/wishlist',
            '/search',
            '/kontakt',
            '/regulamin',
            '/polityka',
            '/platnosc',
            '/dostawa',
            '/faq',
            '/zwrot',
            '/reklamac',
            '/newsletter',
            '/media/',
            '/storage/',
        ];
    }
}
