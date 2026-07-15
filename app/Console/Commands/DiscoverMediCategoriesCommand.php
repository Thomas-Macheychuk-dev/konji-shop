<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Medi\MediCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverMediCategoriesCommand extends Command
{
    protected $signature = 'medi:categories
        {--url=* : medi shop or approved category page to scan. Defaults to https://www.medi-polska.pl/shop/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed medi URLs.}
        {--page-limit=100 : Maximum medi pages to visit while discovering nested categories.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per medi HTTP request.}
        {--retry-delay-ms=1500 : Milliseconds to pause between retry attempts.}
        {--request-delay-ms=500 : Milliseconds to pause before each medi HTTP request.}';

    protected $description = 'Discover the medi Kompresja, Ortopedia, and Akcesoria category hierarchy.';

    public function __construct(
        private readonly MediCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [MediCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withMaxPages($this->pageLimit())
            ->withTimeout($this->timeoutSeconds())
            ->withMaxAttempts($this->maxAttempts(), $this->retryDelayMilliseconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering medi category hierarchy...');

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
            $this->warn('Failed medi URLs:');

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

    private function maxAttempts(): int
    {
        $value = $this->option('attempts');

        return is_string($value) && trim($value) !== '' ? max(1, (int) $value) : 3;
    }

    private function retryDelayMilliseconds(): int
    {
        $value = $this->option('retry-delay-ms');

        return is_string($value) && trim($value) !== '' ? max(0, (int) $value) : 1500;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        return is_string($value) && trim($value) !== '' ? max(0, (int) $value) : 500;
    }

    private function pageLimit(): int
    {
        $value = $this->option('page-limit');

        return is_string($value) && trim($value) !== '' ? max(1, (int) $value) : 100;
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
