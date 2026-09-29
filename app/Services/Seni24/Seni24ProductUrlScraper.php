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
    public const HOST = 'www.seni24.pl';

    /** @var list<string> */
    public const DEFAULT_URLS = [
        'https://www.seni24.pl/strona-glowna/',
    ];

    private ?Closure $progressCallback = null;
    private int $timeoutSeconds = 15;
    private int $requestDelayMilliseconds = 500;
    private int $maxCategoryPages = 250;

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

    /** @param array<int,string> $startUrls
     *  @return array<int,string>
     */
    public function discover(array $startUrls = self::DEFAULT_URLS): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /** @param array<int,string> $startUrls
     *  @return array<string,mixed>
     */
    public function scrape(array $startUrls = self::DEFAULT_URLS): array
    {
        $roots = [];
        foreach ($startUrls as $url) {
            $normalized = $this->normalizeListingUrl($url);
            if ($normalized !== null) {
                $roots[$normalized] = true;
            }
        }

        $products = [];
        $visited = [];
        $failed = [];
        $queuedRemaining = [];
        $processed = 0;
        $stopped = false;

        foreach (array_keys($roots) as $root) {
            $queue = [$root];
            $visitedForRoot = [];

            while ($queue !== []) {
                if ($processed >= $this->maxCategoryPages) {
                    $queuedRemaining = array_values(array_unique(array_merge($queuedRemaining, $queue)));
                    $stopped = true;
                    break 2;
                }

                $url = array_shift($queue);
                if (!is_string($url) || isset($visitedForRoot[$url])) {
                    continue;
                }

                $visitedForRoot[$url] = true;
                $visited[$url] = true;
                $processed++;

                $this->emit(sprintf('Fetching Seni24 catalogue page %d: %s', $processed, $url));
                $html = $this->fetchBody($url, $failed);
                if ($html === null) {
                    continue;
                }

                $links = $this->extractLinks($html, $url, $root);

                foreach ($links['products'] as $candidate) {
                    $productUrl = $candidate['url'];
                    $candidate['listing_roots'] = [$root];

                    if (!isset($products[$productUrl])) {
                        $products[$productUrl] = $candidate;
                        continue;
                    }

                    foreach (['listing_pages', 'listing_roots'] as $key) {
                        $products[$productUrl][$key] = array_values(array_unique(array_merge(
                            is_array($products[$productUrl][$key] ?? null) ? $products[$productUrl][$key] : [],
                            is_array($candidate[$key] ?? null) ? $candidate[$key] : [],
                        )));
                    }

                    if (($products[$productUrl]['name'] ?? '') === '' && ($candidate['name'] ?? '') !== '') {
                        $products[$productUrl]['name'] = $candidate['name'];
                    }

                    if (($products[$productUrl]['price_gross_amount'] ?? null) === null
                        && ($candidate['price_gross_amount'] ?? null) !== null) {
                        $products[$productUrl]['price_gross_amount'] = $candidate['price_gross_amount'];
                    }
                }

                foreach ($links['pagination_urls'] as $pageUrl) {
                    if (!isset($visitedForRoot[$pageUrl]) && !in_array($pageUrl, $queue, true)) {
                        $queue[] = $pageUrl;
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
            'visited_root_category_memberships' => $processed,
            'queued_category_urls_remaining' => $queuedRemaining,
            'max_category_pages' => $this->maxCategoryPages,
            'stopped_at_category_limit' => $stopped,
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array{products:list<array<string,mixed>>,pagination_urls:list<string>}
     */
    public function extractLinks(string $html, string $baseUrl, ?string $rootUrl = null): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return ['products' => [], 'pagination_urls' => []];
        }

        $products = [];
        $pages = [];
        $rootUrl ??= $baseUrl;
        $rootPath = (string) parse_url($rootUrl, PHP_URL_PATH);

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$products, &$pages, $baseUrl, $rootPath): void {
            if ($this->isSiteChromeAnchor($node)) {
                return;
            }

            $href = $node->attr('href');
            if (!is_string($href)) {
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

                if (!isset($products[$productUrl])) {
                    $products[$productUrl] = $candidate;
                } elseif ($products[$productUrl]['name'] === '' && $candidate['name'] !== '') {
                    $products[$productUrl]['name'] = $candidate['name'];
                }
                return;
            }

            $pageUrl = $this->normalizePaginationUrl($href, $baseUrl, $rootPath);
            if ($pageUrl !== null) {
                $pages[$pageUrl] = true;
            }
        });

        return [
            'products' => array_values($products),
            'pagination_urls' => array_keys($pages),
        ];
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, false);
        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        return preg_match('/_[0-9]+-[0-9]+(?:\\.html)?$/u', rtrim($path, '/')) === 1
            ? rtrim($normalized, '/')
            : null;
    }

    public function normalizeListingUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, true);
        if ($normalized === null || $this->normalizeProductUrl($normalized) !== null) {
            return null;
        }

        return $normalized;
    }

    private function normalizePaginationUrl(string $url, string $baseUrl, string $rootPath): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl, true);
        if ($normalized === null) {
            return null;
        }

        $parts = parse_url($normalized);
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (rtrim($path, '/') !== rtrim($rootPath, '/') || !isset($query['page'])) {
            return null;
        }

        $page = filter_var($query['page'], FILTER_VALIDATE_INT);
        if (!is_int($page) || $page < 1) {
            return null;
        }

        return $normalized;
    }

    /** @param array<string,string> $failed */
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

        if (!$response->successful()) {
            $failed[$url] = 'HTTP '.$response->status();
            return null;
        }

        return $response->body();
    }

    private function isSiteChromeAnchor(Crawler $node): bool
    {
        $domNode = $node->getNode(0);
        if (!$domNode instanceof DOMElement) {
            return false;
        }

        for ($depth = 0, $current = $domNode; $current instanceof DOMElement && $depth < 8; $depth++, $current = $current->parentElement) {
            $tag = mb_strtolower($current->tagName);
            if (in_array($tag, ['header', 'nav', 'footer'], true)) {
                return true;
            }

            $signature = mb_strtolower(trim($current->getAttribute('id').' '.$current->getAttribute('class')));
            if ($signature !== '' && preg_match('/(?:^|[ _-])(header|footer|navbar|main-menu|top-menu|breadcrumb)(?:$|[ _-])/u', $signature) === 1) {
                return true;
            }
        }

        return false;
    }

    private function productName(Crawler $node, string $url): string
    {
        $text = $this->text($node->text(''));
        if ($text !== '') {
            return $text;
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
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = preg_replace('/_[0-9]+-[0-9]+(?:\\.html)?$/u', '', basename($path));

        return Str::headline((string) $slug);
    }

    private function priceNearAnchor(Crawler $node): ?float
    {
        $domNode = $node->getNode(0);
        if (!$domNode instanceof DOMElement) {
            return null;
        }

        for ($depth = 0, $current = $domNode; $current instanceof DOMElement && $depth < 6; $depth++, $current = $current->parentElement) {
            $text = $this->text($current->textContent ?? '');
            if (preg_match('/(?:Cena\\s+1\\s+opak\\.\\s*)?(\\d{1,7}[,.]\\d{2})\\s*zł/iu', $text, $m) === 1) {
                return (float) str_replace(',', '.', $m[1]);
            }
        }

        return null;
    }

    private function normalizeUrl(string $url, ?string $baseUrl, bool $preserveQuery): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(?:mailto|tel|javascript):/iu', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::HOST.$url;
        } elseif (!preg_match('#^https?://#iu', $url)) {
            if ($baseUrl === null) {
                return null;
            }
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
            $url = 'https://'.self::HOST.($dir === '' || $dir === '.' ? '' : $dir).'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        if (!in_array($host, ['seni24.pl', 'www.seni24.pl'], true)) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/').'/';

        $normalized = 'https://'.self::HOST.$path;

        if ($preserveQuery && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            $allowed = [];
            foreach (['page', 'cacheables'] as $key) {
                if (array_key_exists($key, $query) && is_scalar($query[$key])) {
                    $allowed[$key] = (string) $query[$key];
                }
            }
            if ($allowed !== []) {
                ksort($allowed);
                $normalized .= '?'.http_build_query($allowed, '', '&', PHP_QUERY_RFC3986);
            }
        }

        return $normalized;
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /** @return array<string,string> */
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
