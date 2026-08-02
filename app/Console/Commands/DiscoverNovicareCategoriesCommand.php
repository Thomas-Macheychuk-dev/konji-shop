<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Novicare\NovicareCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverNovicareCategoriesCommand extends Command
{
    protected $signature = 'novicare:categories
        {--url=* : Novicare page containing the product category catalogue. Defaults to https://novicare.pl/produkty/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Novicare URLs.}
        {--insecure : Disable TLS certificate verification for Novicare requests.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Novicare page.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Novicare HTTP request.}';

    protected $description = 'Discover Novicare product categories from the Polish catalogue page.';

    public function __construct(
        private readonly NovicareCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [NovicareCategoryUrlScraper::DEFAULT_URL];
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

        $this->info('Discovering Novicare product categories...');

        if ($insecure) {
            $this->warn('TLS certificate verification is disabled for this Novicare run.');
        }

        $result = $this->scraper->scrape($urls);

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Discovered category URLs: '.count($result['category_urls']));
        $this->info('Product-scraping category URLs: '.count($result['product_category_urls']));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            foreach ($result['categories'] as $category) {
                $this->line($category['name'].' [product category] - '.$category['url']);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Novicare URLs:');

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

        return is_numeric($value)
            ? max($minimum, (int) $value)
            : $default;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim(trim($relativePath), '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ).PHP_EOL,
        );

        $this->info('Saved discovery result to storage/app/'.$relativePath);
    }
}
