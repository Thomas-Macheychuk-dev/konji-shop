<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Neoxmed\NeoxmedCategoryUrlScraper;
use Illuminate\Console\Command;
use RuntimeException;

final class DiscoverNeoxmedCategoriesCommand extends Command
{
    protected $signature = 'neoxmed:categories
        {--url=* : NeoxMed page containing catalogue navigation. Defaults to https://neoxmed.com/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed NeoxMed URLs.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts for HTTP 429 and 5xx responses.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying a NeoxMed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each NeoxMed HTTP request.}';

    protected $description = 'Discover the seven NeoxMed catalogue category pages.';

    public function __construct(
        private readonly NeoxmedCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [NeoxmedCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withMaxAttempts($this->maxAttempts(), $this->retryDelayMilliseconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback((bool) $this->option('json') ? null : fn (string $message): null => $this->line($message));

        if (! (bool) $this->option('json')) {
            $this->info('Discovering NeoxMed catalogue categories...');
        }

        $result = $this->scraper->scrape(array_values(array_map('strval', $urls)));

        if ((bool) $this->option('json')) {
            $this->line($this->encodeJson($result));
        } else {
            $this->info('Visited pages: '.count($result['visited_urls']));
            $this->info('Discovered category URLs: '.count($result['category_urls']));

            foreach ($result['categories'] as $category) {
                $this->line('- '.$category['name'].' - '.$category['url']);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed NeoxMed URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $savePath = $this->option('save');

        if (is_string($savePath) && trim($savePath) !== '') {
            $this->saveJson($savePath, $result);
        }

        return count($result['category_urls']) === 7 ? self::SUCCESS : self::FAILURE;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) $this->option('timeout'));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) $this->option('attempts'));
    }

    private function retryDelayMilliseconds(): int
    {
        return max(0, (int) $this->option('retry-delay-ms'));
    }

    private function requestDelayMilliseconds(): int
    {
        return max(0, (int) $this->option('request-delay-ms'));
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create NeoxMed scraper directory: '.$directory);
        }

        if (file_put_contents($path, $this->encodeJson($result)) === false) {
            throw new RuntimeException('Unable to save NeoxMed category JSON: '.$path);
        }

        $this->info('Saved discovery result to storage/app/'.$relativePath);
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
