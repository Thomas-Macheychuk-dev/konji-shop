<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vaya\VayaCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverVayaCategoriesCommand extends Command
{
    protected $signature = 'vaya:categories
        {--url=* : Vaya page containing the shop navigation. Defaults to https://www.vaya.com.pl/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Vaya URLs.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Vaya HTTP request.}';

    protected $description = 'Discover the selected Vaya category trees and product-scraping leaf categories.';

    public function __construct(
        private readonly VayaCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [VayaCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Vaya category hierarchy...');

        $result = $this->scraper->scrape($urls);
        $categoryUrls = $result['category_urls'];
        $productCategoryUrls = $result['product_category_urls'];

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Target top categories: '.count($result['top_categories']));
        $this->info('Discovered category URLs: '.count($categoryUrls));
        $this->info('Product-scraping category URLs: '.count($productCategoryUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['categories'] as $category) {
                $indent = str_repeat('  ', max(0, ((int) $category['level']) - 1));
                $suffix = (bool) $category['is_product_category'] ? ' [product category]' : '';
                $this->line($indent.$category['name'].$suffix.' - '.$category['url']);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Vaya URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $savePath = $this->option('save');

        if (is_string($savePath) && trim($savePath) !== '') {
            $this->saveJson($savePath, $result);
        }

        return $productCategoryUrls === [] ? self::FAILURE : self::SUCCESS;
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');

        return is_string($value) && trim($value) !== '' ? max(1, (int) $value) : 20;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        return is_string($value) && trim($value) !== '' ? max(0, (int) $value) : 500;
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
