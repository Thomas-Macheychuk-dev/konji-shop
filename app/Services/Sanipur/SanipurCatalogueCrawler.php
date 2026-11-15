<?php

declare(strict_types=1);

namespace App\Services\Sanipur;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads every Polish page listed in the sanipur.eu sitemap and keeps the product pages.
 *
 * nopCommerce's sitemap mixes categories, topics and products under the same /pl/<slug> URL shape, so
 * product pages are recognised by their markup (SanipurProductScraper returns null for the rest).
 */
final class SanipurCatalogueCrawler
{
    public const SITEMAP_URL = 'https://sanipur.eu/sitemap.xml';

    /**
     * Sitemap paths that are never products. Skipping them saves requests; anything missed here is still
     * rejected by the product parser.
     */
    private const NON_PRODUCT_PATHS = [
        'search', 'contactus', 'contactus-2', 'news', 'blog', 'cart', 'wishlist', 'login', 'register',
        'compareproducts', 'recentlyviewedproducts', 'newproducts', 'manufacturer/all', 'sitemap',
        'customer/info', 'o-nas', 'certyfikaty', 'katalog', 'regulamin_sanipur', 'dostawa_sanipur',
        'polityka-prywatności',
    ];

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 250;

    private int $concurrency = 4;

    public function __construct(
        private readonly SanipurProductScraper $scraper,
    ) {}

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

    public function withConcurrency(int $concurrency): self
    {
        $this->concurrency = max(1, min(8, $concurrency));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function discoverUrls(string $sitemapUrl = self::SITEMAP_URL): array
    {
        $this->emit('Fetching sitemap: '.$sitemapUrl);

        $response = $this->client()->get($sitemapUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('Sanipur sitemap returned HTTP '.$response->status());
        }

        return $this->productCandidateUrls($response->body());
    }

    /**
     * @return list<string>
     */
    public function productCandidateUrls(string $sitemapXml): array
    {
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $sitemapXml, $matches);

        $urls = [];

        foreach ($matches[1] as $loc) {
            $url = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $parts = parse_url($url);
            $host = preg_replace('/^www\./', '', mb_strtolower((string) ($parts['host'] ?? '')));
            $path = rawurldecode(trim((string) ($parts['path'] ?? ''), '/'));

            if ($host !== SanipurProductScraper::HOST || ! str_starts_with(mb_strtolower($path), 'pl/')) {
                continue;
            }

            $slug = mb_substr($path, 3);

            if ($slug === '' || in_array(mb_strtolower($slug), self::NON_PRODUCT_PATHS, true)) {
                continue;
            }

            $urls['https://'.SanipurProductScraper::HOST.'/pl/'.$this->encodePath($slug)] = true;
        }

        return array_keys($urls);
    }

    /**
     * @param  list<string>  $urls
     * @return array{products: list<array<string, mixed>>, unpriced_urls: list<string>, skipped_urls: list<string>, failed_urls: array<string, string>}
     */
    public function crawl(array $urls): array
    {
        $products = [];
        $unpriced = [];
        $skipped = [];
        $failed = [];
        $total = count($urls);
        $done = 0;

        foreach (array_chunk($urls, $this->concurrency) as $batch) {
            if ($this->requestDelayMilliseconds > 0) {
                usleep($this->requestDelayMilliseconds * 1000);
            }

            $responses = $this->fetchBatch($batch);

            foreach ($batch as $url) {
                $done++;
                $response = $responses[$url] ?? null;

                if (! $response instanceof Response) {
                    $failed[$url] = $response instanceof Throwable ? $response->getMessage() : 'no response';

                    continue;
                }

                if (! $response->successful()) {
                    $failed[$url] = 'HTTP '.$response->status();

                    continue;
                }

                $product = $this->scraper->parse($response->body(), $url);

                if ($product === null) {
                    $skipped[] = $url;

                    continue;
                }

                if ($product['price_gross_amount'] === null) {
                    $unpriced[] = $url;

                    continue;
                }

                $products[(string) $product['external_product_id']] = $product;
                $this->emit(sprintf('[%d/%d] %s — %s zł', $done, $total, $product['name'] ?? $url, $product['price_gross_amount']));
            }
        }

        return [
            'products' => array_values($products),
            'unpriced_urls' => $unpriced,
            'skipped_urls' => $skipped,
            'failed_urls' => $failed,
        ];
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, Response|Throwable>
     */
    private function fetchBatch(array $batch): array
    {
        try {
            $results = Http::pool(function (Pool $pool) use ($batch): array {
                return array_map(
                    fn (string $url) => $pool->as($url)
                        ->connectTimeout(min(5, $this->timeoutSeconds))
                        ->timeout($this->timeoutSeconds)
                        ->withHeaders($this->headers())
                        ->get($url),
                    $batch,
                );
            });
        } catch (Throwable $exception) {
            return array_fill_keys($batch, $exception);
        }

        return $results;
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout(min(5, $this->timeoutSeconds))
            ->timeout($this->timeoutSeconds)
            ->withHeaders($this->headers());
    }

    private function encodePath(string $slug): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $slug)));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9',
            'User-Agent' => 'KonjiShopCatalogCrawler/1.0 (+https://ortezka.pl)',
        ];
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
