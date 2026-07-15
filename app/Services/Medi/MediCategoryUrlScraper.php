<?php

declare(strict_types=1);

namespace App\Services\Medi;

use Closure;
use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MediCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://www.medi-polska.pl/shop/';

    private const MEDI_HOST = 'www.medi-polska.pl';

    private const CATEGORY_PATH_PREFIX = '/shop/kategoria-produktu/';

    /**
     * @var array<string, string>
     */
    private const TOP_LEVEL_CATEGORIES = [
        'kompresja' => 'Kompresja',
        'ortopedia' => 'Ortopedia',
        'akcesoria' => 'Akcesoria',
    ];

    /**
     * @var array<int, string>
     */
    private const GENERIC_LINK_LABELS = [
        'all',
        'category',
        'kategoria',
        'more',
        'more-dot-dot-dot',
        'products',
        'produkty',
        'shop',
        'sklep',
        'wiecej',
        'wszystko',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 0;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    private int $maxPages = 100;

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
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['product_category_urls'];
    }

    /**
     * Discover the approved medi Magento category trees beneath Kompresja, Ortopedia,
     * and Akcesoria. Other medi navigation trees are deliberately ignored.
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

            if ($normalized === null || ! $this->isMediShopUrl($normalized)) {
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
            $this->emit('Fetching medi category page: '.$url);

            try {
                $response = $this->fetch($url);

                if (! $response->successful()) {
                    $failed[$url] = 'HTTP '.$response->status();

                    continue;
                }

                foreach ($this->extractCategoryCandidates($response->body(), $url) as $candidateUrl => $candidateLabel) {
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
            'source' => 'medi',
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
            $url = $this->normalizeUrl((string) $node->attr('href'), $baseUrl);

            if ($url === null || ! $this->isApprovedCategoryUrl($url)) {
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
        $rootOrder = array_flip(array_keys(self::TOP_LEVEL_CATEGORIES));

        usort($urls, function (string $left, string $right) use ($rootOrder): int {
            $leftSegments = $this->categoryPathSegments($left);
            $rightSegments = $this->categoryPathSegments($right);

            return count($leftSegments) <=> count($rightSegments)
                ?: (($rootOrder[$leftSegments[0] ?? ''] ?? PHP_INT_MAX) <=> ($rootOrder[$rightSegments[0] ?? ''] ?? PHP_INT_MAX))
                ?: strcmp(implode('/', $leftSegments), implode('/', $rightSegments));
        });

        $categories = [];

        foreach ($urls as $url) {
            $segments = $this->categoryPathSegments($url);

            if ($segments === []) {
                continue;
            }

            $level = count($segments);
            $externalCategoryId = implode('/', $segments);
            $parentExternalCategoryId = $level > 1 ? implode('/', array_slice($segments, 0, -1)) : null;
            $path = [];

            foreach ($segments as $index => $segment) {
                $partialUrl = $this->urlFromCategorySegments(array_slice($segments, 0, $index + 1));
                $path[] = $this->categoryName($partialUrl, $labelsByUrl[$partialUrl] ?? '');
            }

            $categories[] = [
                'source' => 'medi',
                'external_category_id' => $externalCategoryId,
                'name' => $this->categoryName($url, $labelsByUrl[$url]),
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
        $segments = $this->categoryPathSegments($url);

        if ($segments !== [] && count($segments) === 1 && isset(self::TOP_LEVEL_CATEGORIES[$segments[0]])) {
            return self::TOP_LEVEL_CATEGORIES[$segments[0]];
        }

        $label = $this->cleanText($label);

        if ($label !== '' && ! $this->isGenericLabel($label)) {
            return $label;
        }

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
            $url = 'https://'.self::MEDI_HOST.$url;
        } elseif (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '/');
            $baseDirectory = str_ends_with($basePath, '/') ? rtrim($basePath, '/') : dirname($basePath);
            $url = 'https://'.self::MEDI_HOST.'/'.trim($baseDirectory.'/'.$url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        return 'https://'.$host.$this->normalizePath((string) ($parts['path'] ?? '/'));
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        if ($path === '/' || str_ends_with($path, '.html')) {
            return $path;
        }

        return rtrim($path, '/').'/';
    }

    private function isApprovedCategoryUrl(string $url): bool
    {
        $segments = $this->categoryPathSegments($url);

        return $segments !== [] && isset(self::TOP_LEVEL_CATEGORIES[$segments[0]]);
    }

    private function isMediShopUrl(string $url): bool
    {
        if ($this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== self::MEDI_HOST) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === '/shop' || str_starts_with($path, '/shop/');
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return match ($host) {
            'medi-polska.pl' => self::MEDI_HOST,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function categoryPathSegments(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! str_starts_with($path, self::CATEGORY_PATH_PREFIX)) {
            return [];
        }

        $relativePath = substr($path, strlen(self::CATEGORY_PATH_PREFIX));
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/'))));

        if ($segments === []) {
            return [];
        }

        $lastIndex = array_key_last($segments);
        $lastSegment = $segments[$lastIndex];

        if (! str_ends_with($lastSegment, '.html')) {
            return [];
        }

        $segments[$lastIndex] = substr($lastSegment, 0, -5);

        return array_values(array_map(
            static fn (string $segment): string => Str::slug($segment),
            $segments
        ));
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function urlFromCategorySegments(array $segments): string
    {
        $lastSegment = array_pop($segments);

        if (! is_string($lastSegment) || $lastSegment === '') {
            return self::DEFAULT_URL;
        }

        $relativePath = $segments === []
            ? $lastSegment.'.html'
            : implode('/', $segments).'/'.$lastSegment.'.html';

        return 'https://'.self::MEDI_HOST.self::CATEGORY_PATH_PREFIX.$relativePath;
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

        throw $lastException ?? new RuntimeException('Failed to fetch medi URL: '.$url);
    }

    private function shouldRetryResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopMediScraper/1.0; +https://konji.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
