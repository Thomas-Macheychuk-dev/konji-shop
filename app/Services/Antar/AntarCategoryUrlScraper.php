<?php

declare(strict_types=1);

namespace App\Services\Antar;

use Closure;
use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class AntarCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://antar.net/produkty/';

    private const ANTAR_HOST = 'antar.net';

    /**
     * @var array<string, string>
     */
    private const TOP_LEVEL_CATEGORIES = [
        'ortopedia' => 'Ortopedia',
        'rehabilitacja' => 'Rehabilitacja',
        'sprzet-pomocniczy-i-sanitarny' => 'Sprzęt pomocniczy i sanitarny',
        'wyroby-niemedyczne' => 'Wyroby niemedyczne',
    ];

    /**
     * @var array<int, string>
     */
    private const GENERIC_LINK_LABELS = [
        'all',
        'product',
        'products',
        'produkty',
        'shop',
        'skip-to-content',
        'sklep',
        'przejdz-do-tresci',
        'wszystko',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    private int $maxPages = 250;

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

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->maxAttempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds);

        return $this;
    }

    public function withMaxPages(int $pages): self
    {
        $this->maxPages = max(1, $pages);

        return $this;
    }

    /**
     * Convenience wrapper for callers/tests that only need product-scraping category URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * Discover Antar WooCommerce product-category hierarchy from the Elementor product menu.
     *
     * Antar category URLs live under `/produkty/.../`. Product detail URLs do not use this
     * prefix, so every valid `/produkty/{top-level}/.../` URL can be used as a category URL.
     * The product menu is duplicated for desktop/tablet/mobile, so URLs are normalized and
     * deduplicated before the hierarchy is built.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     source: string,
     *     start_urls: array<int, string>,
     *     top_categories: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     category_urls: array<int, string>,
     *     product_category_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $visited = [];
        $failed = [];
        $normalizedStartUrls = [];
        $categoryLabelsByUrl = [];
        $queue = [];

        foreach ($startUrls as $url) {
            $normalized = $this->normalizeUrl($url, self::DEFAULT_URL);

            if ($normalized === null || ! $this->isAntarUrl($normalized)) {
                continue;
            }

            $normalizedStartUrls[] = $normalized;
            $queue[] = $normalized;
        }

        $normalizedStartUrls = array_values(array_unique($normalizedStartUrls));
        $queue = array_values(array_unique($queue));

        while ($queue !== [] && count($visited) < $this->maxPages) {
            $url = array_shift($queue);

            if (! is_string($url) || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Antar category page: '.$url);

            try {
                $response = $this->fetch($url);

                if (! $response->successful()) {
                    $failed[$url] = 'HTTP '.$response->status();

                    continue;
                }

                $candidates = $this->extractCategoryCandidates($response->body(), $url);

                foreach ($candidates as $candidateUrl => $candidateLabel) {
                    $categoryLabelsByUrl[$candidateUrl] = $this->preferredLabel(
                        $categoryLabelsByUrl[$candidateUrl] ?? null,
                        $candidateLabel,
                        $candidateUrl
                    );

                    if (! isset($visited[$candidateUrl]) && ! in_array($candidateUrl, $queue, true)) {
                        $queue[] = $candidateUrl;
                    }
                }
            } catch (Throwable $exception) {
                $failed[$url] = $exception->getMessage();
            }
        }

        $categories = $this->buildCategories($categoryLabelsByUrl);
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories
        ));

        return [
            'source' => 'antar',
            'start_urls' => $normalizedStartUrls,
            'top_categories' => array_values(array_filter(
                $categories,
                static fn (array $category): bool => ((int) $category['level']) === 1
            )),
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractCategoryCandidates(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $candidates = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$candidates, $baseUrl): void {
            $href = (string) $node->attr('href');
            $url = $this->normalizeUrl($href, $baseUrl);

            if ($url === null || ! $this->isCategoryUrl($url)) {
                return;
            }

            $label = $this->cleanText($node->text('', false));

            if ($label === '') {
                /** @var DOMElement|null $element */
                $element = $node->getNode(0);
                $label = $this->cleanText((string) ($element?->getAttribute('title') ?: $element?->getAttribute('aria-label')));
            }

            $candidates[$url] = $this->preferredLabel($candidates[$url] ?? null, $label, $url);
        });

        return $candidates;
    }

    /**
     * @param  array<string, string>  $labelsByUrl
     * @return array<int, array<string, mixed>>
     */
    private function buildCategories(array $labelsByUrl): array
    {
        $urls = array_keys($labelsByUrl);

        usort($urls, function (string $left, string $right): int {
            $leftSegments = $this->categoryPathSegments($left);
            $rightSegments = $this->categoryPathSegments($right);

            return count($leftSegments) <=> count($rightSegments)
                ?: strcmp(implode('/', $leftSegments), implode('/', $rightSegments));
        });

        $categories = [];
        $knownUrls = [];

        foreach ($urls as $url) {
            $segments = $this->categoryPathSegments($url);

            if ($segments === []) {
                continue;
            }

            $level = count($segments);
            $externalCategoryId = implode('/', $segments);
            $parentExternalCategoryId = $level > 1 ? implode('/', array_slice($segments, 0, -1)) : null;
            $name = $this->categoryName($url, $labelsByUrl[$url]);
            $path = [];

            foreach ($segments as $index => $segment) {
                $partialUrl = $this->urlFromCategorySegments(array_slice($segments, 0, $index + 1));
                $path[] = $this->categoryName($partialUrl, $labelsByUrl[$partialUrl] ?? '');
            }

            $knownUrls[$url] = true;

            $categories[] = [
                'source' => 'antar',
                'external_category_id' => $externalCategoryId,
                'name' => $name,
                'source_name' => $labelsByUrl[$url],
                'url' => $url,
                'slug' => (string) end($segments),
                'level' => $level,
                'parent_external_category_id' => $parentExternalCategoryId,
                'path' => $path,
                'product_count' => null,
            ];
        }

        return array_values($categories);
    }

    private function categoryName(string $url, string $label): string
    {
        $label = $this->cleanText($label);

        $segments = $this->categoryPathSegments($url);

        if ($segments !== []) {
            $lastSegment = (string) end($segments);

            if (count($segments) === 1 && isset(self::TOP_LEVEL_CATEGORIES[$lastSegment])) {
                return self::TOP_LEVEL_CATEGORIES[$lastSegment];
            }
        }

        if ($label !== '' && ! $this->isGenericLabel($label)) {
            return $label;
        }

        $segments = $this->categoryPathSegments($url);

        if ($segments === []) {
            return $label;
        }

        return $this->headlineFromSlug((string) end($segments));
    }

    private function preferredLabel(?string $current, string $candidate, string $url): string
    {
        $candidate = $this->cleanText($candidate);
        $current = $current === null ? '' : $this->cleanText($current);

        if ($current === '') {
            return $candidate !== '' ? $candidate : $this->categoryName($url, '');
        }

        if ($candidate === '') {
            return $current;
        }

        if ($this->isGenericLabel($current) && ! $this->isGenericLabel($candidate)) {
            return $candidate;
        }

        if (mb_strlen($candidate) > mb_strlen($current) && ! $this->isGenericLabel($candidate)) {
            return $candidate;
        }

        return $current;
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;

        return trim($value);
    }

    private function isGenericLabel(string $label): bool
    {
        return in_array(Str::slug($label), self::GENERIC_LINK_LABELS, true);
    }

    private function headlineFromSlug(string $slug): string
    {
        return Str::of($slug)
            ->replace('-', ' ')
            ->ucfirst()
            ->toString();
    }

    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || $url === '#' || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $parts = parse_url($baseUrl);
            $host = (string) ($parts['host'] ?? self::ANTAR_HOST);
            $url = 'https://'.$host.$url;
        } elseif (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = rtrim($baseUrl, '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $normalizedPath = $this->normalizePath($path);

        return 'https://'.$host.$normalizedPath;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/').'/';
    }

    private function isCategoryUrl(string $url): bool
    {
        if (! $this->isAntarUrl($url)) {
            return false;
        }

        $segments = $this->categoryPathSegments($url);

        if ($segments === []) {
            return false;
        }

        return isset(self::TOP_LEVEL_CATEGORIES[$segments[0]]);
    }

    private function isAntarUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::ANTAR_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return match ($host) {
            self::ANTAR_HOST => self::ANTAR_HOST,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function categoryPathSegments(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if ($segments === [] || $segments[0] !== 'produkty') {
            return [];
        }

        array_shift($segments);

        if ($segments === []) {
            return [];
        }

        $segments = array_values(array_map(
            static fn (string $segment): string => Str::slug($segment),
            $segments
        ));

        if ($this->containsPaginationSegments($segments)) {
            return [];
        }

        return $segments;
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function containsPaginationSegments(array $segments): bool
    {
        foreach ($segments as $index => $segment) {
            if ($segment === 'page') {
                return true;
            }

            if ($index > 0 && $segments[$index - 1] === 'page' && preg_match('/^\d+$/', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function urlFromCategorySegments(array $segments): string
    {
        return 'https://'.self::ANTAR_HOST.'/produkty/'.implode('/', $segments).'/';
    }


    private function fetch(string $url): Response
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $this->pauseBeforeRequest();

                $response = Http::withHeaders($this->headers())
                    ->connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->get($url);

                if ($this->shouldRetryResponse($response) && $attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();

                    continue;
                }

                return $response;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt >= $this->maxAttempts) {
                    throw $exception;
                }

                $this->pauseBeforeRetry();
            }
        }

        throw $lastException ?? new \RuntimeException('Failed to fetch Antar URL: '.$url);
    }

    private function shouldRetryResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->retryDelayMilliseconds * 1000);
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopAntarScraper/1.0; +https://konji.pl)',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
