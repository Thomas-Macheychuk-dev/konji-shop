<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Peruka\PerukaCategoryUrlScraper;
use App\Services\Peruka\PerukaProductUrlScraper;
use Illuminate\Console\Command;

final class DiscoverPerukaProductsCommand extends Command
{
    protected $signature = 'peruka:products
        {--category=* : Peruka category URL to scan. If omitted, categories are discovered from --url pages.}
        {--url=* : Peruka page to scan for category navigation when --category is omitted. Defaults to https://www.peruka.pl/.}
        {--limit= : Stop after discovering this many product URLs.}
        {--max-pages= : Stop after visiting this many paginated pages per category.}
        {--category-limit= : Stop after scanning this many category URLs. Useful for smoke tests.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Peruka URLs.}
        {--request-delay-ms=500 : Milliseconds to pause before each Peruka HTTP request.}';

    protected $description = 'Discover Peruka product URLs from category pages, including pagination.';

    public function __construct(
        private readonly PerukaProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $categoryUrls = $this->option('category') ?: [];
        $startUrls = $this->option('url') ?: [PerukaCategoryUrlScraper::DEFAULT_URL];
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $maxPages = $this->option('max-pages') !== null ? max(1, (int) $this->option('max-pages')) : null;
        $categoryLimit = $this->option('category-limit') !== null ? max(1, (int) $this->option('category-limit')) : null;
        $shouldDiscoverCategories = $categoryUrls === [];

        $this->scraper
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Peruka product URLs...');

        $result = $this->scraper->scrape(
            categoryUrls: $categoryUrls,
            startUrls: $startUrls,
            discoverCategories: $shouldDiscoverCategories,
            limit: $limit,
            maxPages: $maxPages,
            categoryLimit: $categoryLimit,
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
            $this->warn('Failed Peruka URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $productUrls === [] ? self::FAILURE : self::SUCCESS;
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
