<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class CrawlQmedProductDataCommand extends Command
{
    protected $signature = 'qmed:crawl-product-data
        {--from=scrapers/qmed/product-links.json : Discovery JSON on the private local storage disk}
        {--save=scrapers/qmed/full-product-data.json : Output JSON on the private local storage disk}
        {--limit=5 : Number of products to crawl; use 0 for all}
        {--offset=0 : Number of products to skip}
        {--timeout=20 : HTTP timeout in seconds}
        {--request-delay-ms=500 : Delay between requests}
        {--show-failures : Print failed URLs}';

    protected $description = 'Scrape Qmed product pages to structured JSON without database writes.';

    public function __construct(private readonly QmedProductDataCrawler $crawler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $save = (string) $this->option('save');
        if (! $this->safePath($from) || ! $this->safePath($save)) {
            $this->error('Invalid storage path.');

            return self::FAILURE;
        }
        $storage = Storage::disk('local');
        if (! $storage->exists($from)) {
            $this->error('Discovery file missing: '.$from);

            return self::FAILURE;
        }

        try {
            $discovery = json_decode($storage->get($from), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($discovery)) {
                throw new JsonException('Discovery JSON must be an object.');
            }
            $limit = max(0, (int) $this->option('limit'));
            $result = $this->crawler
                ->withTimeout(max(1, (int) $this->option('timeout')))
                ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
                ->crawl($discovery, $limit === 0 ? null : $limit, max(0, (int) $this->option('offset')));
            $storage->put($save, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Selected URLs: '.$result['source_product_url_count']);
        $this->info('Scraped products: '.$result['product_count']);
        $this->info('Failed URLs: '.count($result['failed_urls']));
        $this->info('Saved: '.$save);
        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] as $url => $reason) {
                $this->warn($url.' : '.$reason);
            }
        }

        return $result['failed_urls'] === [] && $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/')
            && ! in_array('..', explode('/', $path), true)
            && ! str_contains($path, "\0");
    }
}
