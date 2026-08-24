<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisCategoryUrlScraper;
use Illuminate\Console\Command;
use RuntimeException;

final class DiscoverSigvarisCategoriesCommand extends Command
{
    protected $signature = 'sigvaris:categories
        {--url=https://www.sklep-sigvaris.com/ : Sigvaris store URL.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per request.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retry.}
        {--request-delay-ms=500 : Milliseconds to pause before each request.}
        {--insecure : Disable TLS verification.}
        {--no-progress : Do not print request progress.}
        {--json : Print JSON.}
        {--save= : Save JSON under storage/app.}
        {--show-failures : Print failed URLs.}';

    protected $description = 'Discover sklep-sigvaris.com PrestaShop category hierarchy without database writes.';

    public function __construct(private readonly SigvarisCategoryUrlScraper $scraper) { parent::__construct(); }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $this->scraper
            ->withTlsVerification(! (bool) $this->option('insecure'))
            ->withTimeout($this->intOption('timeout', 20, 1))
            ->withAttempts($this->intOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->intOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->intOption('request-delay-ms', 500, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $m): null => $this->line($m));
        }

        $result = $this->scraper->scrape((string) $this->option('url'));

        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->info('Sigvaris category discovery');
            $this->line('Categories: '.count($result['categories'] ?? []));
            $this->line('Top categories: '.count($result['top_categories'] ?? []));
            $this->line('Category URLs: '.count($result['category_urls'] ?? []));
            $this->line('Failed URLs: '.count($result['failed_urls'] ?? []));
            foreach ($result['top_categories'] ?? [] as $category) {
                $this->line('- '.implode(' > ', $category['path'] ?? []).' | '.($category['url'] ?? ''));
            }
        }

        $this->showFailures($result);
        $this->save($result, $json);
        return ($result['category_urls'] ?? []) !== [] ? self::SUCCESS : self::FAILURE;
    }

    private function intOption(string $name, int $default, int $min): int
    {
        $v = $this->option($name);
        return is_numeric($v) ? max($min, (int) $v) : $default;
    }

    /** @param array<string,mixed> $result */
    private function showFailures(array $result): void
    {
        if (! (bool) $this->option('show-failures')) return;
        foreach ($result['failed_urls'] ?? [] as $url => $reason) $this->warn($url.' - '.$reason);
    }

    /** @param array<string,mixed> $result */
    private function save(array $result, bool $quiet): void
    {
        $relative = trim((string) ($this->option('save') ?? ''));
        if ($relative === '') return;
        $path = storage_path('app/'.ltrim($relative, '/'));
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) throw new RuntimeException('Unable to create Sigvaris scraper directory.');
        if (file_put_contents($path, $this->encode($result).PHP_EOL) === false) throw new RuntimeException('Unable to save Sigvaris category JSON.');
        if (! $quiet) $this->info('Saved category discovery to storage/app/'.ltrim($relative, '/'));
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($json)) throw new RuntimeException('Unable to encode Sigvaris JSON.');
        return $json;
    }
}
