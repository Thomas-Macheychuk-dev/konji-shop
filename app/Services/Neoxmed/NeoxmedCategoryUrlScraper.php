<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class NeoxmedCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://neoxmed.com/';

    private const NEOXMED_HOSTS = ['neoxmed.com', 'www.neoxmed.com'];

    /**
     * NeoxMed publishes its catalogue as seven static WordPress category pages.
     * Individual products are sections on those pages rather than dedicated URLs.
     *
     * @var array<string, string>
     */
    private const TARGET_CATEGORIES = [
        '/ortezy-konczyn-dolnych/' => 'Ortezy kończyn dolnych',
        '/ortezy-konczyn-gornych/' => 'Ortezy kończyn górnych',
        '/ortezy-tulowia/' => 'Ortezy tułowia',
        '/ortezy-szyi/' => 'Ortezy szyi',
        '/ortezy-barku/' => 'Ortezy barku',
        '/temblaki/' => 'Temblaki',
        '/opaski-elastyczne/' => 'Opaski elastyczne',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 500;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

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
        $categoriesByPath = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isNeoxmedUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching NeoxMed catalogue navigation: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractCategories($html, $url) as $category) {
                $categoriesByPath[(string) $category['slug']] = $category;
            }
        }

        $categories = [];

        foreach (self::TARGET_CATEGORIES as $path => $expectedName) {
            $slug = trim($path, '/');

            if (isset($categoriesByPath[$slug])) {
                $categories[] = $categoriesByPath[$slug];
            }
        }

        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories,
        ));

        return [
            'source' => 'neoxmed',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  array<string, string>  $failed
     */
    private function fetchBody(string $url, array &$failed): ?string
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();

                    continue;
                }

                $failed[$url] = $exception->getMessage();

                return null;
            }

            if ($response->successful()) {
                return $response->body();
            }

            if ($attempt < $this->maxAttempts && $this->isRetryableResponse($response)) {
                $this->pauseBeforeRetry();

                continue;
            }

            $failed[$url] = 'HTTP '.$response->status();

            return null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $categories = [];

        $crawler->filter('a[href]')->each(function (Crawler $anchor) use (&$categories, $baseUrl): void {
            $href = (string) $anchor->attr('href');
            $url = $this->normalizeUrl($href, $baseUrl);

            if ($url === null || ! $this->isNeoxmedUrl($url)) {
                return;
            }

            $path = $this->normalizedPath($url);

            if ($path === null || ! isset(self::TARGET_CATEGORIES[$path])) {
                return;
            }

            $expectedName = self::TARGET_CATEGORIES[$path];
            $label = $this->normalizeText($anchor->text(''));
            $name = $label !== '' ? $label : $expectedName;
            $slug = trim($path, '/');

            $categories[$slug] = [
                'external_category_id' => $slug,
                'name' => $name,
                'slug' => $slug,
                'url' => 'https://neoxmed.com'.$path,
                'level' => 1,
                'parent_external_category_id' => null,
                'top_category_name' => $name,
                'path' => [$name],
                'has_children' => false,
                'is_product_category' => true,
            ];
        });

        return array_values($categories);
    }

    public function normalizeUrl(string $url, string $baseUrl = self::DEFAULT_URL): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(?:mailto|tel|javascript):/i', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://neoxmed.com'.$url;
        } elseif (parse_url($url, PHP_URL_SCHEME) === null) {
            $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
            $url = 'https://neoxmed.com'.$basePath.'/'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        if (! in_array($host, self::NEOXMED_HOSTS, true)) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/').'/';
        }

        return 'https://neoxmed.com'.$path;
    }

    private function normalizedPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $path === '/' ? '/' : rtrim($path, '/').'/';
    }

    private function isNeoxmedUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::NEOXMED_HOSTS, true);
    }

    private function isRetryableResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopCatalogBot/1.0; +https://konji.pl)',
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

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
