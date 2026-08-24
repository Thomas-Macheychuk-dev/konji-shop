<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisCombinationEnumerator;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class EnumerateSigvarisCombinationsCommand extends Command
{
    protected $signature = 'sigvaris:combinations
        {--from=scrapers/sigvaris/product-data-smoke.json : Product-data or product-link JSON under storage/app.}
        {--url=* : Explicit product URL(s).}
        {--limit= : Maximum products to enumerate.}
        {--offset=0 : Product offset.}
        {--max-requests-per-product=1000 : Hard refresh-request limit per product.}
        {--timeout=30 : HTTP request timeout.}
        {--attempts=5 : Maximum request attempts.}
        {--retry-delay-ms=3000 : Retry delay.}
        {--request-delay-ms=250 : Delay before each source request.}
        {--insecure : Disable TLS verification.}
        {--no-progress : Hide progress.}
        {--json : Print JSON.}
        {--save= : Save JSON under storage/app.}
        {--show-failures : Print failed refresh requests.}';

    protected $description = 'Enumerate concrete Sigvaris PrestaShop combinations using the source AJAX refresh endpoint; no database writes.';

    public function __construct(private readonly SigvarisCombinationEnumerator $enumerator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $this->enumerator
            ->withTlsVerification(!(bool) $this->option('insecure'))
            ->withTimeout($this->intOption('timeout', 30, 1))
            ->withAttempts($this->intOption('attempts', 5, 1))
            ->withRetryDelayMilliseconds($this->intOption('retry-delay-ms', 3000, 0))
            ->withRequestDelayMilliseconds($this->intOption('request-delay-ms', 250, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->enumerator->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $records = $this->records();
        $sourceCount = count($records);
        $records = array_slice(
            $records,
            max(0, (int) $this->option('offset')),
            $this->limit(),
        );

        $products = [];
        $warnings = [];
        $failedProducts = [];
        $failedRefreshRequests = 0;
        $truncatedProducts = 0;
        $totalCombinations = 0;
        $totalRequests = 0;

        foreach ($records as $index => $record) {
            $url = (string) $record['url'];
            if (! $json && ! (bool) $this->option('no-progress')) {
                $this->line(sprintf('Combination product %d/%d: %s', $index + 1, count($records), $url));
            }

            $result = $this->enumerator->enumerate(
                $url,
                $this->intOption('max-requests-per-product', 1000, 1),
            );

            if ($result === null) {
                $failedProducts[$url] = 'Initial product request failed.';
                continue;
            }

            $result['source_category_paths'] = $record['category_paths'];
            $products[] = $result;
            $totalCombinations += (int) ($result['combination_count'] ?? 0);
            $totalRequests += (int) ($result['refresh_request_count'] ?? 0);
            $failedRefreshRequests += count($result['failed_requests'] ?? []);

            if ((bool) ($result['truncated'] ?? false)) {
                $truncatedProducts++;
            }

            foreach ($result['warnings'] ?? [] as $warning) {
                $warnings[] = ($result['name'] ?? $url).': '.$warning;
            }
        }

        $result = [
            'source' => 'sigvaris',
            'platform' => 'prestashop',
            'source_product_count' => $sourceCount,
            'selected_product_count' => count($records),
            'enumerated_product_count' => count($products),
            'combination_count' => $totalCombinations,
            'refresh_request_count' => $totalRequests,
            'failed_refresh_request_count' => $failedRefreshRequests,
            'truncated_product_count' => $truncatedProducts,
            'products' => $products,
            'warnings' => $warnings,
            'failed_products' => $failedProducts,
            'database_writes' => false,
        ];

        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->info('Sigvaris PrestaShop combination enumeration');
            $this->line('Source products: '.$sourceCount);
            $this->line('Selected products: '.count($records));
            $this->line('Enumerated products: '.count($products));
            $this->line('Concrete combinations: '.$totalCombinations);
            $this->line('Refresh requests: '.$totalRequests);
            $this->line('Failed refresh requests: '.$failedRefreshRequests);
            $this->line('Truncated products: '.$truncatedProducts);
            $this->line('Database writes: NO');
            foreach ($products as $product) {
                $this->line('- '.($product['name'] ?? 'Unnamed')
                    .' | product_id='.($product['external_product_id'] ?? '?')
                    .' | combinations='.($product['combination_count'] ?? 0)
                    .' | requests='.($product['refresh_request_count'] ?? 0)
                    .' | truncated='.(($product['truncated'] ?? false) ? 'YES' : 'NO'));
            }
        }

        if ((bool) $this->option('show-failures')) {
            foreach ($failedProducts as $url => $reason) {
                $this->warn($url.' - '.$reason);
            }
            foreach ($products as $product) {
                foreach ($product['failed_requests'] ?? [] as $signature => $reason) {
                    $this->warn(($product['name'] ?? $product['source_url'] ?? 'product').' | '.$signature.' - '.$reason);
                }
            }
        }

        $this->save($result, $json);

        return $failedProducts === [] && $failedRefreshRequests === 0 && $truncatedProducts === 0 && count($products) > 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<int,array{url:string,category_paths:array<int,mixed>}> */
    private function records(): array
    {
        $urls = array_values(array_filter(array_map(
            static fn (mixed $url): string => trim((string) $url),
            $this->option('url'),
        )));

        if ($urls !== []) {
            return array_map(
                static fn (string $url): array => ['url' => $url, 'category_paths' => []],
                $urls,
            );
        }

        $data = $this->loadJson((string) $this->option('from'));
        $records = [];

        foreach ($data['products'] ?? [] as $product) {
            if (! is_array($product)) {
                continue;
            }
            $url = trim((string) ($product['source_url'] ?? $product['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $records[$url] = [
                'url' => $url,
                'category_paths' => array_values(is_array($product['source_category_paths'] ?? null)
                    ? $product['source_category_paths']
                    : (is_array($product['category_paths'] ?? null) ? $product['category_paths'] : [])),
            ];
        }

        foreach ($data['product_urls'] ?? [] as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $records[$url] ??= ['url' => trim($url), 'category_paths' => []];
        }

        return array_values($records);
    }

    private function intOption(string $name, int $default, int $min): int
    {
        $value = $this->option($name);
        return is_numeric($value) ? max($min, (int) $value) : $default;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');
        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    /** @return array<string,mixed> */
    private function loadJson(string $relative): array
    {
        $path = storage_path('app/'.ltrim(trim($relative), '/'));
        if (! is_file($path)) {
            throw new JsonException('Sigvaris JSON does not exist: '.$path);
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new JsonException('Sigvaris JSON must contain an object.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $result */
    private function save(array $result, bool $quiet): void
    {
        $relative = trim((string) ($this->option('save') ?? ''));
        if ($relative === '') {
            return;
        }
        $path = storage_path('app/'.ltrim($relative, '/'));
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Unable to create Sigvaris scraper directory.');
        }
        if (file_put_contents($path, $this->encode($result).PHP_EOL) === false) {
            throw new RuntimeException('Unable to save Sigvaris combination data.');
        }
        if (! $quiet) {
            $this->info('Saved combination enumeration to storage/app/'.ltrim($relative, '/'));
        }
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode Sigvaris JSON.');
        }
        return $json;
    }
}
