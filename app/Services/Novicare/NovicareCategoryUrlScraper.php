<?php

declare(strict_types=1);

namespace App\Services\Novicare;

use Closure;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class NovicareCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://novicare.pl/produkty/';

    private const CANONICAL_HOST = 'novicare.pl';

    /**
     * @var array<string, string>
     */
    private const CATEGORY_NAME_FALLBACKS = [
        'tulow' => 'Tułów',
        'nadgarstek' => 'Nadgarstek',
        'stopa' => 'Stopa',
        'bark' => 'Bark',
        'kolano' => 'Kolano',
        'lokiec' => 'Łokieć',
        'szyja' => 'Szyja',
        'akcesoria' => 'Akcesoria',
        'poduszki' => 'Poduszki',
        'palce' => 'Palce',
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
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     source: string,
     *     start_urls: array<int, string>,
     *     top_categories: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     category_urls: array<int, string>,
     *     product_category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $normalizedStartUrls = [];
        $visited = [];
        $failed = [];
        $categoriesByUrl = [];
        $discoveryOrder = 0;

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isNovicareUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Novicare product category page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractCategories($html, $url) as $category) {
                $categoryUrl = (string) $category['url'];

                if (isset($categoriesByUrl[$categoryUrl])) {
                    continue;
                }

                $category['discovery_order'] = $discoveryOrder++;
                $categoriesByUrl[$categoryUrl] = $category;
            }
        }

        $categories = array_values($categoriesByUrl);

        usort(
            $categories,
            static fn (array $left, array $right): int => ((int) $left['discovery_order']) <=> ((int) $right['discovery_order'])
        );

        foreach ($categories as &$category) {
            unset($category['discovery_order']);
        }
        unset($category);

        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories,
        ));

        return [
            'source' => 'novicare',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $categories,
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCategories(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $categories = [];

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$categories, $baseUrl): void {
            $node = $link->getNode(0);

            if (! $node instanceof DOMElement) {
                return;
            }

            $url = $this->normalizeUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null) {
                return;
            }

            $slug = $this->categorySlugFromUrl($url);

            if ($slug === null || isset($categories[$url])) {
                return;
            }

            $name = $this->normalizeText($link->text(''));

            if ($name === '') {
                $name = $this->headingFromAncestor($node);
            }

            if ($name === '') {
                $name = self::CATEGORY_NAME_FALLBACKS[$slug] ?? $this->humanizeSlug($slug);
            }

            $categories[$url] = [
                'source' => 'novicare',
                'external_category_id' => $slug,
                'slug' => $slug,
                'name' => $name,
                'url' => $url,
                'path' => [$name],
                'level' => 1,
                'parent_external_category_id' => null,
                'top_category_external_id' => $slug,
                'top_category_name' => $name,
                'has_children' => false,
                'is_product_category' => true,
            ];
        });

        return array_values($categories);
    }

    private function headingFromAncestor(DOMElement $link): string
    {
        $ancestor = $link->parentNode;
        $depth = 0;

        while ($ancestor instanceof DOMNode && $depth < 5) {
            if ($ancestor instanceof DOMElement) {
                $crawler = new Crawler($ancestor);
                $headings = $crawler->filter('h1, h2, h3, h4, h5, h6');

                if ($headings->count() > 0) {
                    $heading = $this->normalizeText($headings->first()->text(''));

                    if ($heading !== '') {
                        return $heading;
                    }
                }
            }

            $ancestor = $ancestor->parentNode;
            $depth++;
        }

        return '';
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
                    ->withOptions(['verify' => $this->verifyTls])
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->emit(sprintf(
                        '  Novicare request failed on attempt %d/%d: %s',
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
                    '  Novicare request returned %s on attempt %d/%d.',
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

    private function categorySlugFromUrl(string $url): ?string
    {
        if (! $this->isNovicareUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/produkty/([^/]+)/$#u', $path, $matches) !== 1) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function normalizeUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with(mb_strtolower($candidate), 'javascript:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = 'https://'.self::CANONICAL_HOST.$candidate;
        } elseif (parse_url($candidate, PHP_URL_SCHEME) === null) {
            $basePath = str_replace('\\', '/', (string) parse_url($baseUrl, PHP_URL_PATH));
            $baseDirectory = str_ends_with($basePath, '/')
                ? rtrim($basePath, '/')
                : rtrim(dirname($basePath), '/');
            $candidate = 'https://'.self::CANONICAL_HOST.($baseDirectory !== '' ? $baseDirectory.'/' : '/').$candidate;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, [self::CANONICAL_HOST, 'www.'.self::CANONICAL_HOST], true)) {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));

        if ($path !== '/' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return 'https://'.self::CANONICAL_HOST.$path;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = rawurlencode(rawurldecode($segment));
        }

        return '/'.implode('/', $segments);
    }

    private function isNovicareUrl(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, [self::CANONICAL_HOST, 'www.'.self::CANONICAL_HOST], true);
    }

    private function humanizeSlug(string $slug): string
    {
        $value = str_replace(['-', '_'], ' ', rawurldecode($slug));

        return mb_convert_case($this->normalizeText($value), MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopNovicareCategoryCrawler/1.0)',
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
