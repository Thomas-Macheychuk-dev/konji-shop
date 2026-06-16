<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TwojaPeruka\TwojaPerukaProductScraper;
use Illuminate\Console\Command;

final class ScrapeTwojaPerukaProductCommand extends Command
{
    protected $signature = 'twojaperuka:product
        {url : TwojaPeruka product URL to scrape.}
        {--json : Print scraped product data as JSON.}
        {--save= : Save scraped product data as JSON under storage/app.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each TwojaPeruka HTTP request.}
        {--show-failures : Print failed TwojaPeruka URLs.}';

    protected $description = 'Scrape one TwojaPeruka product page into normalized product JSON.';

    public function __construct(
        private readonly TwojaPerukaProductScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
            $this->info('Scraping TwojaPeruka product page...');
        }

        $result = $this->scraper->scrape((string) $this->argument('url'));

        if ($json) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ));
        } else {
            $this->info('Scraped TwojaPeruka product: '.$result['name']);
            $this->line('External product ID: '.($result['external_product_id'] ?? 'not found'));
            $this->line('Price gross: '.($result['price_gross_amount'] ?? 'not found').' '.$result['currency']);
            $this->line('Availability: '.($result['availability_label'] ?? $result['availability']));
            $this->line('Images: '.count($result['images']));
            $this->line('Variant option groups: '.count($result['variant_options']));
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed TwojaPeruka URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return $result['name'] === '' || $result['failed_urls'] !== [] ? self::FAILURE : self::SUCCESS;
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');

        if (! is_string($value) || trim($value) === '') {
            return 15;
        }

        $timeout = (int) $value;

        return $timeout > 0 ? $timeout : 15;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        if (! is_string($value) || trim($value) === '') {
            return 500;
        }

        return max(0, (int) $value);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result, bool $quiet = false): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
        );

        if (! $quiet) {
            $this->info('Saved product data to storage/app/'.$relativePath);
        }
    }
}
