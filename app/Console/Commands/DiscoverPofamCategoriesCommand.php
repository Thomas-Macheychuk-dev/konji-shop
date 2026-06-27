<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pofam\PofamCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverPofamCategoriesCommand extends Command
{
    protected $signature = 'pofam:categories
        {--url=* : Pofam page to scan for category navigation. Defaults to https://sklep.pofam.pl/.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Pofam URLs.}
        {--request-delay-ms=500 : Milliseconds to pause before each Pofam HTTP request.}';

    protected $description = 'Discover Pofam category URLs from the shop navigation.';

    public function __construct(
        private readonly PofamCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [PofamCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Pofam category URLs...');

        $result = $this->scraper->scrape($urls);
        $categoryUrls = $result['category_urls'];

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Discovered category URLs: '.count($categoryUrls));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['categories'] as $category) {
                $count = $category['count'] === null ? '' : ' ('.$category['count'].')';
                $this->line($category['name'].$count.' - '.$category['url']);
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Pofam URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return $categoryUrls === [] ? self::FAILURE : self::SUCCESS;
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
