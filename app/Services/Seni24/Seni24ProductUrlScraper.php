<?php

declare(strict_types=1);

namespace App\Services\Seni24;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class Seni24ProductUrlScraper
{
    public const HOST = 'seni24.pl';

    /**
     * Primary commerce roots. NFZ/promotional/brand pages intentionally remain
     * outside the root set because they duplicate products from these trees.
     *
     * @var list<string>
     */
    public const DEFAULT_URLS = [
        'https://www.seni24.pl/nietrzymanie-moczu/',
        'https://www.seni24.pl/higiena-i-pielegnacja-specjalistyczna/',
        'https://www.seni24.pl/rehabilitacja-i-likwidacja-barier/',
        'https://www.seni24.pl/opatrywanie-i-leczenie-ran/',
        'https://www.seni24.pl/drogeria/',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $maxCategoryPages = 1200;

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

    public function withMaxCategoryPages(int $pages): self
    {
        $this->maxCategoryPages = max(1, $pages);

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = self::DEFAULT_URLS): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(array $startUrls = self::DEFAULT_URLS): array
    {
        $roots = [];

        foreach ($startUrls as $startUrl) {
            $normalized = $this->normalizeCategoryUrl($startUrl);

            if ($normalized !== null) {
                $roots[$normalized] = true;
            }
        }

        $visited = [];
        $failed = [];
        $products = [];
        $pageLinks = [];
        $queuedRemaining = [];
        $processedRootMemberships = 0;
        $stoppedAtLimit = false;

        foreach (array_keys($roots) as $rootUrl) {
            $queue = [$rootUrl];
            $visitedForRoot = [];

            while ($queue !== []) {
                if ($processedRootMemberships >= $this->maxCategoryPages) {
                    $stoppedAtLimit = true;
                    $queuedRemaining = array_values(array_unique(array_merge($queuedRemaining, $queue)));
                    break 2;
                }

                $url = array_shift($queue);

                if (! is_string($url) || isset($visitedForRoot[$url])) {
                    continue;
                }

                $visitedForRoot[$url] = true;
                $visited[$url] = true;
                $processedRootMemberships++;

                if (! isset($pageLinks[$url])) {
                    $this->emit(sprintf(
                        'Fetching Seni24 category %d: %s',
                        count($visited),
                        $url,
                    ));

                    $html = $this->fetchBody($url, $failed);

                    $pageLinks[$url] = $html === null
                        ? ['products' => [], 'category_urls' => []]
                        : $this->extractLinks($html, $url);
                }

                foreach ($pageLinks[$url]['products'] as $product) {
                    $productUrl = $product['url'];
                    $product['listing_roots'] = [$rootUrl];

                    if (! isset($products[$productUrl])) {
                        $products[$productUrl] = $product;
                        continue;
                    }

                    foreach (['listing_pages', 'listing_roots'] as $key) {
                        $products[$productUrl][$key] = array_values(array_unique(array_merge(
                            is_array($products[$productUrl][$key] ?? null)
                                ? $products[$productUrl][$key]
                                : [],
                            is_array($product[$key] ?? null)
                                ? $product[$key]
                                : [],
                        )));
                    }

                    if (($products[$productUrl]['name'] ?? '') === '' && ($product['name'] ?? '') !== '') {
                        $products[$productUrl]['name'] = $product['name'];
                    }

                    if (($products[$productUrl]['price_gross_amount'] ?? null) === null
                        && ($product['price_gross_amount'] ?? null) !== null) {
                        $products[$productUrl]['price_gross_amount'] = $product['price_gross_amount'];
                    }
                }

                foreach ($pageLinks[$url]['category_urls'] as $categoryUrl) {
                    if (! isset($visitedForRoot[$categoryUrl]) && ! in_array($categoryUrl, $queue, true)) {
                        $queue[] = $categoryUrl;
                    }
                }
            }
        }

        return [
            'source' => 'seni24',
            'start_urls' => array_keys($roots),
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'product_count' => count($products),
            'visited_urls' => array_keys($visited),
            'visited_category_count' => count($visited),
            'visited_root_category_memberships' => $processedRootMemberships,
            'queued_category_urls_remaining' => $queuedRemaining,
            'max_category_pages' => $this->maxCategoryPages,
            'stopped_at_category_limit' => $stoppedAtLimit,
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array{products: list<array<string, mixed>>, category_urls: list<string>}
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
            $anchors = $crawler->filter('a[href]');
        } catch (Throwable) {
            return ['products' => [], 'category_urls' => []];
        }

        $products = [];
        $categoryUrls = [];

        $anchors->each(function (Crawler $node) use (&$products, &$categoryUrls, $baseUrl): void {
            if ($this->isSiteChromeAnchor($node)) {
                return;
            }

            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $productUrl = $this->normalizeProductUrl($href, $baseUrl);

            if ($productUrl !== null) {
                $candidate = [
                    'url' => $productUrl,
                    'name' => $this->productName($node, $productUrl),
                    'price_gross_amount' => $this->priceNearAnchor($node),
                    'currency' => 'PLN',
                    'listing_pages' => [$baseUrl],
                ];

                if (! isset($products[$productUrl])) {
                    $products[$productUrl] = $candidate;
                    return;
                }

                if ($products[$productUrl]['name'] === '' && $candidate['name'] !== '') {
                    $products[$productUrl]['name'] = $candidate['name'];
                }

                if ($products[$productUrl]['price_gross_amount'] === null
                    && $candidate['price_gross_amount'] !== null) {
                    $products[$productUrl]['price_gross_amount'] = $candidate['price_gross_amount'];
                }

                return;
            }

            $categoryUrl = $this->normalizeCategoryUrl($href, $baseUrl);

            if ($categoryUrl !== null && $categoryUrl !== $baseUrl) {
                $categoryUrls[$categoryUrl] = true;
            }
        });

        return [
            'products' => array_values($products),
            'category_urls' => array_keys($categoryUrls),
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, false);

        if ($normalized === null) {
            return null;
        }

        $path = rtrim((string) parse_url($normalized, PHP_URL_PATH), '/');

        return preg_match('/_[0-9]+-[0-9]+$/', $path) === 1
            ? 'https://www.'.self::HOST.$path
            : null;
    }

    public function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, true);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        if (! str_ends_with($path, '/')) {
            return null;
        }

        if ($this->isDeniedCategoryPath($path)) {
            return null;
        }

        return $normalized;
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

    private function isSiteChromeAnchor(Crawler $node): bool
    {
        $domNode = $node->getNode(0);

        if (! $domNode instanceof DOMElement) {
            return false;
        }

        for (
            $depth = 0, $current = $domNode;
            $current instanceof DOMElement && $depth < 10;
            $depth++, $current = $current->parentElement
        ) {
            $tag = mb_strtolower($current->tagName);

            if (in_array($tag, ['header', 'footer'], true)) {
                return true;
            }

            $signature = mb_strtolower(trim(
                $current->getAttribute('id').' '.$current->getAttribute('class')
            ));

            if ($signature !== '' && preg_match(
                '/(?:^|[ _-])(header|footer|navbar|navigation|top-menu|main-menu|menu-top|menu-main)(?:$|[ _-])/u',
                $signature,
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    private function productName(Crawler $node, string $url): string
    {
        $name = $this->text($node->text(''));

        if ($name !== '') {
            $name = preg_replace('/\s+\d{1,7}[,.]\d{2}\s*zł.*$/iu', '', $name) ?? $name;
            $name = $this->text($name);

            if ($name !== '') {
                return $name;
            }
        }

        foreach (['title', 'aria-label'] as $attribute) {
            $candidate = $this->text((string) $node->attr($attribute));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        try {
            $image = $node->filter('img[alt]')->first();

            if ($image->count() > 0) {
                $candidate = $this->text((string) $image->attr('alt'));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        } catch (Throwable) {
            // Fall through.
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = preg_replace('/_[0-9]+-[0-9]+$/', '', basename($path)) ?? basename($path);

        return Str::headline($slug);
    }

    private function priceNearAnchor(Crawler $node): ?float
    {
        $domNode = $node->getNode(0);

        if (! $domNode instanceof DOMElement) {
            return null;
        }

        for (
            $depth = 0, $current = $domNode;
            $current instanceof DOMElement && $depth < 6;
            $depth++, $current = $current->parentElement
        ) {
            if (preg_match('/\b(\d{1,7}(?:[ .]\d{3})*[,.]\d{2})\s*zł\b/iu', $this->text($current->textContent ?? ''), $matches) === 1) {
                return $this->decimalAmount($matches[1]);
            }
        }

        return null;
    }

    private function normalizeUrl(string $url, ?string $baseUrl, bool $preservePageQuery): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === ''
            || str_starts_with($url, '#')
            || preg_match('/^(?:mailto|tel|javascript):/iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://www.'.self::HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! is_array($base)) {
                return null;
            }

            $basePath = (string) ($base['path'] ?? '/');
            $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
            $url = 'https://www.'.self::HOST
                .($directory === '' || $directory === '.' ? '/' : $directory.'/')
                .ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host !== self::HOST) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = $this->removeDotSegments($path);

        if (preg_match('/_[0-9]+-[0-9]+\/?$/', $path) === 1) {
            $path = rtrim($path, '/');
        } else {
            $path = rtrim($path, '/').'/';
        }

        $normalized = 'https://www.'.self::HOST.$path;

        if ($preservePageQuery && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            $page = $query['page'] ?? null;

            if ((is_string($page) || is_int($page)) && (int) $page > 1) {
                $normalized .= '?page='.(int) $page;
            }
        }

        return $normalized;
    }

    private function isDeniedCategoryPath(string $path): bool
    {
        $path = mb_strtolower($path);

        foreach ([
            '/koszyk/',
            '/zamowienie/',
            '/logowanie/',
            '/moje-konto/',
            '/kontakt/',
            '/sitemap/',
            '/promocje/',
            '/nowosci/',
            '/polecane/',
            '/dofinansowanie-nfz/',
            '/blog/',
            '/regulamin',
            '/polityka',
            '/reklamacje/',
            '/odstapienie-od-umowy',
        ] as $denied) {
            if (str_starts_with($path, $denied)) {
                return true;
            }
        }

        return false;
    }

    private function removeDotSegments(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function decimalAmount(string $value): ?float
    {
        $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.5',
            'User-Agent' => 'KonjiShopCatalogCrawler/1.0 (+https://ortezka.pl)',
        ];
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
