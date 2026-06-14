<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use Closure;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class PerukaCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://www.peruka.pl/';

    private const PERUKA_HOST = 'www.peruka.pl';

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 15;

    private int $requestDelayMilliseconds = 0;

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
     * Convenience wrapper for callers/tests that only need category URLs.
     *
     * @param  array<int, string>  $startUrls
     * @return array<int, string>
     */
    public function discover(array $startUrls = [self::DEFAULT_URL]): array
    {
        return $this->scrape($startUrls)['category_urls'];
    }

    /**
     * Discover Peruka category URLs from the visible shop navigation.
     *
     * @param  array<int, string>  $startUrls
     * @return array{
     *     category_urls: array<int, string>,
     *     categories: array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null, level: int}>,
     *     visited_urls: array<int, string>,
     *     failed_urls: array<string, string>,
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $categories = [];
        $visited = [];
        $failed = [];

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isPerukaUrl($url)) {
                continue;
            }

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Peruka category navigation page: '.$url);

            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractCategories($html, $url) as $category) {
                $categories[$category['url']] = $category;
            }
        }

        return [
            'category_urls' => array_keys($categories),
            'categories' => array_values($categories),
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
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

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds <= 0) {
            return;
        }

        usleep($this->requestDelayMilliseconds * 1000);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($message);
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

    /**
     * @return array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null, level: int}>
     */
    private function extractCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
            $nav = $crawler->filter('ul.horizontal-categories')->first();

            if ($nav->count() > 0) {
                return $this->extractCategoriesFromNavigation($nav, $baseUrl);
            }

            return $this->extractCategoryLinksFallback($crawler, $baseUrl);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null, level: int}>
     */
    private function extractCategoriesFromNavigation(Crawler $nav, string $baseUrl): array
    {
        $categories = [];
        $navElement = $nav->getNode(0);

        if (! $navElement instanceof DOMElement) {
            return [];
        }

        foreach ($this->directChildElements($navElement, 'li') as $topListItem) {
            $topAnchor = $this->firstDirectCategoryAnchor($topListItem);

            if ($topAnchor === null) {
                continue;
            }

            $topCategory = $this->categoryFromAnchor($topAnchor, $baseUrl, null, null, 0);

            if ($topCategory === null) {
                continue;
            }

            $categories[$topCategory['url']] = $topCategory;

            foreach ($this->directChildElements($topListItem, 'ul') as $menu) {
                if (! $this->elementHasClass($menu, 'dropdown-menu')) {
                    continue;
                }

                foreach ($this->directChildElements($menu, 'li') as $childListItem) {
                    $childAnchor = $this->firstDirectCategoryAnchor($childListItem);

                    if ($childAnchor === null) {
                        continue;
                    }

                    $childCategory = $this->categoryFromAnchor(
                        anchor: $childAnchor,
                        baseUrl: $baseUrl,
                        parentName: $topCategory['name'],
                        parentUrl: $topCategory['url'],
                        level: 1,
                    );

                    if ($childCategory === null) {
                        continue;
                    }

                    $categories[$childCategory['url']] = $childCategory;
                }
            }
        }

        return array_values($categories);
    }

    /**
     * @return array<int, array{name: string, url: string, parent_name: string|null, parent_url: string|null, level: int}>
     */
    private function extractCategoryLinksFallback(Crawler $crawler, string $baseUrl): array
    {
        $categories = [];

        $crawler->filter('a.category-link[href], a[href^="/category/"]')->each(function (Crawler $node) use (&$categories, $baseUrl): void {
            $href = $node->attr('href');

            if (! is_string($href)) {
                return;
            }

            $url = $this->normalizeCategoryUrl($href, $baseUrl);

            if ($url === null) {
                return;
            }

            $name = $this->normalizeLabel($node->text(''));

            if ($name === '') {
                $name = $this->labelFromUrl($url);
            }

            if ($name === '') {
                return;
            }

            $categories[$url] = [
                'name' => $name,
                'url' => $url,
                'parent_name' => null,
                'parent_url' => null,
                'level' => 0,
            ];
        });

        return array_values($categories);
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directChildElements(DOMElement $element, ?string $tagName = null): array
    {
        $children = [];
        $expectedTagName = $tagName === null ? null : mb_strtolower($tagName);

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($expectedTagName !== null && mb_strtolower($child->tagName) !== $expectedTagName) {
                continue;
            }

            $children[] = $child;
        }

        return $children;
    }

    private function firstDirectCategoryAnchor(DOMElement $element): ?DOMElement
    {
        foreach ($this->directChildElements($element, 'a') as $anchor) {
            if (! $anchor->hasAttribute('href') || ! $this->elementHasClass($anchor, 'category-link')) {
                continue;
            }

            return $anchor;
        }

        return null;
    }

    /**
     * @return array{name: string, url: string, parent_name: string|null, parent_url: string|null, level: int}|null
     */
    private function categoryFromAnchor(DOMElement $anchor, string $baseUrl, ?string $parentName, ?string $parentUrl, int $level): ?array
    {
        $href = $anchor->getAttribute('href');
        $url = $this->normalizeCategoryUrl($href, $baseUrl);

        if ($url === null) {
            return null;
        }

        $name = $this->normalizeLabel($anchor->textContent ?? '');

        if ($name === '') {
            $name = $this->labelFromUrl($url);
        }

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'url' => $url,
            'parent_name' => $parentName,
            'parent_url' => $parentUrl,
            'level' => $level,
        ];
    }

    private function elementHasClass(DOMElement $element, string $className): bool
    {
        $classes = preg_split('/\s+/', (string) $element->getAttribute('class')) ?: [];

        return in_array($className, $classes, true);
    }

    private function normalizeCategoryUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = $this->normalizeUrl($url, $baseUrl);

        if ($url === null || ! $this->isPerukaUrl($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $this->normalizePath($path);

        if (preg_match('#^/category/[a-z0-9][a-z0-9\-]*$#', $path) !== 1) {
            return null;
        }

        return 'https://'.self::PERUKA_HOST.$path;
    }

    private function normalizeUrl(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = str_replace(['\\/', '\/'], '/', $url);

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
            $url = 'https://'.self::PERUKA_HOST.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($baseUrl === null) {
                return null;
            }

            $base = parse_url($baseUrl);

            if (! isset($base['scheme'], $base['host'])) {
                return null;
            }

            $basePath = $base['path'] ?? '/';
            $directory = str_ends_with($basePath, '/')
                ? rtrim($basePath, '/')
                : rtrim(dirname($basePath), '/');

            $url = $base['scheme'].'://'.$base['host'].$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        return 'https://'.$host.$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?: $label;

        return trim($label);
    }

    private function labelFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = (string) end($segments);

        if ($slug === '') {
            return '';
        }

        return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
    }

    private function isPerukaUrl(string $url): bool
    {
        return $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)) === self::PERUKA_HOST;
    }

    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        return match ($host) {
            'peruka.pl', self::PERUKA_HOST => self::PERUKA_HOST,
            default => null,
        };
    }
}
