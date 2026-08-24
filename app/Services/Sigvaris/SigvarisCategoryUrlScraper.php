<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use DOMElement;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;

final class SigvarisCategoryUrlScraper extends SigvarisHttpClient
{
    public const DEFAULT_URL = 'https://www.sklep-sigvaris.com/';

    /** @return array<string, mixed> */
    public function scrape(string $startUrl = self::DEFAULT_URL): array
    {
        $startUrl = $this->normalizeSiteUrl($startUrl, self::DEFAULT_URL) ?? self::DEFAULT_URL;
        $failedUrls = [];
        $html = $this->fetchBody($startUrl, $failedUrls);

        if ($html === null) {
            return $this->emptyResult($startUrl, $failedUrls);
        }

        $crawler = new Crawler($html, $startUrl);
        $categoriesByUrl = [];
        $order = 0;

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$categoriesByUrl, &$order, $startUrl): void {
            $node = $link->getNode(0);
            if (! $node instanceof DOMElement) {
                return;
            }

            $url = $this->normalizeCategoryUrl($node->getAttribute('href'), $startUrl);
            if ($url === null) {
                return;
            }

            $identity = $this->categoryIdentity($url);
            if ($identity === null) {
                return;
            }

            $name = $this->normalizeText($link->text(''));
            if ($name === '') {
                $name = $this->humanizeSlug($identity['slug']);
            }

            $path = $this->pathFromMenuAncestors($node, $url, $name);
            $existing = $categoriesByUrl[$url] ?? null;

            if (! is_array($existing) || count($path) > count($existing['path'] ?? [])) {
                $categoriesByUrl[$url] = [
                    'source' => 'sigvaris',
                    'external_category_id' => $identity['id'],
                    'slug' => $identity['slug'],
                    'name' => $name,
                    'url' => $url,
                    'path' => $path,
                    'level' => count($path),
                    'parent_external_category_id' => null,
                    'top_category_external_id' => null,
                    'top_category_name' => $path[0] ?? $name,
                    'product_count' => null,
                    '_order' => $existing['_order'] ?? $order++,
                ];
            }
        });

        $categories = array_values($categoriesByUrl);
        usort($categories, static fn (array $a, array $b): int => ((int) $a['_order']) <=> ((int) $b['_order']));

        $pathToId = [];
        foreach ($categories as $category) {
            $pathToId[implode(' > ', $category['path'])] = $category['external_category_id'];
        }

        foreach ($categories as &$category) {
            $path = $category['path'];
            if (count($path) > 1) {
                $parentPath = implode(' > ', array_slice($path, 0, -1));
                $category['parent_external_category_id'] = $pathToId[$parentPath] ?? null;
            }
            $topName = $path[0] ?? $category['name'];
            foreach ($categories as $candidate) {
                if (($candidate['name'] ?? null) === $topName && count($candidate['path'] ?? []) === 1) {
                    $category['top_category_external_id'] = $candidate['external_category_id'];
                    break;
                }
            }
            unset($category['_order']);
        }
        unset($category);

        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => count($category['path'] ?? []) === 1,
        ));

        return [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'start_urls' => [$startUrl],
            'top_categories' => $topCategories,
            'categories' => $categories,
            'category_urls' => array_values(array_map(static fn (array $c): string => $c['url'], $categories)),
            'visited_urls' => [$startUrl],
            'failed_urls' => $failedUrls,
        ];
    }

    /** @param array<string, string> $failedUrls */
    private function emptyResult(string $startUrl, array $failedUrls): array
    {
        return [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'start_urls' => [$startUrl],
            'top_categories' => [],
            'categories' => [],
            'category_urls' => [],
            'visited_urls' => [$startUrl],
            'failed_urls' => $failedUrls,
        ];
    }

    private function normalizeCategoryUrl(string $href, string $baseUrl): ?string
    {
        $url = $this->normalizeSiteUrl($href, $baseUrl);
        if ($url === null) {
            return null;
        }

        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (str_ends_with(mb_strtolower($path), '.html')) {
            return null;
        }
        if (preg_match('#^/(\d+)-([^/]+?)/?$#u', $path) !== 1) {
            return null;
        }

        return 'https://'.self::CANONICAL_HOST.rtrim($path, '/');
    }

    /** @return array{id:string,slug:string}|null */
    private function categoryIdentity(string $url): ?array
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (str_ends_with(mb_strtolower($path), '.html')) {
            return null;
        }
        if (preg_match('#^/(\d+)-([^/]+?)/?$#u', $path, $m) !== 1) {
            return null;
        }
        return ['id' => $m[1], 'slug' => trim($m[2], '/')];
    }

    /** @return array<int, string> */
    private function pathFromMenuAncestors(DOMElement $anchor, string $url, string $fallbackName): array
    {
        $names = [];
        $node = $anchor->parentNode;

        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'li') {
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'a') {
                        continue;
                    }
                    $href = $child->getAttribute('href');
                    $childUrl = $this->normalizeCategoryUrl($href, $url);
                    if ($childUrl === null) {
                        continue;
                    }
                    $name = $this->normalizeText($child->textContent ?? '');
                    if ($name !== '') {
                        array_unshift($names, $name);
                    }
                    break;
                }
            }
            $node = $node->parentNode;
        }

        $names = array_values(array_unique($names));
        if ($names === []) {
            return [$fallbackName];
        }
        if (end($names) !== $fallbackName) {
            $names[] = $fallbackName;
        }
        return $names;
    }

    private function humanizeSlug(string $slug): string
    {
        return mb_convert_case($this->normalizeText(str_replace('-', ' ', rawurldecode($slug))), MB_CASE_TITLE, 'UTF-8');
    }
}
