<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reh4Mat\Reh4MatCategoryScraper;
use Illuminate\Console\Command;

final class ScrapeReh4MatCategoriesCommand extends Command
{
    protected $signature = 'reh4mat:categories
        {--url=* : Reh4Mat catalogue page URL to scan. Defaults to https://www.reh4mat.com/produkt/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed and skipped Reh4Mat URLs/categories.}
        {--request-delay-ms=500 : Milliseconds to pause before each Reh4Mat HTTP request.}';

    protected $description = 'Discover allowed Reh4Mat category hierarchy for later product scraping.';

    public function __construct(
        private readonly Reh4MatCategoryScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [Reh4MatCategoryScraper::DEFAULT_CATEGORY_URL];
        $json = (bool) $this->option('json');

        $this->scraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json) {
            $this->scraper->withProgressCallback(function (string $message): void {
                $this->line($message);
            });
            $this->info('Discovering Reh4Mat allowed category hierarchy...');
        }

        $result = $this->scraper->scrape($urls);

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Visited pages: '.count($result['visited_urls']));
            $this->info('Discovered top categories: '.count($result['top_categories']));
            $this->info('Discovered categories including descendants: '.count($result['categories']));
            $this->info('Product-scraping leaf category URLs: '.count($result['product_category_urls']));

            $this->printHierarchyResult($result);
        }

        if ((bool) $this->option('show-failures')) {
            $this->printFailuresAndSkipped($result);
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $result['top_categories'] === [] ? self::FAILURE : self::SUCCESS;
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
    private function printHierarchyResult(array $result): void
    {
        $this->newLine();
        $this->line('Category hierarchy:');

        foreach ($result['top_categories'] as $topCategory) {
            $this->line('- '.$topCategory['name']);
            $this->line('  '.$topCategory['url']);

            if ($topCategory['children'] === []) {
                $this->line('  No internal lower categories; products will be scraped from the top category URL.');

                continue;
            }

            foreach ($topCategory['children'] as $childCategory) {
                $this->line('  - '.$childCategory['name']);
                $this->line('    '.$childCategory['url']);
            }
        }

        $this->newLine();
        $this->line('Product-scraping category URLs:');

        foreach ($result['product_category_urls'] as $categoryUrl) {
            $this->line($categoryUrl);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printFailuresAndSkipped(array $result): void
    {
        if ($result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Reh4Mat URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if ($result['skipped_categories'] !== []) {
            $this->newLine();
            $this->warn('Skipped Reh4Mat categories/links:');

            foreach ($result['skipped_categories'] as $skipped) {
                $this->line($skipped['name'].' - '.$skipped['url'].' - '.$skipped['reason']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Saved discovery result to storage/app/'.$relativePath);
    }
}
