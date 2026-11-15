<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sanipur\SanipurCatalogueCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CrawlSanipurProductsCommand extends Command
{
    protected $signature = 'sanipur:crawl
        {--url=* : Product page URL(s) to read instead of every page in the sanipur.eu sitemap.}
        {--limit= : Read at most this many sitemap pages (for a quick check).}
        {--save=scrapers/sanipur/products.json : Where to save the product data under storage/app/private.}
        {--concurrency=4 : Parallel requests per batch (1-8).}
        {--request-delay-ms=250 : Pause before each batch of requests.}
        {--timeout=20 : HTTP timeout in seconds.}
        {--no-progress : Do not print each product.}
        {--show-failures : Print URLs that could not be read.}';

    protected $description = 'Read every sanipur.eu product page (name, SKU, price, description, images) into a JSON file. Read-only.';

    public function handle(SanipurCatalogueCrawler $crawler): int
    {
        $crawler
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withConcurrency(max(1, (int) $this->option('concurrency')));

        if (! (bool) $this->option('no-progress')) {
            $crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $urls = array_values(array_filter(array_map('strval', (array) $this->option('url'))));

        try {
            $urls = $urls !== [] ? $urls : $crawler->discoverUrls();
        } catch (Throwable $exception) {
            $this->error('Could not read the sanipur.eu sitemap: '.$exception->getMessage());

            return self::FAILURE;
        }

        $limit = $this->option('limit');

        if (is_string($limit) && (int) $limit > 0) {
            $urls = array_slice($urls, 0, (int) $limit);
        }

        $this->info('Pages to read: '.count($urls));

        $result = $crawler->crawl($urls);
        $products = $result['products'];

        $this->info('Product pages: '.count($products));
        $this->line('Product pages without a price (not imported): '.count($result['unpriced_urls']));
        $this->line('Non-product pages skipped: '.count($result['skipped_urls']));
        $this->line('Failed pages: '.count($result['failed_urls']));

        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] as $url => $reason) {
                $this->warn($url.' — '.$reason);
            }
        }

        $save = trim((string) $this->option('save'));

        if ($save !== '' && $products !== []) {
            Storage::disk('local')->put(ltrim($save, '/'), json_encode([
                'source' => 'sanipur',
                'crawled_at' => now()->toIso8601String(),
                'product_count' => count($products),
                'failed_urls' => $result['failed_urls'],
                'unpriced_urls' => $result['unpriced_urls'],
                'products' => $products,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            $this->info('Saved: '.Storage::disk('local')->path(ltrim($save, '/')));
        }

        return $products !== [] ? self::SUCCESS : self::FAILURE;
    }
}
