<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seni24\Seni24ProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class DiscoverSeni24ProductLinksCommand extends Command
{
    protected $signature = 'seni24:product-links
        {--url=* : Seni24 category page to scan. Defaults to the full Seni24 catalogue index.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Seni24 HTTP request.}
        {--max-category-pages=500 : Hard safety limit for recursive category traversal.}
        {--no-progress : Do not print progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app/private.}
        {--show-failures : Print failed Seni24 category URLs.}';

    protected $description = 'Discover Seni24 product URLs recursively from the approved public category trees.';

    public function __construct(
        private readonly Seni24ProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withMaxCategoryPages($this->maxCategoryPages());

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $urls = $this->option('url') ?: Seni24ProductUrlScraper::DEFAULT_URLS;
        $result = $this->scraper->scrape(array_values(array_map('strval', $urls)));

        if ($json) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ));
        } else {
            $this->info('Visited category pages: '.$result['visited_category_count']);
            $this->info('Discovered product URLs: '.$result['product_count']);
            $this->info('Failed category URLs: '.count($result['failed_urls']));
            $this->info('Stopped at category safety limit: '.($result['stopped_at_category_limit'] ? 'yes' : 'no'));

            foreach ($result['products'] as $product) {
                $this->line('- '.($product['name'] !== '' ? $product['name'] : $product['url']));
                $this->line('  '.$product['url']);
                $this->line('  Price: '.($product['price_gross_amount'] ?? 'not found').' PLN');
            }
        }

        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] as $url => $reason) {
                $this->warn($url.' - '.$reason);
            }
        }

        $save = $this->option('save');

        if (is_string($save) && trim($save) !== '') {
            Storage::disk('local')->put(
                ltrim($save, '/'),
                json_encode(
                    $result,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                )
            );
        }

        return $result['product_count'] > 0 && ! $result['stopped_at_category_limit']
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');

        return is_string($value) && (int) $value > 0 ? (int) $value : 15;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        return is_string($value) ? max(0, (int) $value) : 500;
    }

    private function maxCategoryPages(): int
    {
        $value = $this->option('max-category-pages');

        return is_string($value) && (int) $value > 0 ? (int) $value : 500;
    }
}
