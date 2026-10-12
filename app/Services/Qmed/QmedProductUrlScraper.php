<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class QmedProductUrlScraper
{
    public const HOST = 'qmedinfo.pl';

    public const CATEGORIES = [
        '/pl/oferta/szyja' => 'Odcinek szyjny',
        '/pl/oferta/konczyna-dolna' => 'Kończyna dolna',
        '/pl/oferta/konczyna-dolna/staw-kolanowy' => 'Staw kolanowy',
        '/pl/oferta/konczyna-dolna/staw-skokowy' => 'Staw skokowy',
        '/pl/oferta/konczyna-dolna/staw-biodrowy' => 'Staw biodrowy',
        '/pl/oferta/konczyna-gorna' => 'Kończyna górna',
        '/pl/oferta/konczyna-gorna/staw-barkowy' => 'Staw barkowy',
        '/pl/oferta/konczyna-gorna/staw-lokciowy' => 'Staw łokciowy',
        '/pl/oferta/konczyna-gorna/staw-nadgarstkowy-i-dlon' => 'Staw nadgarstkowy i dłoń',
        '/pl/oferta/tulow' => 'Tułów',
        '/pl/oferta/tulow/odcinek-ledzwiowo-krzyzowy' => 'Odcinek lędźwiowo-krzyżowy',
        '/pl/oferta/tulow/odcinek-ledzwiowo-krzyzowo-piersiowy' => 'Odcinek lędźwiowo-krzyżowo-piersiowy',
        '/pl/oferta/qmed-kids' => 'Qmed KIDS',
    ];

    private int $delayMs = 500;

    private int $timeout = 20;

    private int $maxPages = 150;

    private ?Closure $progress = null;

    public function withRequestDelayMilliseconds(int $ms): self
    {
        $this->delayMs = max(0, $ms);

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function withMaxPages(int $pages): self
    {
        $this->maxPages = max(1, $pages);

        return $this;
    }

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progress = $callback;

        return $this;
    }

    public function discover(): array
    {
        $products = [];
        $visited = [];
        $failed = [];
        $queue = [];

        foreach (self::CATEGORIES as $path => $name) {
            $queue[] = [
                'url' => 'https://'.self::HOST.$path,
                'category' => $path,
                'category_name' => $name,
            ];
        }

        $processed = [];
        $stoppedAtLimit = false;

        while ($queue !== []) {
            if (count($processed) >= $this->maxPages) {
                $stoppedAtLimit = true;
                break;
            }

            $entry = array_shift($queue);
            $url = $entry['url'];
            $category = $entry['category'];

            $key = $category.'|'.$url;

            if (isset($processed[$key])) {
                continue;
            }

            $processed[$key] = true;
            $visited[$url] = true;

            if ($this->progress !== null) {
                ($this->progress)('Fetching: '.$url);
            }

            try {
                if ($this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }

                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Accept' => 'text/html',
                        'User-Agent' => 'KonjiShop-QmedCatalogueResearch/1.0',
                    ])
                    ->get($url);

                if (! $response->successful()) {
                    $failed[$url] = 'HTTP '.$response->status();

                    continue;
                }

                $links = $this->extractLinks(
                    $response->body(),
                    $url,
                    $category,
                );

                foreach ($links['products'] as $product) {
                    $productUrl = $product['url'];

                    if (! isset($products[$productUrl])) {
                        $products[$productUrl] = $product;
                        $products[$productUrl]['categories'] = [];
                    }

                    $products[$productUrl]['categories'][$category] =
                        $entry['category_name'];

                    if ($products[$productUrl]['name'] === '') {
                        $products[$productUrl]['name'] = $product['name'];
                    }
                }

                foreach ($links['pagination_urls'] as $nextUrl) {
                    $nextKey = $category.'|'.$nextUrl;

                    if (! isset($processed[$nextKey])) {
                        $queue[] = [
                            'url' => $nextUrl,
                            'category' => $category,
                            'category_name' => $entry['category_name'],
                        ];
                    }
                }
            } catch (Throwable $e) {
                $failed[$url] = $e->getMessage();
            }
        }

        ksort($products);

        foreach ($products as &$product) {
            $product['categories'] = array_map(
                static fn (string $path, string $name): array => [
                    'path' => $path,
                    'name' => $name,
                ],
                array_keys($product['categories']),
                array_values($product['categories']),
            );
        }
        unset($product);

        return [
            'source' => 'qmed',
            'category_count' => count(self::CATEGORIES),
            'visited_category_pages' => count($visited),
            'product_count' => count($products),
            'product_urls' => array_keys($products),
            'products' => array_values($products),
            'failed_urls' => $failed,
            'max_pages' => $this->maxPages,
            'stopped_at_limit' => $stoppedAtLimit,
            'queued_pages_remaining' => count($queue),
        ];
    }

    public function extractLinks(
        string $html,
        string $pageUrl,
        string $categoryPath,
    ): array {
        $crawler = new Crawler($html, $pageUrl);
        $products = [];
        $pagination = [];

        $crawler->filter('h3.catItemTitle a[href]')
            ->each(function (Crawler $node) use (&$products, $pageUrl, $categoryPath): void {
                $url = $this->absoluteUrl(
                    (string) $node->attr('href'),
                    $pageUrl,
                );

                if ($url === null) {
                    return;
                }

                $path = (string) parse_url($url, PHP_URL_PATH);

                if (! str_starts_with($path, rtrim($categoryPath, '/').'/')) {
                    return;
                }

                $name = trim(preg_replace(
                    '/\s+/u',
                    ' ',
                    $node->text(),
                ) ?? '');

                $products[$url] = [
                    'url' => $url,
                    'name' => $name,
                    'source_category' => $categoryPath,
                ];
            });

        // Qmed uses a Joomla/K2-style catalogue. Only treat links
        // located inside pagination controls as further listing pages.
        $crawler->filter(
            '.k2Pagination a[href], .pagination a[href], '
            .'a[rel="next"][href], .pager a[href]'
        )->each(function (Crawler $node) use (&$pagination, $pageUrl, $categoryPath): void {
            $url = $this->absoluteUrl(
                (string) $node->attr('href'),
                $pageUrl,
                true,
            );

            if ($url === null || $url === $pageUrl) {
                return;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $basePath = rtrim($categoryPath, '/');

            if ($path === $basePath
                || preg_match(
                    '#^'.preg_quote($basePath, '#').'/(?:start|page)/\d+/?$#',
                    $path
                ) === 1) {
                $pagination[$url] = true;
            }
        });

        return [
            'products' => array_values($products),
            'pagination_urls' => array_keys($pagination),
        ];
    }

    private function absoluteUrl(
        string $href,
        string $base,
        bool $allowQuery = false,
    ): ?string {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (str_starts_with($href, '/')) {
            $href = 'https://'.self::HOST.$href;
        } elseif (! preg_match('#^https?://#i', $href)) {
            $basePath = (string) parse_url($base, PHP_URL_PATH);
            $directory = substr($basePath, 0, strrpos($basePath, '/') + 1);
            $href = 'https://'.self::HOST.$directory.$href;
        }

        $parts = parse_url($href);

        if ($parts === false
            || strtolower($parts['host'] ?? '') !== self::HOST
            || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (str_contains($path, '..')
            || ! str_starts_with($path, '/pl/oferta/')) {
            return null;
        }

        return 'https://'.self::HOST
            .rtrim($path, '/')
            .($allowQuery && isset($parts['query'])
                ? '?'.$parts['query']
                : '');
    }
}
