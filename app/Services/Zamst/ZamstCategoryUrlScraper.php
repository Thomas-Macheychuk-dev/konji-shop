<?php

declare(strict_types=1);

namespace App\Services\Zamst;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ZamstCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://zamst.com.pl/sklep/';

    private const CANONICAL_HOST = 'zamst.com.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 2000;

    private int $requestDelayMilliseconds = 500;

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
     * @return array<string, mixed>
     */
    public function scrape(string $startUrl = self::DEFAULT_URL): array
    {
        $startUrl = $this->normalizeCatalogueUrl($startUrl) ?? self::DEFAULT_URL;
        $failedUrls = [];

        $this->emit('Fetching Zamst catalogue page: '.$startUrl);
        $html = $this->fetchBody($startUrl, $failedUrls);

        if ($html === null) {
            return [
                'source' => 'zamst',
                'start_urls' => [$startUrl],
                'top_categories' => [],
                'categories' => [],
                'category_urls' => [],
                'product_category_urls' => [],
                'visited_urls' => [$startUrl],
                'failed_urls' => $failedUrls,
            ];
        }

        $crawler = new Crawler($html, $startUrl);
        $catalogueSectionUrls = $this->catalogueSectionUrls($crawler, $startUrl);
        $categoriesByUrl = [];
        $discoveryOrder = 0;

        $crawler->filter('a[href]')->each(function (Crawler $link) use (
            &$categoriesByUrl,
            &$discoveryOrder,
            $catalogueSectionUrls,
            $startUrl,
        ): void {
            $node = $link->getNode(0);

            if (! $node instanceof DOMElement) {
                return;
            }

            $url = $this->normalizeCategoryUrl($node->getAttribute('href'), $startUrl);

            if ($url === null) {
                return;
            }

            $identity = $this->categoryIdentityFromUrl($url);

            if ($identity === null) {
                return;
            }

            $name = $this->normalizeText($link->text(''));

            if ($name === '') {
                $name = $this->humanizeSlug($identity['slug']);
            }

            if (! isset($categoriesByUrl[$url])) {
                $categoriesByUrl[$url] = [
                    'source' => 'zamst',
                    'external_category_id' => $identity['external_category_id'],
                    'slug' => $identity['slug'],
                    'name' => $name,
                    'url' => $url,
                    'path' => [],
                    'level' => $identity['level'],
                    'parent_external_category_id' => $identity['parent_external_category_id'],
                    'top_category_external_id' => $identity['top_category_external_id'],
                    'top_category_name' => null,
                    'has_children' => false,
                    'is_product_category' => true,
                    'is_catalogue_section' => isset($catalogueSectionUrls[$url]),
                    'product_count' => $catalogueSectionUrls[$url] ?? null,
                    '_discovery_order' => $discoveryOrder++,
                ];

                return;
            }

            if ($categoriesByUrl[$url]['name'] === '' && $name !== '') {
                $categoriesByUrl[$url]['name'] = $name;
            }

            if (isset($catalogueSectionUrls[$url])) {
                $categoriesByUrl[$url]['is_catalogue_section'] = true;
                $categoriesByUrl[$url]['product_count'] = $catalogueSectionUrls[$url];
            }
        });

        $byExternalId = [];

        foreach ($categoriesByUrl as $url => $category) {
            $byExternalId[(string) $category['external_category_id']] = $url;
        }

        foreach ($categoriesByUrl as $url => &$category) {
            $pathIds = $this->categoryPathIds((string) $category['external_category_id']);
            $path = [];

            foreach ($pathIds as $pathId) {
                $pathUrl = $byExternalId[$pathId] ?? null;
                $pathCategory = is_string($pathUrl) ? ($categoriesByUrl[$pathUrl] ?? null) : null;
                $path[] = is_array($pathCategory)
                    ? (string) $pathCategory['name']
                    : $this->humanizeSlug((string) basename($pathId));
            }

            $category['path'] = $path;
            $category['top_category_name'] = $path[0] ?? (string) $category['name'];

            $parentId = $category['parent_external_category_id'];

            if (is_string($parentId) && isset($byExternalId[$parentId])) {
                $parentUrl = $byExternalId[$parentId];
                $categoriesByUrl[$parentUrl]['has_children'] = true;
            }
        }
        unset($category);

        $categories = array_values($categoriesByUrl);
        usort($categories, static function (array $left, array $right): int {
            $levelComparison = ((int) $left['level']) <=> ((int) $right['level']);

            if ($levelComparison !== 0) {
                return $levelComparison;
            }

            return ((int) $left['_discovery_order']) <=> ((int) $right['_discovery_order']);
        });

        foreach ($categories as &$category) {
            unset($category['_discovery_order']);
        }
        unset($category);

        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => (int) $category['level'] === 1,
        ));
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories,
        ));

        return [
            'source' => 'zamst',
            'start_urls' => [$startUrl],
            'top_categories' => $topCategories,
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
            'visited_urls' => [$startUrl],
            'failed_urls' => $failedUrls,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function catalogueSectionUrls(Crawler $crawler, string $baseUrl): array
    {
        $sections = [];

        foreach ($crawler->filter('ul.category-list > li') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $section = new Crawler($node);
            $headingLink = $section->filter('h2 a[href*="/kategoria-produktu/"]')->first();

            if ($headingLink->count() === 0) {
                continue;
            }

            $href = $headingLink->attr('href');
            $url = is_string($href) ? $this->normalizeCategoryUrl($href, $baseUrl) : null;

            if ($url === null) {
                continue;
            }

            $productUrls = [];

            $section->filter('a[href*="/produkt/"]')->each(function (Crawler $link) use (&$productUrls, $baseUrl): void {
                $href = $link->attr('href');
                $productUrl = is_string($href) ? $this->normalizeProductUrl($href, $baseUrl) : null;

                if ($productUrl !== null) {
                    $productUrls[$productUrl] = true;
                }
            });

            $sections[$url] = count($productUrls);
        }

        return $sections;
    }

    /**
     * @return array{external_category_id: string, slug: string, level: int, parent_external_category_id: string|null, top_category_external_id: string}|null
     */
    private function categoryIdentityFromUrl(string $url): ?array
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('#^/kategoria-produktu/(.+)/$#u', $path, $matches) !== 1) {
            return null;
        }

        $externalId = trim($matches[1], '/');
        $segments = array_values(array_filter(explode('/', $externalId)));

        if ($segments === []) {
            return null;
        }

        return [
            'external_category_id' => $externalId,
            'slug' => (string) end($segments),
            'level' => count($segments),
            'parent_external_category_id' => count($segments) > 1
                ? implode('/', array_slice($segments, 0, -1))
                : null,
            'top_category_external_id' => $segments[0],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function categoryPathIds(string $externalCategoryId): array
    {
        $segments = array_values(array_filter(explode('/', trim($externalCategoryId, '/'))));
        $paths = [];

        for ($index = 1; $index <= count($segments); $index++) {
            $paths[] = implode('/', array_slice($segments, 0, $index));
        }

        return $paths;
    }

    private function normalizeCatalogueUrl(string $candidate): ?string
    {
        $normalized = $this->normalizeUrl($candidate, self::DEFAULT_URL);

        if ($normalized === null) {
            return null;
        }

        $path = rawurldecode((string) parse_url($normalized, PHP_URL_PATH));

        return preg_match('#^/sklep(?:/page/[1-9][0-9]*)?/$#u', $path) === 1
            ? $normalized
            : null;
    }

    private function normalizeCategoryUrl(string $candidate, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($candidate, $baseUrl);

        return $normalized !== null && $this->categoryIdentityFromUrl($normalized) !== null
            ? $normalized
            : null;
    }

    private function normalizeProductUrl(string $candidate, string $baseUrl): ?string
    {
        $normalized = $this->normalizeUrl($candidate, $baseUrl);

        if ($normalized === null) {
            return null;
        }

        $path = rawurldecode((string) parse_url($normalized, PHP_URL_PATH));

        return preg_match('#^/produkt/[^/]+/$#u', $path) === 1
            ? $normalized
            : null;
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
            $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
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

    /**
     * @param  array<string, string>  $failedUrls
     */
    private function fetchBody(string $url, array &$failedUrls): ?string
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
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->successful()) {
                unset($failedUrls[$url]);

                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->attempts) {
                $this->pauseBeforeRetry();
            }
        }

        $failedUrls[$url] = $lastFailure;

        return null;
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopZamstCrawler/1.0)',
        ];
    }

    private function humanizeSlug(string $slug): string
    {
        return mb_convert_case(
            $this->normalizeText(str_replace(['-', '_'], ' ', rawurldecode($slug))),
            MB_CASE_TITLE,
            'UTF-8',
        );
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
