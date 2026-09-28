<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DrSapporo\DrSapporoProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class DiscoverDrSapporoProductLinksCommand extends Command
{
    protected $signature = 'drsapporo:product-links
        {--url=* : Dr Sapporo catalogue page to scan. Defaults to https://drsapporo.com/produkty.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Dr Sapporo HTTP request.}
        {--no-progress : Do not print progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app/private.}
        {--show-failures : Print failed Dr Sapporo URLs.}';

    protected $description = 'Discover Dr Sapporo product URLs from the public catalogue page.';

    public function __construct(
        private readonly DrSapporoProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $urls = $this->option('url') ?: [DrSapporoProductUrlScraper::DEFAULT_URL];
        $result = $this->scraper->scrape(array_values(array_map('strval', $urls)));

        if ($json) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ));
        } else {
            $this->info('Visited pages: '.count($result['visited_urls']));
            $this->info('Discovered product URLs: '.$result['product_count']);

            foreach ($result['products'] as $product) {
                $this->line('- '.$product['name']);
                $this->line('  '.$product['url']);
                $this->line('  Category: '.$product['category_name']);
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

        return $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
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
}
