<?php

declare(strict_types=1);

namespace App\Services\Iconic;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class IconicProductUrlScraper
{
    public const HOST = 'sklep.iconic.pl';

    /**
     * @var list<string>
     */
    public const DEFAULT_URLS = [
        'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej',
        'https://sklep.iconic.pl/produkty/podologia',
        'https://sklep.iconic.pl/produkty/zaopatrzenie-ortopedyczne-stopy',
        'https://sklep.iconic.pl/produkty/zaopatrzenie-po-zabiegach-na-hallux-valgus',
        'https://sklep.iconic.pl/produkty/materialy-do-produkcji-wkladek',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 500;

    private int $maxCategoryPages = 500;

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
        $queue = [];
        $start = [];
        $visited = [];
        $failed = [];
        $products = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeCategoryUrl($startUrl);

            if ($url === null || isset($start[$url])) {
                continue;
            }

            $start[$url] = true;
            $queue[] = $url;
        }

        while ($queue !== [] && count($visited) < $this->maxCategoryPages) {
            $url = array_shift($queue);

            if (! is_string($url) || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit(sprintf(
                'Fetching Iconic category %d/%d: %s',
                count($visited),
                count($visited) + count($queue),
                $url,
            ));

            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            $links = $this->extractLinks($html, $url);

            foreach ($links['products'] as $product) {
                $productUrl = $product['url'];

                if (! isset($products[$productUrl])) {
                    $products[$productUrl] = $product;

                    continue;
                }

                if (($products[$productUrl]['name'] ?? '') === '' && ($product['name'] ?? '') !== '') {
                    $products[$productUrl]['name'] = $product['name'];
                }

                if (($products[$productUrl]['price_gross_amount'] ?? null) === null
                    && ($product['price_gross_amount'] ?? null) !== null) {
                    $products[$productUrl]['price_gross_amount'] = $product['price_gross_amount'];
                }

                $listingPages = array_values(array_unique(array_merge(
                    is_array($products[$productUrl]['listing_pages'] ?? null)
                        ? $products[$productUrl]['listing_pages']
                        : [],
                    is_array($product['listing_pages'] ?? null)
                        ? $product['listing_pages']
                        : [],
                )));

                $products[$productUrl]['listing_pages'] = $listingPages;
            }

            foreach ($links['category_urls'] as $categoryUrl) {
                if (! isset($visited[$categoryUrl]) && ! in_array($categoryUrl, $queue, true)) {
                    $queue[] = $categoryUrl;
                }
            }
        }

        return [
            'source' => 'iconic',
            'start_urls' => array_keys($start),
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'product_count' => count($products),
            'visited_urls' => array_keys($visited),
            'visited_category_count' => count($visited),
            'queued_category_urls_remaining' => array_values($queue),
            'max_category_pages' => $this->maxCategoryPages,
            'stopped_at_category_limit' => $queue !== [],
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array{
     *     products: list<array<string, mixed>>,
     *     category_urls: list<string>
     * }
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
            $anchors = $crawler->filter('a[href]');
        } catch (Throwable) {
            return [
                'products' => [],
                'category_urls' => [],
            ];
        }

        $products = [];
        $categoryUrls = [];

        $anchors->each(function (Crawler $node) use (&$products, &$categoryUrls, $baseUrl): void {
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
        $normalized = $this->normalizeUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        return str_starts_with($path, '/produkty/')
            && str_ends_with(mb_strtolower($path), '.html')
                ? $normalized
                : null;
    }

    public function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        if (! str_starts_with($path, '/produkty/')
            || str_ends_with(mb_strtolower($path), '.html')) {
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

    private function productName(Crawler $node, string $url): string
    {
        $name = $this->text($node->text(''));

        if ($name !== '') {
            $name = preg_replace('/\s+\d{1,7}[,.]\d{2}\s*zł\s*$/iu', '', $name) ?? $name;
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
            // Fall through to a URL-derived label.
        }

        $slug = preg_replace('/\.html$/iu', '', (string) basename((string) parse_url($url, PHP_URL_PATH)));

        return Str::headline((string) $slug);
    }

    private function priceNearAnchor(Crawler $node): ?float
    {
        $domNode = $node->getNode(0);

        if (! $domNode instanceof DOMElement) {
            return null;
        }

        for ($depth = 0, $current = $domNode; $current instanceof DOMElement && $depth < 5; $depth++, $current = $current->parentElement) {
            if (preg_match('/\b(\d{1,7}[,.]\d{2})\s*zł\b/iu', $this->text($current->textContent ?? ''), $matches) === 1) {
                return (float) str_replace(',', '.', $matches[1]);
            }
        }

        return null;
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
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
            $url = 'https://'.self::HOST.$url;
        } elseif (! preg_match('#^https?://#iu', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $baseDirectory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

            $url = 'https://'.self::HOST
                .($baseDirectory === '' || $baseDirectory === '.' ? '' : $baseDirectory)
                .'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./iu', '', $host) ?? $host;

        if ($host !== self::HOST) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/') ?: '/';

        return 'https://'.self::HOST.($path === '/' ? '' : $path);
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
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.6',
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
