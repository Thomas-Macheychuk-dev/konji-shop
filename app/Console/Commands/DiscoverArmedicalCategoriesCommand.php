<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Armedical\ArmedicalCategoryUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class DiscoverArmedicalCategoriesCommand extends Command
{
    protected $signature = 'armedical:categories
        {--url=* : ARmedical catalogue/navigation page. Defaults to https://armedical.pl/katalog/.}
        {--max-pages=100 : Maximum number of ARmedical category/navigation pages to inspect.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per request.}
        {--retry-delay-ms=1500 : Milliseconds to pause between retry attempts.}
        {--request-delay-ms=500 : Milliseconds to pause before each request.}
        {--json : Print result as JSON.}
        {--save= : Save JSON on the local filesystem disk.}
        {--show-failures : Print failed URLs.}';

    protected $description = 'Discover ARmedical catalogue category URLs without writing catalogue data.';

    public function __construct(
        private readonly ArmedicalCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $urls = $this->option('url') ?: [ArmedicalCategoryUrlScraper::DEFAULT_URL];

        $this->scraper
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withMaxAttempts(max(1, (int) $this->option('attempts')), max(0, (int) $this->option('retry-delay-ms')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withMaxPages(max(1, (int) $this->option('max-pages')))
            ->withProgressCallback(fn (string $message): null => $this->line($message));

        $this->info('Discovering ARmedical category URLs...');
        $result = $this->scraper->scrape($urls);

        $this->info('Visited pages: '.count($result['visited_urls'] ?? []));
        $this->info('Top categories: '.count($result['top_categories'] ?? []));
        $this->info('Discovered category URLs: '.count($result['category_urls'] ?? []));
        $this->info('Product-scraping category URLs: '.count($result['product_category_urls'] ?? []));

        if ((bool) $this->option('json')) {
            $this->line($this->json($result));
        } else {
            foreach ($result['categories'] ?? [] as $category) {
                $this->line(sprintf(
                    '- %s | %s',
                    (string) ($category['name'] ?? 'Unknown'),
                    (string) ($category['url'] ?? ''),
                ));
            }
        }

        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] ?? [] as $url => $reason) {
                $this->warn($url.' - '.$reason);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        }

        return ($result['category_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        Storage::disk('local')->put($relativePath, $this->json($result));
        $this->info('Saved discovery result to '.Storage::disk('local')->path($relativePath));
    }

    private function json(array $result): string
    {
        return (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
