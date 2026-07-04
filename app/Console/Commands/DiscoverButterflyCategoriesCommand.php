<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Butterfly\ButterflyCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverButterflyCategoriesCommand extends Command
{
    protected $signature = 'butterfly:categories
        {--url=* : Butterfly category/menu page to scan. Defaults to https://butterfly-mag.com.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Butterfly URLs.}
        {--request-delay-ms=500 : Milliseconds to pause before each Butterfly HTTP request.}';

    protected $description = 'Discover Butterfly category hierarchy and product-scraping category URLs.';

    public function __construct(
        private readonly ButterflyCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [ButterflyCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Butterfly category hierarchy...');

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
            $this->warn('Failed Butterfly URLs:');

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
