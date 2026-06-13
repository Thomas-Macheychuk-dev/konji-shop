<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mobilex\MobilexCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverMobilexCategoriesCommand extends Command
{
    protected $signature = 'mobilex:categories
        {--url=* : Mobilex products/category index URL to scan. Defaults to https://mobilex.pl/produkty/.}
        {--top-only : Only discover top-level categories from the Mobilex products page.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Mobilex URLs.}
        {--request-delay-ms=500 : Milliseconds to pause before each Mobilex HTTP request.}';

    protected $description = 'Discover Mobilex category URLs and lower category URLs for product-link scraping.';

    public function __construct(
        private readonly MobilexCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [MobilexCategoryUrlScraper::DEFAULT_PRODUCTS_URL];
        $topOnly = (bool) $this->option('top-only');

        $this->scraper->withRequestDelayMilliseconds($this->requestDelayMilliseconds());

        $this->info($topOnly
            ? 'Discovering Mobilex top-level category URLs...'
            : 'Discovering Mobilex category hierarchy...');

        $result = $topOnly
            ? $this->scraper->scrapeTopLevel($urls)
            : $this->scraper->scrapeHierarchy($urls);

        $categoryUrls = $result['category_urls'];

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info($topOnly
            ? 'Discovered top-level category URLs: '.count($categoryUrls)
            : 'Discovered product-scraping category URLs: '.count($categoryUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($topOnly) {
            $this->printTopOnlyResult($categoryUrls);
        } else {
            $this->printHierarchyResult($result);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Mobilex URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $categoryUrls === [] ? self::FAILURE : self::SUCCESS;
    }


    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        if (! is_string($value) || trim($value) === '') {
            return 500;
        }

        $milliseconds = (int) $value;

        return max(0, $milliseconds);
    }

    /**
     * @param  array<int, string>  $categoryUrls
     */
    private function printTopOnlyResult(array $categoryUrls): void
    {
        foreach ($categoryUrls as $categoryUrl) {
            $this->line($categoryUrl);
        }
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
                $this->line('  No lower categories; products will be scraped from the top category URL.');

                continue;
            }

            foreach ($topCategory['children'] as $childCategory) {
                $this->line('  - '.$childCategory['name']);
                $this->line('    '.$childCategory['url']);
            }
        }

        $this->newLine();
        $this->line('Product-scraping category URLs:');

        foreach ($result['category_urls'] as $categoryUrl) {
            $this->line($categoryUrl);
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
