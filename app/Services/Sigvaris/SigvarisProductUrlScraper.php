<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

final class SigvarisProductUrlScraper extends SigvarisHttpClient
{
    /**
     * @param array<string, mixed>|null $categoryDiscovery
     * @return array<string, mixed>
     */
    public function scrape(?array $categoryDiscovery = null, ?int $categoryLimit = null, ?int $pageLimit = null): array
    {
        $categories = $this->sourceCategories($categoryDiscovery);
        if ($categoryLimit !== null) {
            $categories = array_slice($categories, 0, max(1, $categoryLimit));
        }

        $visited = [];
        $failed = [];
        $productsByUrl = [];
        $categoryResults = [];

        foreach ($categories as $categoryIndex => $category) {
            $categoryUrl = (string) $category['url'];
            $path = array_values($category['path'] ?? [$category['name'] ?? $categoryUrl]);
            $page = 1;
            $pagesScraped = 0;
            $categoryProducts = [];
            $maximumPages = $pageLimit !== null ? max(1, $pageLimit) : null;

            while (true) {
                if ($maximumPages !== null && $pagesScraped >= $maximumPages) {
                    break;
                }

                $pageUrl = $page === 1 ? $categoryUrl : $this->withPage($categoryUrl, $page);
                if (isset($visited[$pageUrl])) {
                    break;
                }

                $visited[$pageUrl] = true;
                $this->emit(sprintf(
                    'Category %d/%d page %d: %s',
                    $categoryIndex + 1,
                    count($categories),
                    $page,
                    implode(' > ', $path),
                ));

                $html = $this->fetchBody($pageUrl, $failed);
                if ($html === null) {
                    break;
                }

                $pagesScraped++;
                $crawler = new Crawler($html, $pageUrl);
                $pageProducts = $this->productLinks($crawler, $pageUrl);

                foreach ($pageProducts as $product) {
                    $url = $product['url'];
                    $categoryProducts[$url] = true;

                    if (! isset($productsByUrl[$url])) {
                        $productsByUrl[$url] = $product + [
                            'source' => 'sigvaris',
                            'category_paths' => [],
                            'category_urls' => [],
                        ];
                    }

                    $pathKey = implode(' > ', $path);
                    $existingPaths = $productsByUrl[$url]['category_paths'];
                    $pathKeys = array_map(static fn (array $p): string => implode(' > ', $p), $existingPaths);
                    if (! in_array($pathKey, $pathKeys, true)) {
                        $productsByUrl[$url]['category_paths'][] = $path;
                    }
                    if (! in_array($categoryUrl, $productsByUrl[$url]['category_urls'], true)) {
                        $productsByUrl[$url]['category_urls'][] = $categoryUrl;
                    }
                }

                $totalPages = $this->totalPages($crawler);
                if ($totalPages !== null) {
                    if ($page >= $totalPages) {
                        break;
                    }
                    $page++;
                    continue;
                }

                $next = $this->nextPageUrl($crawler, $pageUrl);
                if ($next === null || isset($visited[$next])) {
                    break;
                }

                $nextPage = $this->pageNumber($next);
                if ($nextPage === null || $nextPage <= $page) {
                    break;
                }
                $page = $nextPage;
            }

            $categoryResults[] = [
                'external_category_id' => $category['external_category_id'] ?? null,
                'category_name' => $category['name'] ?? null,
                'category_path' => $path,
                'category_url' => $categoryUrl,
                'product_count' => count($categoryProducts),
                'pages_scraped' => $pagesScraped,
            ];
        }

        $products = array_values($productsByUrl);

        return [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'source_categories' => $categories,
            'category_results' => $categoryResults,
            'products' => $products,
            'product_urls' => array_values(array_map(static fn (array $p): string => $p['url'], $products)),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
    }

    /** @param array<string, mixed>|null $discovery @return array<int, array<string,mixed>> */
    private function sourceCategories(?array $discovery): array
    {
        $categories = [];
        foreach ($discovery['categories'] ?? [] as $category) {
            if (! is_array($category) || ! isset($category['url'])) {
                continue;
            }
            $url = $this->normalizeCategoryUrl((string) $category['url']);
            if ($url === null) {
                continue;
            }
            $category['url'] = $url;
            $category['path'] = array_values(array_filter(
                is_array($category['path'] ?? null) ? $category['path'] : [],
                static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
            ));
            if ($category['path'] === []) {
                $category['path'] = [(string) ($category['name'] ?? $url)];
            }
            $categories[$url] = $category;
        }

        if ($categories === []) {
            foreach ([
                ['17', 'Wyroby kompresyjne', 'https://www.sklep-sigvaris.com/17-wyroby-kompresyjne'],
                ['39', 'Wyroby ortopedyczne', 'https://www.sklep-sigvaris.com/39-wyroby-ortopedyczne'],
                ['334', 'Kompresja profilaktyczna', 'https://www.sklep-sigvaris.com/334-kompresja-profilaktyczna'],
                ['242', 'Akcesoria', 'https://www.sklep-sigvaris.com/242-akcesoria'],
            ] as [$id, $name, $url]) {
                $categories[$url] = [
                    'source' => 'sigvaris',
                    'external_category_id' => $id,
                    'name' => $name,
                    'path' => [$name],
                    'url' => $url,
                ];
            }
        }

        return array_values($categories);
    }

    /** @return array<int, array{url:string,external_product_id:string,default_combination_id:string|null,slug:string}> */
    private function productLinks(Crawler $crawler, string $baseUrl): array
    {
        $links = [];
        $selectors = [
            'article.product-miniature a[href]',
            '.product-miniature a[href]',
            '#products .products a[href]',
            '#products a[href]',
        ];

        foreach ($selectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }
            $crawler->filter($selector)->each(function (Crawler $link) use (&$links, $baseUrl): void {
                $href = $link->attr('href');
                if (! is_string($href)) {
                    return;
                }
                $product = $this->normalizeProduct($href, $baseUrl);
                if ($product !== null) {
                    $links[$product['url']] = $product;
                }
            });
            if ($links !== []) {
                break;
            }
        }

        return array_values($links);
    }

    /** @return array{url:string,external_product_id:string,default_combination_id:string|null,slug:string}|null */
    private function normalizeProduct(string $href, string $baseUrl): ?array
    {
        $url = $this->normalizeSiteUrl($href, $baseUrl);
        if ($url === null) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('#^/(\d+)(?:-(\d+))?-([^/]+)\.html$#u', $path, $m) !== 1) {
            return null;
        }
        return [
            'url' => 'https://'.self::CANONICAL_HOST.$path,
            'external_product_id' => $m[1],
            'default_combination_id' => isset($m[2]) && $m[2] !== '' ? $m[2] : null,
            'slug' => $m[3],
        ];
    }

    private function normalizeCategoryUrl(string $href): ?string
    {
        $url = $this->normalizeSiteUrl($href, 'https://'.self::CANONICAL_HOST.'/');
        if ($url === null) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/\d+-[^/]+/?$#u', $path) !== 1) {
            return null;
        }
        return 'https://'.self::CANONICAL_HOST.rtrim($path, '/');
    }

    private function withPage(string $url, int $page): string
    {
        return preg_replace('/\?.*$/', '', $url).'?page='.max(1, $page);
    }

    private function totalPages(Crawler $crawler): ?int
    {
        $text = $this->normalizeText($crawler->filter('body')->text(''));
        if (preg_match('/Pokazano\s+\d+\s*[-–]\s*\d+\s+z\s+(\d+)\s+pozycji/ui', $text, $m) === 1) {
            return max(1, (int) ceil(((int) $m[1]) / 12));
        }
        return null;
    }

    private function nextPageUrl(Crawler $crawler, string $baseUrl): ?string
    {
        foreach (['a[rel="next"]', '.pagination a.next', '.pagination-next a', 'a.next'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }
            $href = $node->attr('href');
            if (is_string($href)) {
                return $this->normalizeSiteUrl($href, $baseUrl);
            }
        }
        return null;
    }

    private function pageNumber(string $url): ?int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : null;
    }
}
