<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Apolonia\ApoloniaCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverApoloniaCategoriesCommand extends Command
{
    protected $signature = 'apolonia:categories
        {--url=* : Apolonia category/menu page to scan. Defaults to https://www.apolonia.com.pl/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Apolonia URLs.}
        {--page-limit=250 : Maximum Apolonia category pages to visit while discovering nested categories.}
        {--request-delay-ms=500 : Milliseconds to pause before each Apolonia HTTP request.}';

    protected $description = 'Discover Apolonia category hierarchy and product-scraping category URLs.';

    public function __construct(
        private readonly ApoloniaCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [ApoloniaCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withMaxPages($this->pageLimit())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Apolonia category hierarchy...');

        $result = $this->scraper->scrape($urls);
        $categoryUrls = $result['category_urls'];
        $productCategoryUrls = $result['product_category_urls'];

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Discovered category URLs: '.count($categoryUrls));
        $this->info('Product-scraping category URLs: '.count($productCategoryUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['categories'] as $category) {
                $indent = str_repeat('  ', max(0, ((int) $category['level']) - 1));
                $this->line($indent.$category['name'].' - '.$category['url']);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Apolonia URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $productCategoryUrls === [] ? self::FAILURE : self::SUCCESS;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        if (! is_string($value) || trim($value) === '') {
            return 500;
        }

        return max(0, (int) $value);
    }

    private function pageLimit(): int
    {
        $value = $this->option('page-limit');

        if (! is_string($value) || trim($value) === '') {
            return 250;
        }

        return max(1, (int) $value);
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
