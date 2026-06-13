<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mobilex\MobilexProductScraper;
use Illuminate\Console\Command;

final class InspectMobilexProductCommand extends Command
{
    protected $signature = 'mobilex:inspect
        {url : Mobilex product URL, for example https://mobilex.pl/produkty/wozek-inwalidzki-flipper/}
        {--links= : Optional product-links JSON path. Relative paths are resolved under storage/app.}
        {--save= : Save normalized JSON under storage/app.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before the Mobilex HTTP request.}
        {--no-progress : Do not print fetch progress.}';

    protected $description = 'Inspect and normalize a single Mobilex product page without importing it.';

    public function __construct(
        private readonly MobilexProductScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = (string) $this->argument('url');
        $timeout = $this->positiveIntOption('timeout', 15);
        $requestDelayMs = $this->nonNegativeIntOption('request-delay-ms', 500);
        $context = $this->findProductContext($url);

        $this->scraper
            ->withTimeout($timeout)
            ->withRequestDelayMilliseconds($requestDelayMs);

        if (! (bool) $this->option('no-progress')) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        try {
            $normalized = $this->scraper->scrape($url, $context);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $normalized);
        }

        return self::SUCCESS;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : $default;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findProductContext(string $url): ?array
    {
        $linksPath = $this->option('links');

        if (! is_string($linksPath) || trim($linksPath) === '') {
            return null;
        }

        $path = $this->resolvePath($linksPath);

        if (! is_file($path)) {
            $this->warn('Product links file not found: '.$path);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->warn('Product links file does not contain a products array: '.$path);

            return null;
        }

        $needle = $this->normalizeProductUrlForLookup($url);

        foreach ($decoded['products'] as $product) {
            if (! is_array($product) || ! isset($product['url']) || ! is_string($product['url'])) {
                continue;
            }

            if ($this->normalizeProductUrlForLookup($product['url']) === $needle) {
                /** @var array<string, mixed> $product */
                return $product;
            }
        }

        $this->warn('Product URL was not found in links file: '.$url);

        return null;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return storage_path('app/'.ltrim($path, '/'));
    }

    private function normalizeProductUrlForLookup(string $url): string
    {
        $parts = parse_url(trim($url));
        $path = isset($parts['path']) ? '/'.trim((string) $parts['path'], '/').'/' : '/';
        $host = isset($parts['host']) ? mb_strtolower((string) $parts['host']) : 'mobilex.pl';

        return 'https://'.$host.$path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveJson(string $relativePath, array $data): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Saved Mobilex product inspection result to storage/app/'.$relativePath);
    }
}
