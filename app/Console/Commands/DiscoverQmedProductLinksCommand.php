<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class DiscoverQmedProductLinksCommand extends Command
{
    protected $signature = 'qmed:product-links
        {--save=scrapers/qmed/product-links.json : JSON output path on the local private storage disk.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Delay before each request.}
        {--max-pages=150 : Maximum catalogue page/category memberships to visit.}
        {--show-failures : Print failed URLs.}';

    protected $description = 'Discover Qmed manufacturer product URLs without importing products.';

    public function __construct(
        private readonly QmedProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->scraper
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withMaxPages(max(1, (int) $this->option('max-pages')))
            ->withProgressCallback(fn (string $message) => $this->line($message));

        $result = $this->scraper->discover();

        $this->info('Categories: '.$result['category_count']);
        $this->info('Visited pages: '.$result['visited_category_pages']);
        $this->info('Unique products: '.$result['product_count']);
        $this->info('Failed URLs: '.count($result['failed_urls']));
        $this->info('Stopped at limit: '.($result['stopped_at_limit'] ? 'yes' : 'no'));

        if ($this->option('show-failures')) {
            foreach ($result['failed_urls'] as $url => $reason) {
                $this->warn($url.' — '.$reason);
            }
        }

        $path = ltrim((string) $this->option('save'), '/');

        if ($path === '' || str_contains($path, '..')) {
            $this->error('Invalid output path.');

            return self::FAILURE;
        }

        Storage::disk('local')->put(
            $path,
            json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );

        $this->info('Saved: '.$path);

        return $result['failed_urls'] === []
            && ! $result['stopped_at_limit']
            && $result['product_count'] > 0
                ? self::SUCCESS
                : self::FAILURE;
    }
}
