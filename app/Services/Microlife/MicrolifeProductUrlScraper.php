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

final class MicrolifeProductUrlScraper
{
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
        'wyszukiwarka-produktow-microlife',
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
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    public function scrapeFromDiscoveredCategories(
        array $discovery,
        ?int $categoryLimit = null,
        ?string $catalogueType = null,
    ): array {
        $categories = array_values(array_filter(
            $discovery['categories'] ?? [],
            static fn (mixed $category): bool => is_array($category)
                && (bool) ($category['is_product_category'] ?? false),
        ));

        if ($catalogueType !== null) {
            $categories = array_values(array_filter(
                $categories,
                static fn (array $category): bool => ($category['catalogue_type'] ?? null) === $catalogueType,
            ));
        }

        return $this->scrapeCategories($categories, $categoryLimit);
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $categories
     * @return array<string, mixed>
     */
    public function scrapeCategories(array $categories, ?int $categoryLimit = null): array
    {
        $allSourceCategories = $this->normalizeCategories($categories);
        $knownCategoryUrls = array_fill_keys(array_map(
            static fn (array $category): string => (string) $category['url'],
            $allSourceCategories,
        ), true);
        $sourceCategories = $allSourceCategories;

        if ($categoryLimit !== null) {
            $sourceCategories = array_slice($sourceCategories, 0, max(1, $categoryLimit));
        }
        $visited = [];
        $failed = [];
        $categoryResults = [];
        $productsByUrl = [];

        foreach ($sourceCategories as $category) {
            $categoryUrl = (string) $category['url'];
            $path = is_array($category['path'] ?? null) ? $category['path'] : [(string) $category['name']];
            $this->emit('Fetching Microlife product category: '.$categoryUrl);
            $visited[$categoryUrl] = true;
            $html = $this->fetchBody($categoryUrl, $failed);
            $categoryProducts = [];

            if ($html !== null) {
                foreach ($this->extractProducts($html, $category, $knownCategoryUrls) as $product) {
                    $url = (string) $product['url'];
                    $categoryProducts[$url] = true;

                    if (! isset($productsByUrl[$url])) {
                        $product['category_urls'] = [$categoryUrl];
                        $product['category_paths'] = [$path];
                        $productsByUrl[$url] = $product;

                        continue;
                    }

                    if (! in_array($categoryUrl, $productsByUrl[$url]['category_urls'], true)) {
                        $productsByUrl[$url]['category_urls'][] = $categoryUrl;
                    }

                    if (! in_array($path, $productsByUrl[$url]['category_paths'], true)) {
                        $productsByUrl[$url]['category_paths'][] = $path;
                    }
                }

                if ($categoryProducts === [] && $this->looksLikeProductDetail($html)) {
                    $product = $this->productFromCategoryPage($html, $category);
                    $url = (string) $product['url'];
                    $categoryProducts[$url] = true;
                    $product['category_urls'] = [$categoryUrl];
                    $product['category_paths'] = [$path];
                    $productsByUrl[$url] = $product;
                }
            }

            $categoryResults[] = [
                'external_category_id' => (string) $category['external_category_id'],
                'catalogue_type' => (string) $category['catalogue_type'],
                'name' => (string) $category['name'],
                'url' => $categoryUrl,
                'category_path' => $path,
                'pages_scraped' => 1,
                'failed_page_count' => isset($failed[$categoryUrl]) ? 1 : 0,
                'product_count' => count($categoryProducts),
                'product_urls' => array_keys($categoryProducts),
            ];
        }

        $products = array_values($productsByUrl);

        return [
            'source' => 'microlife',
            'source_categories' => $sourceCategories,
            'category_results' => $categoryResults,
            'products' => $products,
            'product_urls' => array_values(array_map(
                static fn (array $product): string => (string) $product['url'],
                $products,
            )),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<string, bool>  $knownCategoryUrls
     * @return array<int, array<string, mixed>>
     */
    private function extractProducts(string $html, array $category, array $knownCategoryUrls): array
    {
        $categoryUrl = (string) $category['url'];
        $catalogueType = (string) $category['catalogue_type'];
        $rootUrl = $catalogueType === 'professional'
            ? MicrolifeCategoryUrlScraper::PROFESSIONAL_URL
            : MicrolifeCategoryUrlScraper::CONSUMER_URL;
        $categorySegments = $this->segmentsBelowRoot($categoryUrl, $rootUrl) ?? [];
        $crawler = new Crawler($html, $categoryUrl);
        $products = [];

        $crawler->filter('article[data-href], [class*="product"][data-href], a[href]')->each(function (Crawler $node) use (
            &$products,
            $category,
            $categoryUrl,
            $catalogueType,
            $rootUrl,
            $categorySegments,
            $knownCategoryUrls,
        ): void {
            $element = $node->getNode(0);

            if (! $element instanceof DOMElement) {
                return;
            }

            $candidate = $element->hasAttribute('href')
                ? $element->getAttribute('href')
                : $element->getAttribute('data-href');
            $url = $this->normalizeUrl($candidate, $categoryUrl);

            if (
                $url === null
                || $url === $categoryUrl
                || isset($knownCategoryUrls[$url])
                || $this->isExcludedCatalogueUrl($url)
            ) {
                return;
            }

            $segments = $this->segmentsBelowRoot($url, $rootUrl);

            if (
                $segments === null
                || count($segments) !== count($categorySegments) + 1
                || array_slice($segments, 0, count($categorySegments)) !== $categorySegments
            ) {
                return;
            }

            $name = $this->nameFromNode($node, $element);

            if ($name === '') {
                $name = $this->humanizeSlug((string) end($segments));
            }

            $products[$url] = $this->makeProduct(
                url: $url,
                name: $name,
                catalogueType: $catalogueType,
                category: $category,
            );
        });

        return array_values($products);
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function productFromCategoryPage(string $html, array $category): array
    {
        $crawler = new Crawler($html, (string) $category['url']);
        $name = '';

        foreach (['.product-number-type', '[property="pagetitle"]', 'main h1', 'h1'] as $selector) {
            $nodes = $crawler->filter($selector);

            if ($nodes->count() === 0) {
                continue;
            }

            $name = $this->normalizeText($nodes->first()->text(''));

            if ($name !== '') {
                break;
            }
        }

        if ($name === '') {
            $name = (string) $category['name'];
        }

        return $this->makeProduct(
            url: (string) $category['url'],
            name: $name,
            catalogueType: (string) $category['catalogue_type'],
            category: $category,
        );
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function makeProduct(string $url, string $name, string $catalogueType, array $category): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $slug = (string) (end($segments) ?: hash('sha256', $url));

        return [
            'source' => 'microlife',
            'external_id' => hash('sha256', $url),
            'catalogue_type' => $catalogueType,
            'slug' => $slug,
            'name' => $name,
            'url' => $url,
            'source_url' => $url,
            'canonical_url' => $url,
            'category_external_id' => (string) $category['external_category_id'],
            'category_slug' => (string) $category['slug'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCategories(array $categories): array
    {
        $normalized = [];

        foreach ($categories as $category) {
            if (is_string($category)) {
                $url = $this->normalizeUrl($category, MicrolifeCategoryUrlScraper::CONSUMER_URL);

                if ($url === null) {
                    continue;
                }

                $catalogueType = str_starts_with((string) parse_url($url, PHP_URL_PATH), '/produkty-profesjonalne')
                    ? 'professional'
                    : 'consumer';
                $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
                $slug = (string) end($segments);
                $category = [
                    'external_category_id' => $catalogueType.':'.$slug,
                    'catalogue_type' => $catalogueType,
                    'slug' => $slug,
                    'name' => $this->humanizeSlug($slug),
                    'url' => $url,
                    'path' => [$this->humanizeSlug($slug)],
                    'is_product_category' => true,
                ];
            }

            if (! is_array($category)) {
                continue;
            }

            $url = $this->normalizeUrl((string) ($category['url'] ?? ''), MicrolifeCategoryUrlScraper::CONSUMER_URL);

            if ($url === null) {
                continue;
            }

            $catalogueType = (string) ($category['catalogue_type'] ?? '');

            if (! in_array($catalogueType, ['consumer', 'professional'], true)) {
                $catalogueType = str_starts_with((string) parse_url($url, PHP_URL_PATH), '/produkty-profesjonalne')
                    ? 'professional'
                    : 'consumer';
            }

            $pathSegments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
            $slug = (string) ($category['slug'] ?? end($pathSegments));
            $name = $this->normalizeText((string) ($category['name'] ?? ''));

            if ($name === '') {
                $name = $this->humanizeSlug($slug);
            }

            $normalized[$url] = [
                ...$category,
                'external_category_id' => (string) ($category['external_category_id'] ?? $catalogueType.':'.$slug),
                'catalogue_type' => $catalogueType,
                'slug' => $slug,
                'name' => $name,
                'url' => $url,
                'path' => is_array($category['path'] ?? null) ? $category['path'] : [$name],
                'is_product_category' => true,
            ];
        }

        return array_values($normalized);
    }

    private function looksLikeProductDetail(string $html): bool
    {
        $normalized = Str::lower($html);

        foreach ([
            'instrukcja obsługi',
            'instruction manuals',
            '>kup teraz<',
            'class="product-detail',
            'class="product-features',
            'property="product"',
        ] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
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
