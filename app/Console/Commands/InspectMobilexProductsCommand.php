<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mobilex\MobilexProductScraper;
use Illuminate\Console\Command;

final class InspectMobilexProductsCommand extends Command
{
    protected $signature = 'mobilex:inspect-products
        {--links=mobilex/product-links.json : Product-links JSON path. Relative paths are resolved under storage/app.}
        {--save= : Save normalized batch JSON under storage/app.}
        {--limit= : Maximum number of products to inspect.}
        {--offset=0 : Number of products to skip before inspection.}
        {--timeout=15 : HTTP request timeout in seconds.}
        {--request-delay-ms=500 : Milliseconds to pause before each Mobilex HTTP request.}
        {--show-failures : Print failed product URLs at the end.}
        {--no-progress : Do not print per-product progress.}';

    protected $description = 'Inspect and normalize Mobilex products from a product-links JSON file without importing them.';

    public function __construct(
        private readonly MobilexProductScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $linksPath = $this->resolvePath((string) $this->option('links'));
        $products = $this->loadProductContexts($linksPath);

        if ($products === []) {
            $this->error('No Mobilex products found in links file: '.$linksPath);

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $timeout = $this->positiveIntOption('timeout', 15);
        $requestDelayMs = $this->nonNegativeIntOption('request-delay-ms', 500);
        $quiet = (bool) $this->option('no-progress');

        $selectedProducts = array_slice($products, $offset, $limit);

        $this->scraper
            ->withTimeout($timeout)
            ->withRequestDelayMilliseconds($requestDelayMs);

        if (! $quiet) {
            $this->scraper->withProgressCallback(fn (string $message): null => $this->line('  '.$message));
        }

        $this->info('Inspecting Mobilex products from: '.$linksPath);
        $this->line('Available products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selectedProducts));
        $this->line('Request delay: '.$requestDelayMs.'ms');

        $normalizedProducts = [];
        $failures = [];
        $processed = 0;
        $total = count($selectedProducts);

        foreach ($selectedProducts as $index => $context) {
            $processed++;
            $url = is_string($context['url'] ?? null) ? $context['url'] : null;

            if ($url === null || trim($url) === '') {
                $failures[] = [
                    'url' => null,
                    'name' => is_string($context['name'] ?? null) ? $context['name'] : null,
                    'error' => 'Missing product URL in product-links context.',
                ];

                continue;
            }

            if (! $quiet) {
                $name = is_string($context['name'] ?? null) ? $context['name'] : $url;
                $this->line(sprintf('Inspecting product %d/%d: %s', $processed, $total, $name));
                $this->line('  '.$url);
            }

            try {
                $normalizedProducts[] = $this->scraper->scrape($url, $context);
            } catch (\Throwable $exception) {
                $failures[] = [
                    'url' => $url,
                    'name' => is_string($context['name'] ?? null) ? $context['name'] : null,
                    'error' => $exception->getMessage(),
                ];

                if (! $quiet) {
                    $this->warn('  Failed: '.$exception->getMessage());
                }
            }
        }

        $result = [
            'source' => 'mobilex',
            'links_file' => $this->displayPath($linksPath),
            'available_products' => count($products),
            'offset' => $offset,
            'limit' => $limit,
            'inspected' => count($normalizedProducts),
            'failed' => count($failures),
            'products' => $normalizedProducts,
            'failures' => $failures,
        ];

        $this->info('Inspected products: '.count($normalizedProducts));
        $this->line('Failures: '.count($failures));

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->warn('Failed Mobilex products:');

            foreach ($failures as $failure) {
                $this->line('- '.($failure['url'] ?? '[missing url]').' — '.$failure['error']);
            }
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $result);
        } else {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProductContexts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Product links file not found: '.$path);

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Product links file does not contain a products array: '.$path);

            return [];
        }

        $products = [];
        $seen = [];

        foreach ($decoded['products'] as $product) {
            if (! is_array($product) || ! isset($product['url']) || ! is_string($product['url'])) {
                continue;
            }

            $url = $this->normalizeProductUrlForLookup($product['url']);

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            /** @var array<string, mixed> $product */
            $products[] = $product;
        }

        return $products;
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

    private function nullablePositiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = 'mobilex/product-links.json';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return storage_path('app/'.ltrim($path, '/'));
    }

    private function displayPath(string $path): string
    {
        $storageApp = storage_path('app');

        if (str_starts_with($path, $storageApp.'/')) {
            return 'storage/app/'.ltrim(substr($path, strlen($storageApp)), '/');
        }

        return $path;
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

        $this->info('Saved Mobilex batch inspection result to storage/app/'.$relativePath);
    }
}
