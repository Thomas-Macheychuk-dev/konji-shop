<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TwojaPeruka\TwojaPerukaCategoryScraper;
use Illuminate\Console\Command;

final class ScrapeTwojaPerukaCategoriesCommand extends Command
{
    protected $signature = 'twojaperuka:categories
        {--url=* : TwojaPeruka category/menu page to scan. Defaults to https://twojaperuka.pl/peruki.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed TwojaPeruka URLs.}
        {--request-delay-ms=500 : Milliseconds to pause before each TwojaPeruka HTTP request.}';

    protected $description = 'Discover allowed TwojaPeruka category hierarchy for later product scraping.';

    public function __construct(
        private readonly TwojaPerukaCategoryScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [TwojaPerukaCategoryScraper::DEFAULT_CATEGORY_URL];
        $json = (bool) $this->option('json');

        $this->scraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        if (! $json) {
            $this->scraper->withProgressCallback(function (string $message): void {
                $this->line($message);
            });
            $this->info('Discovering TwojaPeruka allowed category hierarchy...');
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

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed TwojaPeruka URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
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
            $this->printCategory($topCategory);
        }

        $this->newLine();
        $this->line('Product-scraping category URLs:');

        foreach ($result['product_category_urls'] as $categoryUrl) {
            $this->line($categoryUrl);
        }
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function printCategory(array $category, int $depth = 0): void
    {
        $indent = str_repeat('  ', $depth);
        $this->line($indent.'- '.$category['name']);
        $this->line($indent.'  '.$category['url']);

        foreach (($category['children'] ?? []) as $childCategory) {
            if (is_array($childCategory)) {
                $this->printCategory($childCategory, $depth + 1);
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
