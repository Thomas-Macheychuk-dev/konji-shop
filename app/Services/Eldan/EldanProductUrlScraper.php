<?php

declare(strict_types=1);

namespace App\Services\Eldan;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class EldanProductUrlScraper
{
    public const DEFAULT_CATEGORY_URLS = [
        'https://eldan.pl/odziez-medyczna-damska',
        'https://eldan.pl/odziez-medyczna-meska',
        'https://eldan.pl/obuwie-medyczne',
        'https://eldan.pl/odziez-medyczna-dla-studentow',
    ];

    private const ELDAN_HOST = 'eldan.pl';

    public function __construct(
        private readonly EldanProductPayloadExtractor $payloadExtractor,
    ) {
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array{
     *     product_urls: array<int, string>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(
        array $startUrls = self::DEFAULT_CATEGORY_URLS,
        int $maxDepth = 3,
        int $maxPages = 500,
        ?int $limit = null,
    ): array {
        $queue = [];
        $visited = [];
        $failed = [];
        $productUrls = [];

        foreach ($startUrls as $url) {
            $normalized = $this->normalizeUrl($url);

            if ($normalized !== null) {
                $queue[] = [$normalized, 0];
            }
        }

        while ($queue !== [] && count($visited) < $maxPages) {
            [$url, $depth] = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            try {
                $response = Http::timeout(30)
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $failed[$url] = $exception->getMessage();

                continue;
            }

            if (! $response->successful()) {
                $failed[$url] = 'HTTP '.$response->status();

                continue;
            }

            $html = $response->body();

            if ($this->isProductPage($html)) {
                $productUrls[$this->canonicalProductUrl($url)] = true;

                if ($limit !== null && count($productUrls) >= $limit) {
                    break;
                }

                continue;
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->extractCandidateUrls($html, $url) as $candidateUrl) {
                if (isset($visited[$candidateUrl])) {
                    continue;
                }

                $queue[] = [$candidateUrl, $depth + 1];
            }
        }

        return [
            'product_urls' => array_keys($productUrls),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }

    private function isProductPage(string $html): bool
    {
        try {
            $payload = $this->payloadExtractor->extract($html);
        } catch (Throwable) {
            return false;
        }

        return filled($payload['product']['id'] ?? null)
            && filled($payload['product']['name'] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function extractCandidateUrls(string $html, string $baseUrl): array
    {
        $urls = [];

        try {
            $crawler = new Crawler($html, $baseUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                $href = $node->attr('href');

                if (! is_string($href) || trim($href) === '') {
                    return;
                }

                $url = $this->normalizeUrl($href, $baseUrl);

                if ($url === null || ! $this->isEldanUrl($url)) {
                    return;
                }

                if (! $this->looksLikeCatalogUrl($url)) {
                    return;
                }

                $urls[$url] = true;
            });
        } catch (Throwable) {
            return [];
        }

        if ($this->htmlLooksPaginated($html)) {
            foreach ($this->generatedPaginationUrls($baseUrl) as $paginationUrl) {
                if ($this->looksLikeCatalogUrl($paginationUrl)) {
                    $urls[$paginationUrl] = true;
                }
            }
        }

        return array_keys($urls);
    }

    private function htmlLooksPaginated(string $html): bool
    {
        $html = mb_strtolower($html);

        return str_contains($html, 'pagination')
            || str_contains($html, 'rel="next"')
            || str_contains($html, "rel='next'")
            || str_contains($html, '?page=')
            || str_contains($html, '&page=')
            || str_contains($html, '?p=')
            || str_contains($html, '&p=');
    }

    /**
     * Eldan category pages may expose explicit pagination links, but this also
     * probes the two common query parameter styles without making the command
     * depend on one exact frontend implementation.
     *
     * @return array<int, string>
     */
    private function generatedPaginationUrls(string $baseUrl): array
    {
        $parts = parse_url($baseUrl);

        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return [];
        }

        parse_str($parts['query'] ?? '', $query);

        $currentPage = (int) ($query['page'] ?? $query['p'] ?? 1);

        if ($currentPage < 1) {
            $currentPage = 1;
        }

        $nextPage = $currentPage + 1;
        $base = $parts['scheme'].'://'.$parts['host'].$parts['path'];

        return [
            $base.'?page='.$nextPage,
            $base.'?p='.$nextPage,
        ];
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with(mb_strtolower($url), 'mailto:')
            || str_starts_with(mb_strtolower($url), 'tel:')
            || str_starts_with(mb_strtolower($url), 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::ELDAN_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! isset($base['scheme'], $base['host'])) {
                return null;
            }

            $basePath = $base['path'] ?? '/';
            $directory = rtrim(str_replace(basename($basePath), '', $basePath), '/');

            $url = $base['scheme'].'://'.$base['host'].$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $parts['scheme'].'://'.$parts['host'].$path.$query;
    }

    private function isEldanUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === self::ELDAN_HOST || $host === 'www.'.self::ELDAN_HOST;
    }

    private function looksLikeCatalogUrl(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($path === '' || $path === '/') {
            return false;
        }

        foreach ($this->disallowedPathFragments() as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        if (preg_match('#^/\d+-[a-z0-9-]+#', $path) === 1) {
            return true;
        }

        foreach ($this->allowedCatalogFragments() as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalProductUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '/');
    }

    /**
     * @return array<int, string>
     */
    private function allowedCatalogFragments(): array
    {
        return [
            'odziez',
            'medycz',
            'obuwie',
            'buty',
            'klapki',
            'bluzy',
            'bluza',
            'sukien',
            'fartuch',
            'polar',
            'spodnie',
            'spodnica',
            'student',
            'czepki',
            'scrub',
            'koszul',
            'outlet',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function disallowedPathFragments(): array
    {
        return [
            '/account',
            '/customer',
            '/checkout',
            '/cart',
            '/koszyk',
            '/login',
            '/register',
            '/wishlist',
            '/search',
            '/kontakt',
            '/regulamin',
            '/polityka',
            '/platnosc',
            '/dostawa',
            '/faq',
            '/zwrot',
            '/reklamac',
            '/newsletter',
            '/media/',
            '/storage/',
        ];
    }
}
