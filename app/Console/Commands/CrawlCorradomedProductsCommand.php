<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Corradomed\CorradomedCatalogueCrawler;
use App\Services\Corradomed\CorradomedPageScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CrawlCorradomedProductsCommand extends Command
{
    protected $signature = 'corradomed:crawl
        {--page=* : Page key(s) to read without following links, e.g. "uchwyty?do=proste01".}
        {--save=scrapers/corradomed/products.json : Where to save the page data under storage/app/private.}
        {--json : Print the page data as JSON instead of saving it.}
        {--concurrency=4 : Parallel requests per batch (1-8).}
        {--request-delay-ms=250 : Pause before each batch of requests.}
        {--timeout=20 : HTTP timeout in seconds.}
        {--no-progress : Do not print each page.}';

    protected $description = 'Read the corradomed.pl product pages (names, model codes, dimensions, images) into a JSON file. Read-only; prices come from the Corradomed price list.';

    public function handle(CorradomedCatalogueCrawler $crawler): int
    {
        $crawler
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withConcurrency(max(1, (int) $this->option('concurrency')));

        $json = (bool) $this->option('json');

        if (! $json && ! (bool) $this->option('no-progress')) {
            $crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $pages = array_values(array_filter(array_map(
            fn ($page): ?string => CorradomedPageScraper::pageKey('https://'.CorradomedPageScraper::HOST.'/'.ltrim((string) $page, '/')),
            (array) $this->option('page'),
        )));

        $result = $crawler->crawl($pages === [] ? null : $pages, followLinks: $pages === []);
        $payload = [
            'source' => 'corradomed',
            'crawled_at' => now()->toIso8601String(),
            'page_count' => count($result['products']),
            'failed' => $result['failed'],
            'products' => $result['products'],
        ];

        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $result['products'] !== [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Pages visited: '.count($result['visited']));
        $this->info('Product pages: '.count($result['products']));
        $this->line('Failed pages: '.count($result['failed']));

        foreach ($result['failed'] as $page => $reason) {
            $this->warn($page.' — '.$reason);
        }

        $save = trim((string) $this->option('save'));

        if ($save !== '' && $result['products'] !== []) {
            Storage::disk('local')->put(ltrim($save, '/'), (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info('Saved: '.Storage::disk('local')->path(ltrim($save, '/')));
        }

        return $result['products'] !== [] ? self::SUCCESS : self::FAILURE;
    }
}
