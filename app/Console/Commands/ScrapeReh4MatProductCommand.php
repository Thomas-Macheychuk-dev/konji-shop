<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reh4Mat\Reh4MatProductScraper;
use Illuminate\Console\Command;

final class ScrapeReh4MatProductCommand extends Command
{
    protected $signature = 'reh4mat:product
        {url : Reh4Mat product URL to scrape.}
        {--json : Print scraped product data as JSON.}
        {--save= : Save scraped product data as JSON under storage/app.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=5000 : Milliseconds to pause before each Reh4Mat HTTP request.}
        {--show-failures : Print failed Reh4Mat URLs.}';

    protected $description = 'Scrape one Reh4Mat product page into normalized product JSON.';

    public function __construct(
        private readonly Reh4MatProductScraper $scraper,
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
            $this->info('Scraping Reh4Mat product page...');
        }

        $result = $this->scraper->scrape((string) $this->argument('url'));

        if ($json) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ) ?: '{}');
        } else {
            $this->printSummary($result);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Reh4Mat URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return $result['name'] === '' || $result['failed_urls'] !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printSummary(array $result): void
    {
        $this->info('Scraped Reh4Mat product:');
        $this->line('Name: '.($result['name'] ?: '[missing]'));
        $this->line('Canonical URL: '.($result['canonical_url'] ?? '[missing]'));
        $this->line('External product ID: '.($result['external_product_id'] ?? '[missing]'));
        $this->line('SKU: '.($result['sku'] ?? '[missing]'));
        $this->line('Brand: '.($result['brand'] ?? '[missing]'));
        $this->line('Category: '.($result['category'] ?? '[missing]'));
        $this->line('Images: '.count($result['images']));
        $this->line('Pictograms: '.count($result['pictograms']));
        $this->line('Regulatory icons: '.count($result['regulatory_icons']));
        $this->line('Downloads: '.count($result['downloads']));
        $this->line('Tabs: '.count($result['tabs']));

        if (($result['warnings'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Warnings:');

            foreach ($result['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }
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
            return 5000;
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
