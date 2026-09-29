<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seni24\Seni24ProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DiscoverSeni24ProductLinksCommand extends Command
{
    protected $signature = 'seni24:product-links
        {--url=* : Seni24 category page to scan. Defaults to the five primary commerce roots.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Seni24 HTTP request.}
        {--max-category-pages=1200 : Hard safety limit for recursive category traversal.}
        {--no-progress : Do not print progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save discovery JSON under storage/app/private.}
        {--show-failures : Print failed Seni24 category URLs.}';

    protected $description = 'Discover Seni24 product URLs recursively from the public commerce category trees.';

    public function __construct(
        private readonly Seni24ProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $this->scraper
            ->withTimeout($this->timeoutSeconds())
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withMaxCategoryPages($this->maxCategoryPages());

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(
                fn (string $message): null => $this->line($message),
            );
        }

        $urls = $this->option('url') ?: Seni24ProductUrlScraper::DEFAULT_URLS;
        $result = $this->scraper->scrape(array_values(array_map('strval', $urls)));

        if ($json) {
            $this->line($this->encodeJson($result));
        } else {
            $this->info('Visited category pages: '.$result['visited_category_count']);
            $this->info('Discovered product URLs: '.$result['product_count']);
            $this->info('Failed category URLs: '.count($result['failed_urls']));
            $this->info('Stopped at category safety limit: '.($result['stopped_at_category_limit'] ? 'yes' : 'no'));
        }

        if ((bool) $this->option('show-failures')) {
            foreach ($result['failed_urls'] as $url => $reason) {
                $this->warn($url.' - '.$reason);
            }
        }

        $save = $this->option('save');

        if (is_string($save) && trim($save) !== '') {
            $relativePath = ltrim(trim($save), '/');

            if (! Storage::disk('local')->put($relativePath, $this->encodeJson($result))) {
                throw new RuntimeException('Unable to save Seni24 discovery JSON: '.$relativePath);
            }

            if (! Storage::disk('local')->exists($relativePath)
                || Storage::disk('local')->size($relativePath) === 0) {
                throw new RuntimeException('Seni24 discovery JSON was written as an empty artifact: '.$relativePath);
            }
        }

        return $result['product_count'] > 0 && ! $result['stopped_at_category_limit']
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function encodeJson(array $result): string
    {
        return json_encode(
            $result,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }

    private function timeoutSeconds(): int
    {
        $value = $this->option('timeout');

        return is_string($value) && (int) $value > 0 ? (int) $value : 20;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        return is_string($value) ? max(0, (int) $value) : 500;
    }

    private function maxCategoryPages(): int
    {
        $value = $this->option('max-category-pages');

        return is_string($value) && (int) $value > 0 ? (int) $value : 1200;
    }
}
