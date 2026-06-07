<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wojdak\WojdakProductUrlScraper;
use Illuminate\Console\Command;

final class DiscoverWojdakProductsCommand extends Command
{
    protected $signature = 'wojdak:products
        {--category=* : Wojdak shop category URL to scan. If omitted, the hard-coded Wojdak shop categories are used.}
        {--root-category=* : Deprecated alias for --category kept for backwards compatibility.}
        {--limit= : Stop after discovering this many product URLs.}
        {--max-pages= : Stop after visiting this many paginated pages per category.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed category/root URLs.}';

    protected $description = 'Discover Wojdak product URLs from hard-coded WooCommerce category pages, including pagination.';

    public function __construct(
        private readonly WojdakProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $categoryUrls = $this->option('category') ?: ($this->option('root-category') ?: []);
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $maxPages = $this->option('max-pages') !== null ? max(1, (int) $this->option('max-pages')) : null;
        $shouldDiscoverCategories = $categoryUrls === [];

        $this->info('Discovering Wojdak product URLs...');

        $result = $this->scraper->scrape(
            categoryUrls: $categoryUrls,
            discoverCategories: $shouldDiscoverCategories,
            limit: $limit,
            maxPages: $maxPages,
        );

        $productUrls = $result['product_urls'];

        $this->info('Category URLs: '.count($result['category_urls']));
        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Discovered product URLs: '.count($productUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($productUrls as $productUrl) {
                $this->line($productUrl);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Wojdak URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $productUrls === [] ? self::FAILURE : self::SUCCESS;
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
