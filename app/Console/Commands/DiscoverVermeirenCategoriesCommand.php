<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vermeiren\VermeirenCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverVermeirenCategoriesCommand extends Command
{
    protected $signature = 'vermeiren:categories
        {--url=* : Vermeiren page containing the Produkty menu. Defaults to the Polish home page.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Vermeiren URLs.}
        {--insecure : Disable TLS certificate verification for Vermeiren requests.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Vermeiren page.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Vermeiren HTTP request.}';

    protected $description = 'Discover Vermeiren categories from the Polish Produkty navigation menu.';

    public function __construct(
        private readonly VermeirenCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [VermeirenCategoryUrlScraper::DEFAULT_URL];

        $insecure = (bool) $this->option('insecure');

        $this->scraper
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withAttempts($this->integerOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->integerOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 500, 0))
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Vermeiren category hierarchy from the Produkty menu...');

        if ($insecure) {
            $this->warn('TLS certificate verification is disabled for this Vermeiren run.');
        }

        $result = $this->scraper->scrape($urls);

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Top product categories: '.count($result['top_categories']));
        $this->info('Discovered category URLs: '.count($result['category_urls']));
        $this->info('Product-scraping category URLs: '.count($result['product_category_urls']));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } else {
            foreach ($result['categories'] as $category) {
                $indent = str_repeat('  ', max(0, ((int) $category['level']) - 1));
                $suffix = (bool) $category['is_product_category'] ? ' [product category]' : '';
                $this->line($indent.$category['name'].$suffix.' - '.$category['url']);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Vermeiren URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $savePath = $this->option('save');

        if (is_string($savePath) && trim($savePath) !== '') {
            $this->saveJson($savePath, $result);
        }

        return $result['product_category_urls'] === [] ? self::FAILURE : self::SUCCESS;
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== ''
            ? max($minimum, (int) $value)
            : $default;
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
