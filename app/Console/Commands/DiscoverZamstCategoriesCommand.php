<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zamst\ZamstCategoryUrlScraper;
use Illuminate\Console\Command;
use RuntimeException;

final class DiscoverZamstCategoriesCommand extends Command
{
    protected $signature = 'zamst:categories
        {--url=https://zamst.com.pl/sklep/ : Zamst catalogue URL.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Zamst request.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Zamst HTTP request.}
        {--insecure : Disable TLS certificate verification for Zamst requests.}
        {--no-progress : Do not print request progress.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Zamst URLs.}';

    protected $description = 'Discover Zamst WooCommerce product categories without writing to the database.';

    public function __construct(
        private readonly ZamstCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');

        $this->scraper
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withAttempts($this->integerOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->integerOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 500, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json) {
            $this->info('Discovering Zamst category hierarchy.');

            if ($insecure) {
                $this->warn('TLS certificate verification is disabled for this Zamst run.');
            }
        }

        $result = $this->scraper->scrape((string) $this->option('url'));

        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->info('Visited pages: '.count($result['visited_urls'] ?? []));
            $this->info('Discovered category URLs: '.count($result['category_urls'] ?? []));
            $this->info('Top categories: '.count($result['top_categories'] ?? []));
            $this->info('Catalogue sections: '.count(array_filter(
                $result['categories'] ?? [],
                static fn (array $category): bool => (bool) ($category['is_catalogue_section'] ?? false),
            )));

            foreach ($result['categories'] ?? [] as $category) {
                $this->line(sprintf(
                    '- %s%s - %s',
                    implode(' > ', $category['path'] ?? []),
                    ($category['product_count'] ?? null) !== null ? ' ['.(int) $category['product_count'].' products on shop page]' : '',
                    $category['url'] ?? '',
                ));
            }
        }

        $this->printFailures($result);

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result, $json);
        }

        return ($result['category_urls'] ?? []) === [] ? self::FAILURE : self::SUCCESS;
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max($minimum, (int) $value) : $default;
    }

    /** @param array<string, mixed> $result */
    private function printFailures(array $result): void
    {
        if (! (bool) $this->option('show-failures') || ($result['failed_urls'] ?? []) === []) {
            return;
        }

        $this->newLine();
        $this->warn('Failed Zamst URLs:');

        foreach ($result['failed_urls'] as $url => $reason) {
            $this->line($url.' - '.$reason);
        }
    }

    /** @param array<string, mixed> $data */
    private function saveJson(string $relativePath, array $data, bool $quiet): void
    {
        $relativePath = ltrim(trim($relativePath), '/');

        if ($relativePath === '') {
            throw new RuntimeException('Zamst category save path cannot be empty.');
        }

        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Zamst scraper directory: '.$directory);
        }

        if (file_put_contents($path, $this->encode($data).PHP_EOL) === false) {
            throw new RuntimeException('Unable to save Zamst category discovery JSON.');
        }

        if (! $quiet) {
            $this->info('Saved category discovery result to storage/app/'.$relativePath);
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Zamst category discovery JSON.');
        }

        return $encoded;
    }
}
