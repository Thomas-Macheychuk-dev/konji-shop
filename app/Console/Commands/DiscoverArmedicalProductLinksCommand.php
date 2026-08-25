<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Armedical\ArmedicalProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DiscoverArmedicalProductLinksCommand extends Command
{
    protected $signature = 'armedical:product-links
        {--url=* : ARmedical listing URL. Defaults to the complete /oferta/ archive.}
        {--categories-from= : Existing ARmedical category discovery JSON on the local filesystem disk.}
        {--category-limit= : Maximum category URLs when --categories-from is used.}
        {--page-limit= : Maximum listing pages to visit. Useful for smoke tests.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per request.}
        {--retry-delay-ms=1500 : Milliseconds to pause between retries.}
        {--request-delay-ms=500 : Milliseconds to pause before each request.}
        {--json : Print result as JSON.}
        {--save= : Save JSON on the local filesystem disk.}
        {--show-failures : Print failed URLs.}';

    protected $description = 'Discover ARmedical product URLs from the complete offer archive or category pages.';

    public function __construct(
        private readonly ArmedicalProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->scraper
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withMaxAttempts(max(1, (int) $this->option('attempts')), max(0, (int) $this->option('retry-delay-ms')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withProgressCallback(fn (string $message): null => $this->line($message));

        $categoriesFrom = $this->option('categories-from');

        if (is_string($categoriesFrom) && trim($categoriesFrom) !== '') {
            $this->info('Discovering ARmedical product URLs from saved categories...');
            $result = $this->scraper->scrapeFromCategoryDiscovery(
                $this->loadJson($categoriesFrom),
                $this->nullablePositiveInt($this->option('page-limit')),
                $this->nullablePositiveInt($this->option('category-limit')),
            );
        } else {
            $urls = $this->option('url') ?: [ArmedicalProductUrlScraper::DEFAULT_URL];
            $this->info('Discovering ARmedical product URLs from listing pages...');
            $result = $this->scraper->scrapeListings(
                $urls,
                $this->nullablePositiveInt($this->option('page-limit')),
            );
        }

        $this->info('Visited listing pages: '.count($result['visited_urls'] ?? []));
        $this->info('Discovered product URLs: '.count($result['product_urls'] ?? []));
        $this->info('Failed listing URLs: '.count($result['failed_urls'] ?? []));

        if ((bool) $this->option('json')) {
            $this->line($this->json($result));
        } else {
            foreach ($result['products'] ?? [] as $product) {
                $this->line(sprintf(
                    '- %s | %s | %s',
                    (string) ($product['catalogue_number'] ?? 'no catalogue number'),
                    (string) ($product['name'] ?? 'Unknown'),
                    (string) ($product['url'] ?? ''),
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

        return ($result['product_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function loadJson(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new \RuntimeException('JSON file not found on local filesystem disk: '.$relativePath);
        }

        try {
            return json_decode(Storage::disk('local')->get($relativePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Invalid JSON on local filesystem disk at '.$relativePath.': '.$exception->getMessage(), 0, $exception);
        }
    }

    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        Storage::disk('local')->put($relativePath, $this->json($result));
        $this->info('Saved product-link discovery to '.Storage::disk('local')->path($relativePath));
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    private function json(array $result): string
    {
        return (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
