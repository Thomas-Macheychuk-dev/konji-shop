<?php

declare(strict_types=1);

namespace App\Services\Corradomed;

use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Walks corradomed.pl from its section pages (uchwyty, krzesla, …) through every "?do=" page they link to
 * and keeps the pages that show a product table. The site is small (about 130 pages) and has no shop.
 */
final class CorradomedCatalogueCrawler
{
    private const MAX_PAGES = 400;

    private ?Closure $progressCallback = null;

    private int $timeoutSeconds = 20;

    private int $requestDelayMilliseconds = 250;

    private int $concurrency = 4;

    public function __construct(
        private readonly CorradomedPageScraper $scraper,
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
     * @param  list<string>|null  $startPages  page keys; null walks the whole site
     * @return array{products: list<array<string, mixed>>, visited: list<string>, failed: array<string, string>}
     */
    public function crawl(?array $startPages = null, bool $followLinks = true): array
    {
        $queue = $startPages ?? CorradomedPageScraper::SECTIONS;
        $seen = array_fill_keys($queue, true);
        $products = [];
        $visited = [];
        $failed = [];

        while ($queue !== [] && count($visited) < self::MAX_PAGES) {
            $batch = array_splice($queue, 0, $this->concurrency);

            if ($this->requestDelayMilliseconds > 0) {
                usleep($this->requestDelayMilliseconds * 1000);
            }

            $responses = $this->fetchBatch($batch);

            foreach ($batch as $page) {
                $visited[] = $page;
                $response = $responses[$page] ?? null;

                if (! $response instanceof Response) {
                    $failed[$page] = $response instanceof Throwable ? $response->getMessage() : 'no response';

                    continue;
                }

                if (! $response->successful()) {
                    $failed[$page] = 'HTTP '.$response->status();

                    continue;
                }

                $parsed = $this->scraper->parse($response->body(), $page);

                if ($followLinks) {
                    foreach ($parsed['links'] as $link) {
                        if (! isset($seen[$link])) {
                            $seen[$link] = true;
                            $queue[] = $link;
                        }
                    }
                }

                if ($parsed['product'] !== null) {
                    $products[$page] = $parsed['product'];
                    $this->emit(sprintf('%s — %s (%d codes)', $page, $parsed['product']['title'] ?? '?', count($parsed['product']['codes'])));
                }
            }
        }

        ksort($products, SORT_NATURAL);

        return ['products' => array_values($products), 'visited' => $visited, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, Response|Throwable>
     */
    private function fetchBatch(array $batch): array
    {
        try {
            return Http::pool(fn (Pool $pool): array => array_map(
                fn (string $page) => $pool->as($page)
                    ->connectTimeout(min(5, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'pl-PL,pl;q=0.9',
                        'User-Agent' => 'KonjiShopCatalogCrawler/1.0 (+https://ortezka.pl)',
                    ])
                    ->get('https://'.CorradomedPageScraper::HOST.'/'.$page),
                $batch,
            ));
        } catch (Throwable $exception) {
            return array_fill_keys($batch, $exception);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
