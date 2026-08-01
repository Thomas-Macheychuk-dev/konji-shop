<?php

declare(strict_types=1);

namespace App\Services\Vermeiren;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class VermeirenProductUrlScraper
{
    private const VERMEIREN_HOST = 'www.vermeiren.pl';

    private const BASE_URL = 'https://www.vermeiren.pl/web/web.nsf/';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 2000;

    private int $requestDelayMilliseconds = 0;

    private bool $verifyTls = true;

    public function __construct(
        private readonly VermeirenCategoryUrlScraper $categoryScraper,
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

    public function withAttempts(int $attempts): self
    {
        $this->attempts = max(1, $attempts);
        $this->categoryScraper->withAttempts($this->attempts);

        return $this;
    }

    public function withRetryDelayMilliseconds(int $milliseconds): self
    {
        $this->retryDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRetryDelayMilliseconds($this->retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        $this->categoryScraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->verifyTls = $verify;
        $this->categoryScraper->withTlsVerification($verify);

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [VermeirenCategoryUrlScraper::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(
        array $startUrls = [VermeirenCategoryUrlScraper::DEFAULT_URL],
        ?int $categoryLimit = null,
    ): array {
        return $this->scrapeFromDiscoveredCategories(
            $this->categoryScraper->scrape($startUrls),
            $categoryLimit,
        );
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    public function scrapeFromDiscoveredCategories(array $discovery, ?int $categoryLimit = null): array
    {
        $productCategoryUrls = [];

        foreach (($discovery['product_category_urls'] ?? []) as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = $this->normalizeUrl($url, self::BASE_URL);

            if ($normalized !== null && $this->isCategoryUrl($normalized)) {
                $productCategoryUrls[$normalized] = true;
            }
        }

        $categories = [];

        foreach (($discovery['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeUrl((string) ($category['url'] ?? ''), self::BASE_URL);

            if ($url === null || ! $this->isCategoryUrl($url)) {
                continue;
            }

            $isProductCategory = ($category['is_product_category'] ?? null) === true
                || isset($productCategoryUrls[$url]);

            if (! $isProductCategory) {
                continue;
            }

            $categories[$url] = $this->categoryRecord($url, $category);
        }

        foreach (array_keys($productCategoryUrls) as $url) {
            if (! isset($categories[$url])) {
                $categories[$url] = $this->categoryRecord($url);
            }
        }

        return $this->scrapeCategoryRecords(
            array_values($categories),
            $categoryLimit,
            $this->stringMap($discovery['failed_urls'] ?? []),
        );
    }

    /**
     * @param  array<int, string>  $categoryUrls
     * @return array<string, mixed>
     */
    public function scrapeCategories(array $categoryUrls, ?int $categoryLimit = null): array
    {
        $categories = [];

        foreach ($categoryUrls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = $this->normalizeUrl($url, self::BASE_URL);

            if ($normalized !== null && $this->isCategoryUrl($normalized)) {
                $categories[$normalized] = $this->categoryRecord($normalized);
            }
        }

        return $this->scrapeCategoryRecords(array_values($categories), $categoryLimit);
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string, string>  $initialFailedUrls
     * @return array<string, mixed>
     */
    private function scrapeCategoryRecords(
        array $categories,
        ?int $categoryLimit = null,
        array $initialFailedUrls = [],
    ): array {
        if ($categoryLimit !== null) {
            $categories = array_slice($categories, 0, max(1, $categoryLimit));
        }

        $queue = array_values($categories);
        $queuedUrls = [];

        foreach ($queue as $category) {
            $url = (string) ($category['url'] ?? '');

            if ($url !== '') {
                $queuedUrls[$url] = true;
            }
        }

        $products = [];
        $categoryResults = [];
        $sourceCategories = [];
        $visitedUrls = [];
        $failedUrls = $initialFailedUrls;

        for ($index = 0; $index < count($queue); $index++) {
            $category = $queue[$index];
            $categoryUrl = (string) ($category['url'] ?? '');

            if ($categoryUrl === '' || isset($visitedUrls[$categoryUrl])) {
                continue;
            }

            $sourceCategories[$categoryUrl] = $category;
            $this->emit(sprintf(
                'Scraping Vermeiren category %d/%d: %s',
                $index + 1,
                count($queue),
                $this->categoryPathLabel($category),
            ));
            $this->emit('  '.$categoryUrl);
            $this->emit('  Fetching page 1: '.$categoryUrl);

            $visitedUrls[$categoryUrl] = true;
            $html = $this->fetchBody($categoryUrl, $failedUrls);
            $pageProducts = $html === null ? [] : $this->extractProducts($html, $categoryUrl);
            $childCategories = $html === null ? [] : $this->extractChildCategories($html, $categoryUrl, $category);

            $this->emit('  Page 1 product links: '.count($pageProducts));

            if ($childCategories !== []) {
                $this->emit('  Nested product categories: '.count($childCategories));
            }

            foreach ($childCategories as $childCategory) {
                $childUrl = (string) $childCategory['url'];

                if (isset($queuedUrls[$childUrl]) || isset($visitedUrls[$childUrl])) {
                    continue;
                }

                $queuedUrls[$childUrl] = true;
                $queue[] = $childCategory;
            }

            $categoryProductUrls = [];

            foreach ($pageProducts as $product) {
                $productUrl = (string) $product['url'];
                $categoryProductUrls[$productUrl] = true;

                if (! isset($products[$productUrl])) {
                    $products[$productUrl] = $product + [
                        'source' => 'vermeiren',
                        'category_urls' => [$categoryUrl],
                        'category_paths' => [$category['path']],
                    ];

                    continue;
                }

                if ($products[$productUrl]['name'] === '' && $product['name'] !== '') {
                    $products[$productUrl]['name'] = $product['name'];
                }

                if (! in_array($categoryUrl, $products[$productUrl]['category_urls'], true)) {
                    $products[$productUrl]['category_urls'][] = $categoryUrl;
                }

                if (! in_array($category['path'], $products[$productUrl]['category_paths'], true)) {
                    $products[$productUrl]['category_paths'][] = $category['path'];
                }
            }

            $categoryResults[] = [
                'category' => $category,
                'source' => 'vermeiren',
                'external_category_id' => (string) ($category['external_category_id'] ?? $this->categoryExternalIdFromUrl($categoryUrl)),
                'name' => (string) ($category['name'] ?? $this->categoryNameFromUrl($categoryUrl)),
                'url' => $categoryUrl,
                'category_path' => $category['path'],
                'page_urls' => [$categoryUrl],
                'pages_scraped' => 1,
                'failed_page_urls' => $html === null ? [$categoryUrl => ($failedUrls[$categoryUrl] ?? 'Unknown HTTP failure')] : [],
                'failed_page_count' => $html === null ? 1 : 0,
                'child_category_urls' => array_values(array_map(
                    static fn (array $child): string => (string) $child['url'],
                    $childCategories,
                )),
                'child_category_count' => count($childCategories),
                'product_urls' => array_keys($categoryProductUrls),
                'product_count' => count($categoryProductUrls),
            ];
        }

        $productRecords = array_values($products);

        return [
            'source' => 'vermeiren',
            'source_categories' => array_values($sourceCategories),
            'category_results' => $categoryResults,
            'products' => $productRecords,
            'product_urls' => array_values(array_map(
                static fn (array $product): string => (string) $product['url'],
                $productRecords,
            )),
            'visited_urls' => array_keys($visitedUrls),
            'failed_urls' => $failedUrls,
        ];
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        $lastFailure = 'Unknown HTTP failure';

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $request = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers());

                if (! $this->verifyTls) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->emitRetry($url, $attempt, $lastFailure);
                    $this->pauseBeforeRetry();

                    continue;
                }

                break;
            }

            if ($response->successful()) {
                unset($failed[$url]);

                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->attempts && ($response->status() === 429 || $response->serverError())) {
                $this->emitRetry($url, $attempt, $lastFailure);
                $this->pauseBeforeRetry();

                continue;
            }

            break;
        }

        $failed[$url] = $lastFailure;

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractProducts(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $products = [];

        $crawler->filter('a[href]')->each(function (Crawler $anchor) use (&$products, $baseUrl): void {
            $url = $this->normalizeUrl((string) $anchor->attr('href'), $baseUrl);

            if ($url === null || ! $this->isProductUrl($url)) {
                return;
            }

            $reference = $this->parseProductReference($url);

            if ($reference === null) {
                return;
            }

            $name = $this->normalizeText($anchor->text(''));

            if ($name === '') {
                $image = $anchor->filter('img')->first();

                if ($image->count() > 0) {
                    $name = $this->normalizeText(
                        (string) ($image->attr('alt') ?: $image->attr('title') ?: '')
                    );
                }
            }

            if ($name === '') {
                $name = (string) $reference['selected_name'];
            }

            if (! isset($products[$url])) {
                $products[$url] = $reference + [
                    'name' => $name,
                    'url' => $url,
                ];

                return;
            }

            if ($products[$url]['name'] === '' && $name !== '') {
                $products[$url]['name'] = $name;
            }
        });

        return array_values($products);
    }

    /**
     * @param  array<string, mixed>  $parentCategory
     * @return array<int, array<string, mixed>>
     */
    private function extractChildCategories(string $html, string $baseUrl, array $parentCategory): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $children = [];

        $crawler->filter('a[href]')->each(function (Crawler $anchor) use (&$children, $baseUrl, $parentCategory): void {
            $url = $this->normalizeUrl((string) $anchor->attr('href'), $baseUrl);

            if ($url === null || ! $this->isNestedCategoryUrl($url)) {
                return;
            }

            $reference = $this->parseCategoryReference($url);

            if ($reference === null || (string) ($reference['sub_sub_group'] ?? '') === '') {
                return;
            }

            $name = $this->normalizeText($anchor->text(''));

            if ($name === '') {
                $image = $anchor->filter('img')->first();

                if ($image->count() > 0) {
                    $name = $this->normalizeText(
                        (string) ($image->attr('alt') ?: $image->attr('title') ?: '')
                    );
                }
            }

            if ($name === '') {
                $name = (string) $reference['sub_sub_group'];
            }

            $path = $parentCategory['path'] ?? [];
            $path = is_array($path) ? array_values($path) : [];

            if ($path === [] || $this->normalizeText((string) end($path)) !== $name) {
                $path[] = $name;
            }

            $externalId = $this->categoryExternalIdFromUrl($url);

            $children[$url] = [
                'external_category_id' => $externalId,
                'source_key' => (string) $reference['source_key'],
                'name' => $name,
                'url' => $url,
                'page_type' => 'mainproduct_subsub',
                'product_group' => (string) $reference['product_group'],
                'sub_group' => (string) $reference['sub_group'],
                'sub_sub_group' => (string) $reference['sub_sub_group'],
                'level' => max(1, (int) ($parentCategory['level'] ?? count($path) - 1)) + 1,
                'parent_external_category_id' => (string) ($parentCategory['external_category_id'] ?? ''),
                'top_category_external_id' => (string) ($parentCategory['top_category_external_id'] ?? ''),
                'top_category_name' => (string) ($parentCategory['top_category_name'] ?? $reference['product_group']),
                'path' => $path,
                'has_children' => false,
                'is_product_category' => true,
            ];
        });

        return array_values($children);
    }

    /**
     * @return array{external_id: string, source_key: string, product_group: string, sub_group: string, sub_sub_group: string, selected_name: string}|null
     */
    private function parseProductReference(string $url): ?array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        $decodedQuery = rawurldecode($query);
        $selectedParts = explode('Selected', $decodedQuery, 2);

        if (count($selectedParts) !== 2) {
            return null;
        }

        $categoryReference = $this->parseCategorySourceKey($selectedParts[0]);
        $selectedName = $this->normalizeText($selectedParts[1]);

        if ($categoryReference === null || $selectedName === '') {
            return null;
        }

        return $categoryReference + [
            'external_id' => hash('sha256', $decodedQuery),
            'source_key' => $decodedQuery,
            'selected_name' => $selectedName,
        ];
    }

    /**
     * @return array{source_key: string, product_group: string, sub_group: string, sub_sub_group: string}|null
     */
    private function parseCategoryReference(string $url): ?array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        return $this->parseCategorySourceKey(rawurldecode($query));
    }

    /**
     * @return array{source_key: string, product_group: string, sub_group: string, sub_sub_group: string}|null
     */
    private function parseCategorySourceKey(string $sourceKey): ?array
    {
        $productGroupMarker = 'ProductGroup';
        $subGroupMarker = 'SubGroup';
        $groupPosition = strpos($sourceKey, $productGroupMarker);

        if ($groupPosition === false) {
            return null;
        }

        $categoryKey = substr($sourceKey, $groupPosition + strlen($productGroupMarker));
        $subGroupPosition = strpos($categoryKey, $subGroupMarker);

        if ($subGroupPosition === false) {
            return null;
        }

        $productGroup = $this->normalizeText(substr($categoryKey, 0, $subGroupPosition));
        $remainder = substr($categoryKey, $subGroupPosition + strlen($subGroupMarker));
        $subSubGroupMarker = 'SubSubGroup';
        $subSubGroupPosition = strpos($remainder, $subSubGroupMarker);
        $subGroup = $subSubGroupPosition === false
            ? $this->normalizeText($remainder)
            : $this->normalizeText(substr($remainder, 0, $subSubGroupPosition));
        $subSubGroup = $subSubGroupPosition === false
            ? ''
            : $this->normalizeText(substr($remainder, $subSubGroupPosition + strlen($subSubGroupMarker)));

        if ($productGroup === '') {
            return null;
        }

        return [
            'source_key' => $sourceKey,
            'product_group' => $productGroup,
            'sub_group' => $subGroup,
            'sub_sub_group' => $subSubGroup,
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function categoryRecord(string $url, array $category = []): array
    {
        $name = $this->normalizeText((string) ($category['name'] ?? $this->categoryNameFromUrl($url)));
        $path = $category['path'] ?? [$name];

        if (! is_array($path) || $path === []) {
            $path = [$name];
        }

        $path = array_values(array_filter(array_map(
            fn (mixed $segment): string => $this->normalizeText((string) $segment),
            $path,
        ), static fn (string $segment): bool => $segment !== ''));

        if ($path === []) {
            $path = [$name];
        }

        return $category + [
            'external_category_id' => $this->categoryExternalIdFromUrl($url),
            'name' => $name,
            'url' => $url,
            'path' => $path,
            'is_product_category' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function categoryPathLabel(array $category): string
    {
        $path = $category['path'] ?? [];

        if (is_array($path) && $path !== []) {
            return implode(' > ', array_map(static fn (mixed $segment): string => (string) $segment, $path));
        }

        return (string) ($category['name'] ?? $category['url'] ?? 'Unknown category');
    }

    private function categoryNameFromUrl(string $url): string
    {
        $reference = $this->parseCategoryReference($url);

        if ($reference === null) {
            return $url;
        }

        if ($reference['sub_sub_group'] !== '') {
            return $reference['sub_sub_group'];
        }

        if ($reference['sub_group'] !== '') {
            return $reference['sub_group'];
        }

        return $reference['product_group'];
    }

    private function categoryExternalIdFromUrl(string $url): string
    {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? $url);

        return 'category:'.hash('sha256', rawurldecode($query));
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl ?? self::BASE_URL);

        return $normalized !== null && $this->isProductUrl($normalized) ? $normalized : null;
    }

    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/#.*$/', '', $url) ?? $url;

        if ($url === '' || $url === '#' || str_starts_with(strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::VERMEIREN_HOST.$url;
        } elseif (parse_url($url, PHP_URL_SCHEME) === null) {
            $basePath = parse_url($baseUrl, PHP_URL_PATH);
            $directory = is_string($basePath) ? rtrim(str_replace('\\', '/', dirname($basePath)), '/') : '/web/web.nsf';
            $url = 'https://'.self::VERMEIREN_HOST.$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['vermeiren.pl', self::VERMEIREN_HOST], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $encodedPath = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
        $query = $parts['query'] ?? null;
        $normalized = 'https://'.self::VERMEIREN_HOST.($encodedPath === '' ? '/' : $encodedPath);

        if (is_string($query) && $query !== '') {
            $normalized .= '?'.rawurlencode(rawurldecode($query));
        }

        return $normalized;
    }

    private function isProductUrl(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== self::VERMEIREN_HOST) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return strtolower(pathinfo($path, PATHINFO_FILENAME)) === 'detailproduct';
    }

    private function isCategoryUrl(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== self::VERMEIREN_HOST) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array(strtolower(pathinfo($path, PATHINFO_FILENAME)), [
            'mainproduct',
            'mainproduct_categories',
            'mainproduct_sub',
            'mainproduct_subsub',
        ], true);
    }

    private function isNestedCategoryUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return strtolower(pathinfo($path, PATHINFO_FILENAME)) === 'mainproduct_subsub';
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopVermeirenProductDiscovery/1.0)',
        ];
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }

    private function emitRetry(string $url, int $attempt, string $reason): void
    {
        $this->emit(sprintf(
            'Retrying Vermeiren URL after attempt %d/%d (%s): %s',
            $attempt,
            $this->attempts,
            $reason,
            $url,
        ));
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
