<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wojdak\WojdakCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverWojdakCategoriesCommand extends Command
{
    protected $signature = 'wojdak:categories
        {--category=* : Wojdak shop category URL to list. Defaults to the hard-coded Wojdak shop categories.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Wojdak category URLs.}';

    protected $description = 'List hard-coded Wojdak shop category URLs for the Wojdak product scraper.';

    public function __construct(
        private readonly WojdakCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rootCategories = $this->option('category') ?: WojdakCategoryUrlScraper::DEFAULT_CATEGORY_URLS;

        $this->info('Listing Wojdak shop category URLs...');

        $result = $this->scraper->scrape(
            startUrls: $rootCategories,
            includeHardCodedCategories: true,
        );

        $categoryUrls = $result['category_urls'];

        $this->info('Configured category URLs: '.count($categoryUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($categoryUrls as $categoryUrl) {
                $this->line($categoryUrl);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Wojdak category URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $categoryUrls === [] ? self::FAILURE : self::SUCCESS;
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
