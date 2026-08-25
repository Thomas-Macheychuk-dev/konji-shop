<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Closure;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ArmedicalCategoryUrlScraper
{
    public const DEFAULT_URL = ArmedicalUrl::CATALOGUE_URL;

    private const TOP_CATEGORY_SLUGS = [
        'produkty-ortopedyczne',
        'produkty-rehabilitacyjne',
        'srodki-pomocnicze',
        'produkty-medyczne',
    ];

    private ?Closure $progressCallback = null;

    private int $maxPages = 100;

    public function __construct(
        private readonly ArmedicalHttpClient $http,
    ) {}

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->http->withTimeout($seconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->http->withMaxAttempts($attempts, $retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->http->withRequestDelayMilliseconds($milliseconds);

        return $this;
    }

    public function withMaxPages(int $pages): self
    {
        $this->maxPages = max(1, $pages);

        return $this;
    }

    /**
     * @param  array<int, string>  $startUrls
     * @return array<string, mixed>
     */
    public function scrape(array $startUrls = [self::DEFAULT_URL]): array
    {
        $queue = [];
        $queued = [];
        $visited = [];
        $failed = [];
        $normalizedStartUrls = [];
        $categoriesByUrl = [];
        $order = 0;
        $stoppedEarly = false;

        foreach ($startUrls as $startUrl) {
            $url = ArmedicalUrl::normalize($startUrl, self::DEFAULT_URL);

            if ($url === null) {
                continue;
            }

            $normalizedStartUrls[] = $url;
            $queue[] = $url;
            $queued[$url] = true;
        }

        while ($queue !== [] && count($visited) < $this->maxPages) {
            $url = array_shift($queue);

            if (! is_string($url) || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $this->emit('Fetching ARmedical category/navigation page: '.$url);
            $response = $this->http->fetch($url);

            if (! is_string($response['body'])) {
                $failed[$url] = (string) ($response['error'] ?? 'Unknown HTTP error');

                continue;
            }

            foreach ($this->extractCategories($response['body'], $url) as $candidate) {
                $categoryUrl = $candidate['url'];

                if (! isset($categoriesByUrl[$categoryUrl])) {
                    $categoriesByUrl[$categoryUrl] = [
                        'external_category_id' => $candidate['slug'],
                        'slug' => $candidate['slug'],
                        'name' => $candidate['name'],
                        'url' => $categoryUrl,
                        'level' => in_array($candidate['slug'], self::TOP_CATEGORY_SLUGS, true) ? 1 : 2,
                        'parent_external_category_id' => null,
                        'top_category_external_id' => in_array($candidate['slug'], self::TOP_CATEGORY_SLUGS, true) ? $candidate['slug'] : null,
                        'top_category_name' => in_array($candidate['slug'], self::TOP_CATEGORY_SLUGS, true) ? $candidate['name'] : null,
                        'path' => [$candidate['name']],
                        'has_children' => false,
                        'is_product_category' => true,
                        'discovery_order' => $order++,
                    ];
                }

                if (! isset($visited[$categoryUrl], $queued[$categoryUrl])) {
                    if (count($visited) + count($queue) < $this->maxPages) {
                        $queue[] = $categoryUrl;
                        $queued[$categoryUrl] = true;
                    } else {
                        $stoppedEarly = true;
                    }
                }
            }
        }

        $categories = array_values($categoriesByUrl);
        usort($categories, static function (array $left, array $right): int {
            $level = ((int) $left['level']) <=> ((int) $right['level']);

            if ($level !== 0) {
                return $level;
            }

            return ((int) $left['discovery_order']) <=> ((int) $right['discovery_order']);
        });

        $categories = array_map(static function (array $category): array {
            unset($category['discovery_order']);

            return $category;
        }, $categories);

        $topCategories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => (int) $category['level'] === 1,
        ));
        $categoryUrls = array_values(array_map(
            static fn (array $category): string => (string) $category['url'],
            $categories,
        ));

        return [
            'source' => 'armedical',
            'start_urls' => array_values(array_unique($normalizedStartUrls)),
            'top_categories' => $topCategories,
            'categories' => $categories,
            'category_urls' => $categoryUrls,
            'product_category_urls' => $categoryUrls,
            'visited_urls' => array_keys($visited),
            'failed_urls' => $failed,
            'stopped_early' => $stoppedEarly || $queue !== [],
            'stop_reason' => $stoppedEarly || $queue !== [] ? 'maximum category page limit reached' : null,
        ];
    }

    /**
     * @return array<int, array{url: string, slug: string, name: string}>
     */
    private function extractCategories(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (Throwable) {
            return [];
        }

        $categories = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$categories, $baseUrl): void {
            $url = ArmedicalUrl::categoryCanonical((string) $node->attr('href'), $baseUrl);

            if ($url === null) {
                return;
            }

            $slug = ArmedicalUrl::categorySlug($url);
            $name = $this->normalizeText((string) $node->text(''));

            if ($slug === null || $name === '' || mb_strlen($name) > 120) {
                return;
            }

            $categories[$url] = [
                'url' => $url,
                'slug' => $slug,
                'name' => $name,
            ];
        });

        return array_values($categories);
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
