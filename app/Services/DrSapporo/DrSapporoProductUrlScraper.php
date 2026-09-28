<?php

declare(strict_types=1);

namespace App\Services\DrSapporo;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class DrSapporoProductUrlScraper
{
    public const DEFAULT_URL = 'https://drsapporo.com/produkty';

    private const HOST = 'drsapporo.com';

    /**
     * @var array<string, array{category_name: string, brand_name: string}>
     */
    private const SECTIONS = [
        'poduszki ortopedyczne dr sapporo' => [
            'category_name' => 'Poduszki ortopedyczne Dr Sapporo',
            'brand_name' => 'Dr Sapporo',
        ],
        'poszewki na poduszki dr sapporo' => [
            'category_name' => 'Poszewki na poduszki Dr Sapporo',
            'brand_name' => 'Dr Sapporo',
        ],
        'poduszki ortopedyczne onsen' => [
            'category_name' => 'Poduszki ortopedyczne ONSEN',
            'brand_name' => 'ONSEN',
        ],
        'poszewki na poduszki onsen' => [
            'category_name' => 'Poszewki na poduszki ONSEN',
            'brand_name' => 'ONSEN',
        ],
        'aparaty na haluksy' => [
            'category_name' => 'Aparaty na haluksy',
            'brand_name' => 'Dr Sapporo',
        ],
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 500;

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

    /**
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $products = [];
        $visited = [];
        $failed = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeCatalogueUrl($startUrl);

            if ($url === null || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Dr Sapporo catalogue: '.$url);

            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractProducts($html, $url) as $product) {
                $productUrl = $product['url'];

                if (! isset($products[$productUrl])) {
                    $products[$productUrl] = $product;

                    continue;
                }

                foreach (['name', 'category_name', 'brand_name', 'source_section'] as $key) {
                    if (($products[$productUrl][$key] ?? '') === '' && ($product[$key] ?? '') !== '') {
                        $products[$productUrl][$key] = $product[$key];
                    }
                }

                if (($products[$productUrl]['price_gross_amount'] ?? null) === null
                    && ($product['price_gross_amount'] ?? null) !== null) {
                    $products[$productUrl]['price_gross_amount'] = $product['price_gross_amount'];
                }
            }
        }

        return [
            'source' => 'drsapporo',
            'start_urls' => array_keys($visited),
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'product_count' => count($products),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractProducts(string $html, string $baseUrl = self::DEFAULT_URL): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $products = [];
        $section = null;

        try {
            $nodes = $crawler->filter('h2, h3, a[href]');
        } catch (Throwable) {
            return [];
        }

        $nodes->each(function (Crawler $node) use (&$products, &$section, $baseUrl): void {
            $nodeName = mb_strtolower($node->nodeName());

            if ($nodeName === 'h2' || $nodeName === 'h3') {
                $section = $this->sectionFromHeading($node->text(''));

                return;
            }

            if (! is_array($section)) {
                return;
            }

            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $url = $this->normalizeProductUrl($href, $baseUrl);

            if ($url === null || ! $this->looksLikeProductAnchor($node, $url)) {
                return;
            }

            $name = $this->productName($node, $url);
            $price = $this->priceNearAnchor($node);

            $products[$url] = [
                'url' => $url,
                'name' => $name,
                'category_name' => $section['category_name'],
                'brand_name' => $section['brand_name'],
                'source_section' => $section['category_name'],
                'price_gross_amount' => $price,
                'currency' => 'PLN',
            ];
        });

        return array_values($products);
    }

    public function normalizeProductUrl(string $url, ?string $baseUrl = null): ?string
    {
        $normalized = $this->normalizeUrl($url, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);

        if (! preg_match('#^/(?:poduszka|poszewka|aparat)-#iu', $path)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeCatalogueUrl(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url);

        if ($normalized === null) {
            return null;
        }

        return (string) parse_url($normalized, PHP_URL_PATH) === '/produkty'
            ? $normalized
            : null;
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

    /**
     * @return array{category_name: string, brand_name: string}|null
     */
    private function sectionFromHeading(string $heading): ?array
    {
        return self::SECTIONS[$this->comparable($heading)] ?? null;
    }

    private function looksLikeProductAnchor(Crawler $node, string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/(?:poduszka|poszewka|aparat)-#iu', $path) !== 1) {
            return false;
        }

        $domNode = $node->getNode(0);

        if (! $domNode instanceof DOMElement) {
            return true;
        }

        for ($depth = 0, $current = $domNode; $current instanceof DOMElement && $depth < 5; $depth++, $current = $current->parentElement) {
            $text = $this->text($current->textContent ?? '');

            if (preg_match('/\b\d{1,5}[,.]\d{2}\s*(?:zł|pln)\b/iu', $text) === 1) {
                return true;
            }
        }

        return true;
    }

    private function productName(Crawler $node, string $url): string
    {
        $name = $this->text($node->text(''));

        if ($name !== '' && ! preg_match('/^\d{1,5}[,.]\d{2}\s*(?:zł|pln)$/iu', $name)) {
            return $name;
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
            // Fall through to URL-derived label.
        }

        return Str::headline((string) basename((string) parse_url($url, PHP_URL_PATH)));
    }

    private function priceNearAnchor(Crawler $node): ?float
    {
        $domNode = $node->getNode(0);

        if (! $domNode instanceof DOMElement) {
            return null;
        }

        for ($depth = 0, $current = $domNode; $current instanceof DOMElement && $depth < 5; $depth++, $current = $current->parentElement) {
            if (preg_match('/\b(\d{1,5}[,.]\d{2})\s*(?:zł|pln)\b/iu', $this->text($current->textContent ?? ''), $matches) === 1) {
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

            $url = rtrim($baseUrl, '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! isset($parts['host'])) {
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

    private function comparable(string $value): string
    {
        $value = Str::ascii($this->text($value));
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
