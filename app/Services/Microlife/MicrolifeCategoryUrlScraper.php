<?php

declare(strict_types=1);

namespace App\Services\Microlife;

use Closure;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MicrolifeCategoryUrlScraper
{
    public const CONSUMER_URL = 'https://www.microlife.pl/produkty';

    public const PROFESSIONAL_URL = 'https://www.microlife.pl/produkty-profesjonalne';

    /**
     * @var array<int, string>
     */
    public const DEFAULT_URLS = [
        self::CONSUMER_URL,
        self::PROFESSIONAL_URL,
    ];

    private const CANONICAL_HOST = 'www.microlife.pl';

    /**
     * Legacy Microlife consumer paths still emitted by catalogue cards.
     *
     * @var array<string, string>
     */
    private const LEGACY_CONSUMER_PATH_PREFIXES = [
        '/consumer-products/flexible-heating/heating-pads' => '/produkty/termoterapia-2/poduszki-grzewcze',
        '/consumer-products/flexible-heating' => '/produkty/termoterapia-2',
    ];

    /**
     * Catalogue landing pages that do not expose Microlife product details.
     *
     * @var array<int, string>
     */
    private const NON_PRODUCT_CATEGORY_PATHS = [
        '/produkty/testpage',
    ];

    /**
     * Catalogue pages that belong to the same URL trees but are not product categories.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_SEGMENTS = [
        'o-mankietach',
        'oprogramowanie',
        'oprogramowanie-profesjonalne',
        'professional-software',
        'software',
        'support',
        'validation',
        'validations',
        'walidacje-i-badania-kliniczne',
        'wyszukiwarka-produktow',
        'wyszukiwarka-produktow-microlife',
    ];

    /**
     * Consumer catalogue pages that are product details linked directly from a
     * top-level category rather than nested product-list categories.
     *
     * @var array<int, string>
     */
    private const DIRECT_PRODUCT_PATHS = [
        '/produkty/opieka-nad-dzieckiem/bc-50',
        '/produkty/opieka-nad-dzieckiem/bc-100-soft',
        '/produkty/opieka-nad-dzieckiem/bc-200-comfy',
        '/produkty/opieka-nad-dzieckiem/bc-300-maxi-2w1',
    ];

    /**
     * Stable names for catalogue cards whose surrounding marketing headings
     * are more prominent than the actual category label in Microlife HTML.
     *
     * @var array<string, string>
     */
    private const CATEGORY_NAME_OVERRIDES = [
        '/produkty/cisnienie-krwi/nadgarstkowe' => 'Ciśnieniomierze nadgarstkowe',
        '/produkty-profesjonalne/watchbp-office' => 'WatchBP Office',
        '/produkty-profesjonalne/watchbp-o3' => 'WatchBP O3',
        '/produkty-profesjonalne/mankiety-i-wyposazenie' => 'Mankiety i wyposażenie',
        '/produkty/termoterapia-2/poduszki-grzewcze' => 'Poduszki grzewcze',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 2000;

    private int $requestDelayMilliseconds = 0;

    private bool $verifyTls = true;

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

    public function withAttempts(int $attempts): self
    {
        $this->attempts = max(1, $attempts);

        return $this;
    }

    public function withRetryDelayMilliseconds(int $milliseconds): self
    {
        $this->retryDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->verifyTls = $verify;

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = self::DEFAULT_URLS): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     source: string,
     *     start_urls: array<int, string>,
     *     catalogues: array<int, array<string, mixed>>,
     *     top_categories: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     category_urls: array<int, string>,
     *     product_category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>
     * }
     */
    public function scrape(array $startUrls = self::DEFAULT_URLS): array
    {
        $normalizedStartUrls = [];
        $visited = [];
        $failed = [];
        $categories = [];
        $topCategories = [];
        $catalogues = [];
        $discoveryOrder = 0;

        foreach ($startUrls as $startUrl) {
            $rootUrl = $this->normalizeUrl($startUrl, self::CONSUMER_URL);
            $catalogueType = $rootUrl === null ? null : $this->catalogueType($rootUrl);

            if ($rootUrl === null || $catalogueType === null || ! $this->isCatalogueRoot($rootUrl, $catalogueType)) {
                continue;
            }

            $normalizedStartUrls[] = $rootUrl;
            $this->emit('Fetching Microlife '.$catalogueType.' catalogue root: '.$rootUrl);
            $visited[$rootUrl] = true;
            $rootHtml = $this->fetchBody($rootUrl, $failed);

            if ($rootHtml === null) {
                $catalogues[] = [
                    'catalogue_type' => $catalogueType,
                    'root_url' => $rootUrl,
                    'category_count' => 0,
                    'product_category_count' => 0,
                    'category_urls' => [],
                    'product_category_urls' => [],
                ];

                continue;
            }

            $rootChildren = $this->extractDirectChildren($rootHtml, $rootUrl, $rootUrl);
            $catalogueCategoryUrls = [];
            $catalogueProductCategoryUrls = [];

            foreach ($rootChildren as $rootChild) {
                $topCategory = $this->makeCategory(
                    catalogueType: $catalogueType,
                    url: $rootChild['url'],
                    name: $rootChild['name'],
                    path: [$rootChild['name']],
                    parentExternalCategoryId: null,
                    topCategoryExternalId: null,
                    topCategoryName: $rootChild['name'],
                    level: 1,
                    hasChildren: false,
                    isProductCategory: true,
                );

                $children = [];
                $directProductChildren = [];

                if ($catalogueType === 'consumer') {
                    $this->emit('Fetching Microlife consumer category page: '.$rootChild['url']);
                    $visited[$rootChild['url']] = true;
                    $topHtml = $this->fetchBody($rootChild['url'], $failed);

                    if ($topHtml !== null) {
                        foreach ($this->extractDirectChildren($topHtml, $rootChild['url'], $rootUrl) as $child) {
                            if ($this->isDirectProductUrl($child['url'])) {
                                $directProductChildren[] = $child;

                                continue;
                            }

                            $children[] = $child;
                        }
                    }
                }

                if ($children !== []) {
                    $topCategory['has_children'] = true;
                }

                $topCategory['is_product_category'] = ! $this->isNonProductCategoryUrl($topCategory['url'])
                    && ($children === [] || $directProductChildren !== []);

                $topCategory['discovery_order'] = $discoveryOrder++;
                $categories[$topCategory['url']] = $topCategory;
                $topCategories[$topCategory['url']] = $topCategory;
                $catalogueCategoryUrls[] = $topCategory['url'];

                if ($topCategory['is_product_category']) {
                    $catalogueProductCategoryUrls[] = $topCategory['url'];
                }

                foreach ($children as $child) {
                    $childCategory = $this->makeCategory(
                        catalogueType: $catalogueType,
                        url: $child['url'],
                        name: $child['name'],
                        path: [$rootChild['name'], $child['name']],
                        parentExternalCategoryId: $topCategory['external_category_id'],
                        topCategoryExternalId: $topCategory['external_category_id'],
                        topCategoryName: $rootChild['name'],
                        level: 2,
                        hasChildren: false,
                        isProductCategory: true,
                    );
                    $childCategory['discovery_order'] = $discoveryOrder++;

                    if (isset($categories[$childCategory['url']])) {
                        continue;
                    }

                    $categories[$childCategory['url']] = $childCategory;
                    $catalogueCategoryUrls[] = $childCategory['url'];
                    $catalogueProductCategoryUrls[] = $childCategory['url'];
                }
            }

            $catalogues[] = [
                'catalogue_type' => $catalogueType,
                'root_url' => $rootUrl,
                'category_count' => count(array_unique($catalogueCategoryUrls)),
                'product_category_count' => count(array_unique($catalogueProductCategoryUrls)),
                'category_urls' => array_values(array_unique($catalogueCategoryUrls)),
                'product_category_urls' => array_values(array_unique($catalogueProductCategoryUrls)),
            ];
        }

        $categoryValues = array_values($categories);
        usort(
            $categoryValues,
            static fn (array $left, array $right): int => ((int) $left['discovery_order']) <=> ((int) $right['discovery_order']),
        );

        $topCategoryValues = array_values($topCategories);
        usort(
            $topCategoryValues,
            static fn (array $left, array $right): int => ((int) $left['discovery_order']) <=> ((int) $right['discovery_order']),
        );

        foreach ($categoryValues as &$category) {
            unset($category['discovery_order']);
        }
        unset($category);

        foreach ($topCategoryValues as &$category) {
            unset($category['discovery_order']);
        }
        unset($category);

        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categoryValues,
        ));
        $productCategoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            array_values(array_filter(
                $categoryValues,
                static fn (array $category): bool => (bool) ($category['is_product_category'] ?? false),
            )),
        ));

        return [
            'source' => 'microlife',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'catalogues' => $catalogues,
            'top_categories' => $topCategoryValues,
            'categories' => $categoryValues,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $productCategoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array<int, array{url: string, name: string}>
     */
    private function extractDirectChildren(string $html, string $currentUrl, string $rootUrl): array
    {
        $crawler = new Crawler($html, $currentUrl);
        $children = [];
        $currentSegments = $this->segmentsBelowRoot($currentUrl, $rootUrl) ?? [];
        $currentDepth = count($currentSegments);

        $crawler->filter('a[href], [data-href]')->each(function (Crawler $node) use (&$children, $currentUrl, $rootUrl, $currentDepth, $currentSegments): void {
            $element = $node->getNode(0);

            if (! $element instanceof DOMElement) {
                return;
            }

            $candidate = $element->hasAttribute('href')
                ? $element->getAttribute('href')
                : $element->getAttribute('data-href');
            $url = $this->normalizeUrl($candidate, $currentUrl);

            if ($url === null || $url === $currentUrl || $this->isExcludedCatalogueUrl($url)) {
                return;
            }

            $segments = $this->segmentsBelowRoot($url, $rootUrl);

            if (
                $segments === null
                || count($segments) !== $currentDepth + 1
                || array_slice($segments, 0, $currentDepth) !== $currentSegments
            ) {
                return;
            }

            $name = $this->nameFromNode($node, $element);

            if ($name === '') {
                $name = $this->humanizeSlug((string) end($segments));
            }

            $name = $this->categoryName($url, $name);

            if (
                ! isset($children[$url])
                || $this->categoryNameScore($name, $url) > $this->categoryNameScore($children[$url]['name'], $url)
            ) {
                $children[$url] = [
                    'url' => $url,
                    'name' => $name,
                ];
            }
        });

        return array_values($children);
    }

    /**
     * @param  array<int, string>  $path
     * @return array<string, mixed>
     */
    private function makeCategory(
        string $catalogueType,
        string $url,
        string $name,
        array $path,
        ?string $parentExternalCategoryId,
        ?string $topCategoryExternalId,
        string $topCategoryName,
        int $level,
        bool $hasChildren,
        bool $isProductCategory,
    ): array {
        $rootUrl = $catalogueType === 'professional' ? self::PROFESSIONAL_URL : self::CONSUMER_URL;
        $segments = $this->segmentsBelowRoot($url, $rootUrl) ?? [];
        $externalCategoryId = $catalogueType.':'.implode('/', $segments);
        $slug = (string) (end($segments) ?: Str::slug($name));

        return [
            'source' => 'microlife',
            'catalogue_type' => $catalogueType,
            'external_category_id' => $externalCategoryId,
            'slug' => $slug,
            'name' => $name,
            'url' => $url,
            'path' => $path,
            'level' => $level,
            'parent_external_category_id' => $parentExternalCategoryId,
            'top_category_external_id' => $topCategoryExternalId ?? $externalCategoryId,
            'top_category_name' => $topCategoryName,
            'has_children' => $hasChildren,
            'is_product_category' => $isProductCategory,
        ];
    }

    private function nameFromNode(Crawler $crawler, DOMElement $element): string
    {
        $ancestor = $element;
        $depth = 0;
        $imageAlt = '';

        while ($ancestor instanceof DOMNode && $depth < 5) {
            if ($ancestor instanceof DOMElement) {
                $ancestorCrawler = new Crawler($ancestor);
                $headings = $ancestorCrawler->filter('h1, h2, h3, h4, h5, h6, [property="title"], [property="ext_title"]');

                if ($headings->count() > 0) {
                    $heading = $this->normalizeText($headings->first()->text(''));

                    if ($heading !== '' && ! $this->isGenericLinkText($heading)) {
                        return $heading;
                    }
                }

                if ($imageAlt === '') {
                    $images = $ancestorCrawler->filter('img[alt]');

                    if ($images->count() > 0) {
                        $imageAlt = $this->normalizeText((string) $images->first()->attr('alt'));
                    }
                }
            }

            $ancestor = $ancestor->parentNode;
            $depth++;
        }

        $name = $this->normalizeText($crawler->text(''));

        if ($name !== '' && ! $this->isGenericLinkText($name)) {
            return $name;
        }

        return $imageAlt;
    }

    private function isGenericLinkText(string $text): bool
    {
        return in_array(Str::lower($text), [
            'dowiedz się więcej',
            'przeczytaj więcej',
            'pokaż produkt',
            'view product',
            'view products',
        ], true);
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
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withOptions([
                        'allow_redirects' => true,
                        'verify' => $this->verifyTls,
                    ])
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->emit(sprintf(
                        '  Microlife request failed on attempt %d/%d: %s',
                        $attempt,
                        $this->attempts,
                        $lastFailure,
                    ));
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->successful()) {
                unset($failed[$url]);

                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->attempts) {
                $this->emit(sprintf(
                    '  Microlife request returned %s on attempt %d/%d.',
                    $lastFailure,
                    $attempt,
                    $this->attempts,
                ));
                $this->pauseBeforeRetry();
            }
        }

        $failed[$url] = $lastFailure;

        return null;
    }

    private function isDirectProductUrl(string $url): bool
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return in_array($path, self::DIRECT_PRODUCT_PATHS, true);
    }

    private function categoryName(string $url, string $fallback): string
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return self::CATEGORY_NAME_OVERRIDES[$path] ?? $fallback;
    }

    private function categoryNameScore(string $name, string $url): int
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $slug = Str::slug((string) end($segments));
        $normalizedName = Str::slug($name);

        if ($slug === '' || $normalizedName === '') {
            return 0;
        }

        if ($slug === $normalizedName) {
            return 1000;
        }

        $score = 0;

        foreach (array_filter(explode('-', $slug)) as $token) {
            if (str_contains($normalizedName, $token)) {
                $score += 100;
            }
        }

        return $score - mb_strlen($normalizedName);
    }

    private function isCatalogueRoot(string $url, string $catalogueType): bool
    {
        $expected = $catalogueType === 'professional' ? self::PROFESSIONAL_URL : self::CONSUMER_URL;

        return $url === $expected;
    }

    private function catalogueType(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '/produkty-profesjonalne') {
            return 'professional';
        }

        if ($path === '/produkty') {
            return 'consumer';
        }

        return null;
    }

    private function isExcludedCatalogueUrl(string $url): bool
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        foreach ($segments as $segment) {
            if (in_array(Str::lower(rawurldecode($segment)), self::EXCLUDED_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>|null
     */
    private function segmentsBelowRoot(string $url, string $rootUrl): ?array
    {
        $urlPath = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $rootPath = trim((string) parse_url($rootUrl, PHP_URL_PATH), '/');
        $urlSegments = $urlPath === '' ? [] : explode('/', $urlPath);
        $rootSegments = $rootPath === '' ? [] : explode('/', $rootPath);

        if (array_slice($urlSegments, 0, count($rootSegments)) !== $rootSegments) {
            return null;
        }

        return array_values(array_slice($urlSegments, count($rootSegments)));
    }

    private function normalizeUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lowerCandidate = Str::lower($candidate);

        if (
            $candidate === ''
            || str_starts_with($candidate, '#')
            || str_starts_with($lowerCandidate, 'javascript:')
            || str_starts_with($lowerCandidate, 'mailto:')
            || str_starts_with($lowerCandidate, 'tel:')
        ) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
            $baseDirectory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
            $candidate = 'https://'.self::CANONICAL_HOST.$baseDirectory.'/'.$candidate;
        }

        $host = Str::lower((string) parse_url($candidate, PHP_URL_HOST));

        if (! in_array($host, ['microlife.pl', 'www.microlife.pl'], true)) {
            return null;
        }

        $path = rawurldecode((string) parse_url($candidate, PHP_URL_PATH));
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if (str_starts_with($path, '/professional-products')) {
            $path = '/produkty-profesjonalne'.substr($path, strlen('/professional-products'));
        }

        $path = $this->normalizeLegacyConsumerPath($path);
        $path = '/'.trim($path, '/');

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function isNonProductCategoryUrl(string $url): bool
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return in_array($path, self::NON_PRODUCT_CATEGORY_PATHS, true);
    }

    private function normalizeLegacyConsumerPath(string $path): string
    {
        foreach (self::LEGACY_CONSUMER_PATH_PREFIXES as $legacyPrefix => $canonicalPrefix) {
            if ($path === $legacyPrefix || str_starts_with($path, $legacyPrefix.'/')) {
                return $canonicalPrefix.substr($path, strlen($legacyPrefix));
            }
        }

        return $path;
    }

    private function humanizeSlug(string $slug): string
    {
        return Str::of(rawurldecode($slug))
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->value();
    }

    private function normalizeText(string $value): string
    {
        return Str::of(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replace("\u{00A0}", ' ')
            ->squish()
            ->value();
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
            'Referer' => 'https://www.microlife.pl/',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMicrolifeCatalogue/1.0)',
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

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
