<?php

declare(strict_types=1);

namespace App\Services\Vermeiren;

use Closure;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class VermeirenCategoryUrlScraper
{
    public const DEFAULT_URL = 'https://www.vermeiren.pl/web/web.nsf/home.xsp?CountryPLPLProductGroup';

    private const BASE_URL = 'https://www.vermeiren.pl/web/web.nsf/';

    private const VERMEIREN_HOST = 'www.vermeiren.pl';

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
     * Discover the category hierarchy exposed by the Polish "Produkty" menu.
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
     *     failed_urls: array<string, string>
     * }
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $normalizedStartUrls = [];
        $visited = [];
        $failed = [];
        $categoriesByExternalId = [];
        $discoveryOrder = 0;

        foreach ($startUrls as $startUrl) {
            $url = $this->normalizeUrl($startUrl, self::DEFAULT_URL);

            if ($url === null || ! $this->isVermeirenUrl($url)) {
                continue;
            }

            $normalizedStartUrls[] = $url;

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching Vermeiren product navigation page: '.$url);
            $html = $this->fetchBody($url, $failed);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractProductMenuCategories($html, $url) as $category) {
                $externalId = (string) $category['external_category_id'];

                if (isset($categoriesByExternalId[$externalId])) {
                    continue;
                }

                $category['discovery_order'] = $discoveryOrder++;
                $categoriesByExternalId[$externalId] = $category;
            }
        }

        $categories = array_values($categoriesByExternalId);

        usort(
            $categories,
            static fn (array $left, array $right): int => ((int) $left['discovery_order']) <=> ((int) $right['discovery_order'])
        );

        foreach ($categories as &$category) {
            unset($category['discovery_order']);
        }
        unset($category);

        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => ((int) $category['level']) === 1
        ));
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories
        ));
        $productCategoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            array_filter(
                $categories,
                static fn (array $category): bool => (bool) $category['is_product_category']
            )
        ));

        return [
            'source' => 'vermeiren',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $productCategoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
        ];
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
                $request = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($this->headers());

                if (! $this->verifyTls) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->emitRetry($url, $attempt, $lastFailure);
                    $this->pauseBeforeRetry();

                    continue;
                }

                break;
            }

            if ($response->successful()) {
                return $response->body();
            }

            $lastFailure = 'HTTP '.$response->status();

            if ($attempt < $this->attempts && ($response->status() === 429 || $response->serverError())) {
                $this->emitRetry($url, $attempt, $lastFailure);
                $this->pauseBeforeRetry();

                continue;
            }

            break;
        }

        $failed[$url] = $lastFailure;

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractProductMenuCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $productsMenu = $this->findProductsMenu($crawler);

        if (! $productsMenu instanceof DOMElement) {
            return [];
        }

        $categories = [];

        foreach ($this->directChildElements($productsMenu, 'li') as $topListItem) {
            $topAnchor = $this->directChildElement($topListItem, 'a');

            if (! $topAnchor instanceof DOMElement) {
                continue;
            }

            $submenu = $this->directChildElement($topListItem, 'ul');
            $references = [];

            if ($submenu instanceof DOMElement) {
                $submenuCrawler = new Crawler($submenu, $baseUrl);

                $submenuCrawler->filter('a[href]')->each(function (Crawler $anchor) use (&$references, $baseUrl): void {
                    $reference = $this->parseCategoryReference((string) $anchor->attr('href'), $baseUrl);

                    if ($reference !== null) {
                        $references[] = $reference;
                    }
                });
            } else {
                $reference = $this->parseCategoryReference((string) $topAnchor->getAttribute('href'), $baseUrl);

                if ($reference !== null) {
                    $references[] = $reference;
                }
            }

            if ($references === []) {
                continue;
            }

            $rootReference = null;

            foreach ($references as $reference) {
                if ((string) $reference['sub_group'] === '') {
                    $rootReference = $reference;
                    break;
                }
            }

            if (! is_array($rootReference)) {
                continue;
            }

            $group = (string) $rootReference['product_group'];
            $rootExternalId = $this->externalCategoryId($group);
            $children = array_values(array_filter(
                $references,
                static fn (array $reference): bool => (string) $reference['sub_group'] !== ''
            ));

            $categories[] = [
                'external_category_id' => $rootExternalId,
                'source_key' => (string) $rootReference['source_key'],
                'name' => $group,
                'url' => (string) $rootReference['url'],
                'page_type' => (string) $rootReference['page_type'],
                'product_group' => $group,
                'sub_group' => null,
                'level' => 1,
                'parent_external_category_id' => null,
                'top_category_external_id' => $rootExternalId,
                'top_category_name' => $group,
                'path' => [$group],
                'has_children' => $children !== [],
                'is_product_category' => $children === [],
            ];

            $seenChildren = [];

            foreach ($children as $reference) {
                $subGroup = (string) $reference['sub_group'];
                $externalId = $this->externalCategoryId($group, $subGroup);

                if (isset($seenChildren[$externalId])) {
                    continue;
                }

                $seenChildren[$externalId] = true;
                $categories[] = [
                    'external_category_id' => $externalId,
                    'source_key' => (string) $reference['source_key'],
                    'name' => $subGroup,
                    'url' => (string) $reference['url'],
                    'page_type' => (string) $reference['page_type'],
                    'product_group' => $group,
                    'sub_group' => $subGroup,
                    'level' => 2,
                    'parent_external_category_id' => $rootExternalId,
                    'top_category_external_id' => $rootExternalId,
                    'top_category_name' => $group,
                    'path' => [$group, $subGroup],
                    'has_children' => false,
                    'is_product_category' => true,
                ];
            }
        }

        return $categories;
    }

    private function findProductsMenu(Crawler $crawler): ?DOMElement
    {
        $menu = null;

        $crawler->filter('a')->each(function (Crawler $anchor) use (&$menu): void {
            if ($menu instanceof DOMElement || $this->normalizeText($anchor->text('')) !== 'Produkty') {
                return;
            }

            $anchorNode = $anchor->getNode(0);

            if (! $anchorNode instanceof DOMElement) {
                return;
            }

            $listItem = $this->closestElement($anchorNode, 'li');

            if (! $listItem instanceof DOMElement) {
                return;
            }

            $candidate = $this->directChildElement($listItem, 'ul');

            if ($candidate instanceof DOMElement) {
                $menu = $candidate;
            }
        });

        return $menu;
    }

    /**
     * @return array{url: string, source_key: string, product_group: string, sub_group: string, page_type: string}|null
     */
    private function parseCategoryReference(string $href, string $baseUrl): ?array
    {
        $url = $this->normalizeUrl($href, $baseUrl);

        if ($url === null || ! $this->isVermeirenUrl($url)) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        $decodedQuery = rawurldecode($query);

        if (preg_match('/ProductGroup(?<group>.*?)SubGroup(?<subgroup>.*)$/u', $decodedQuery, $matches) !== 1) {
            return null;
        }

        $group = $this->normalizeText((string) $matches['group']);
        $subGroup = $this->normalizeText((string) $matches['subgroup']);

        if ($group === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $filename = is_string($path) ? pathinfo($path, PATHINFO_FILENAME) : '';

        if (! in_array($filename, ['mainproduct', 'mainproduct_categories', 'mainproduct_sub'], true)) {
            return null;
        }

        return [
            'url' => $url,
            'source_key' => 'ProductGroup'.$group.'SubGroup'.$subGroup,
            'product_group' => $group,
            'sub_group' => $subGroup,
            'page_type' => $filename,
        ];
    }

    private function externalCategoryId(string $productGroup, ?string $subGroup = null): string
    {
        $id = 'product-group:'.$productGroup;

        if (is_string($subGroup) && $subGroup !== '') {
            $id .= '|sub-group:'.$subGroup;
        }

        return $id;
    }

    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '' || $url === '#') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://'.self::VERMEIREN_HOST.$url;
        } elseif (parse_url($url, PHP_URL_SCHEME) === null) {
            $basePath = parse_url($baseUrl, PHP_URL_PATH);
            $directory = is_string($basePath) ? rtrim(str_replace('\\', '/', dirname($basePath)), '/') : '/web/web.nsf';
            $url = 'https://'.self::VERMEIREN_HOST.$directory.'/'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['vermeiren.pl', self::VERMEIREN_HOST], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $encodedPath = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path)
        ));
        $query = $parts['query'] ?? null;
        $normalized = 'https://'.self::VERMEIREN_HOST.($encodedPath === '' ? '/' : $encodedPath);

        if (is_string($query) && $query !== '') {
            $normalized .= '?'.rawurlencode(rawurldecode($query));
        }

        return $normalized;
    }

    private function isVermeirenUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === self::VERMEIREN_HOST;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function closestElement(DOMNode $node, string $tagName): ?DOMElement
    {
        $current = $node->parentNode;

        while ($current instanceof DOMNode) {
            if ($current instanceof DOMElement && strtolower($current->tagName) === strtolower($tagName)) {
                return $current;
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private function directChildElement(DOMElement $parent, string $tagName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === strtolower($tagName)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directChildElements(DOMElement $parent, string $tagName): array
    {
        $elements = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === strtolower($tagName)) {
                $elements[] = $child;
            }
        }

        return $elements;
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
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopVermeirenCategoryDiscovery/1.0)',
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

    private function emitRetry(string $url, int $attempt, string $reason): void
    {
        $this->emit(sprintf(
            'Retrying Vermeiren URL after attempt %d/%d (%s): %s',
            $attempt,
            $this->attempts,
            $reason,
            $url,
        ));
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback instanceof Closure) {
            ($this->progressCallback)($message);
        }
    }
}
