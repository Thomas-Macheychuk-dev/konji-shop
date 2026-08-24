<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Sigvaris\SigvarisSizeChartRepair;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class RepairSigvarisSizeChartsCommand extends Command
{
    protected $signature = 'sigvaris:repair-size-charts
        {--from=scrapers/sigvaris/import-map.json : Approved Sigvaris import-map JSON path. Relative paths resolve under storage/app.}
        {--expected-sha256= : Approved SHA-256 fingerprint of the exact import map. Required for writes.}
        {--limit= : Maximum number of mapped products to inspect.}
        {--offset=0 : Number of mapped products to skip.}
        {--write : Download discovered size-chart images and update Sigvaris product descriptions.}
        {--confirm-production-write= : Required in production; must equal REPAIR-SIGVARIS-SIZE-CHARTS.}
        {--timeout=20 : Source product page timeout in seconds.}
        {--attempts=3 : Source product page request attempts.}
        {--retry-delay-ms=1500 : Delay between source page retry attempts.}
        {--request-delay-ms=300 : Delay before source product page requests.}
        {--asset-timeout=30 : Size-chart image timeout in seconds.}
        {--asset-attempts=3 : Size-chart image request attempts.}
        {--asset-retry-delay-ms=1500 : Delay between size-chart image retries.}
        {--insecure : Disable TLS certificate verification for source and asset requests.}
        {--show-missing : Print products for which no linked size-chart image was discovered.}
        {--show-failures : Print discovery/write failures.}
        {--save= : Save the evidence JSON under storage/app.}';

    protected $description = 'Recover Sigvaris TABELA ROZMIARÓW image links; read-only unless --write is supplied.';

    public function __construct(
        private readonly SigvarisSizeChartRepair $repair,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('from'));
        $map = $this->loadJson($path);

        if ($map === null) {
            return self::FAILURE;
        }

        $products = array_values(array_filter(
            $map['products'] ?? [],
            static fn (mixed $record): bool => is_array($record),
        ));
        $offset = max(0, (int) $this->option('offset'));
        $limitRaw = $this->option('limit');
        $limit = is_numeric($limitRaw) && (int) $limitRaw > 0 ? (int) $limitRaw : null;
        $selected = array_slice($products, $offset, $limit);
        $actualSha = hash_file('sha256', $path);
        $expectedSha = strtolower(trim((string) $this->option('expected-sha256')));
        $write = (bool) $this->option('write');
        $verifyTls = ! (bool) $this->option('insecure');

        $this->info('Sigvaris size-chart recovery');
        $this->line('Environment: '.app()->environment());
        $this->line('Source: '.$path);
        $this->line('Import-map SHA-256: '.$actualSha);
        $this->line('Available mapped products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selected));
        $this->line('Database writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Size-chart asset writes: '.($write ? 'REQUESTED' : 'NO'));

        if ($selected === []) {
            $this->warn('No products selected.');

            return self::SUCCESS;
        }

        if ($write) {
            if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha) || ! hash_equals($expectedSha, $actualSha)) {
                $this->error('BLOCKED: --write requires the exact approved --expected-sha256 fingerprint.');

                return self::FAILURE;
            }

            if (app()->environment('production')
                && (string) $this->option('confirm-production-write') !== 'REPAIR-SIGVARIS-SIZE-CHARTS') {
                $this->error('BLOCKED: production writes require --confirm-production-write=REPAIR-SIGVARIS-SIZE-CHARTS.');

                return self::FAILURE;
            }
        }

        $this->repair->configureDiscovery(
            timeoutSeconds: max(1, (int) $this->option('timeout')),
            attempts: max(1, (int) $this->option('attempts')),
            retryDelayMs: max(0, (int) $this->option('retry-delay-ms')),
            requestDelayMs: max(0, (int) $this->option('request-delay-ms')),
            verifyTls: $verifyTls,
        );

        $found = 0;
        $missing = [];
        $failures = [];
        $created = 0;
        $reused = 0;
        $updated = 0;
        $evidence = [];
        $total = count($selected);

        foreach ($selected as $index => $mapped) {
            $productData = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $externalId = trim((string) ($productData['external_id'] ?? ''));
            $name = trim((string) ($productData['name'] ?? '')) ?: 'Unnamed Sigvaris product';
            $sourceUrl = trim((string) ($mapped['source_url'] ?? $mapped['canonical_url'] ?? ''));
            $this->line(sprintf('Inspecting %d/%d: %s | external_id=%s', $index + 1, $total, $name, $externalId ?: '?'));

            if ($externalId === '' || $sourceUrl === '') {
                $failures[] = ['external_id' => $externalId, 'name' => $name, 'error' => 'Missing mapped external ID or source URL.'];
                continue;
            }

            try {
                $chart = $this->repair->discover($sourceUrl);
            } catch (Throwable $exception) {
                $failures[] = ['external_id' => $externalId, 'name' => $name, 'error' => $exception->getMessage()];
                continue;
            }

            if ($chart === null) {
                $missing[] = ['external_id' => $externalId, 'name' => $name, 'source_url' => $sourceUrl];
                $evidence[] = ['external_id' => $externalId, 'name' => $name, 'source_url' => $sourceUrl, 'status' => 'no_linked_image'];
                continue;
            }

            $found++;
            $row = [
                'external_id' => $externalId,
                'name' => $name,
                'source_url' => $sourceUrl,
                'size_chart_url' => $chart['url'],
                'status' => 'discovered',
            ];

            if ($write) {
                $product = Product::query()
                    ->where('external_source', 'sigvaris')
                    ->where('external_id', $externalId)
                    ->first();

                if (! $product instanceof Product) {
                    $failures[] = ['external_id' => $externalId, 'name' => $name, 'error' => 'Production/local Sigvaris product row not found.'];
                    continue;
                }

                if ($product->status !== ProductStatus::DRAFT || $product->published_at !== null) {
                    $failures[] = ['external_id' => $externalId, 'name' => $name, 'error' => 'Size-chart repair refuses non-draft or published Sigvaris products.'];
                    continue;
                }

                try {
                    $result = $this->repair->repair(
                        product: $product,
                        chart: $chart,
                        assetTimeoutSeconds: max(1, (int) $this->option('asset-timeout')),
                        assetAttempts: max(1, (int) $this->option('asset-attempts')),
                        assetRetryDelayMs: max(0, (int) $this->option('asset-retry-delay-ms')),
                        verifyTls: $verifyTls,
                    );
                    $updated++;
                    $result['action'] === 'created' ? $created++ : $reused++;
                    $row['status'] = 'repaired';
                    $row['asset_action'] = $result['action'];
                    $row['local_path'] = $result['path'];
                    $row['href'] = $result['href'];
                    $this->info('  REPAIRED: '.$result['href']);
                } catch (Throwable $exception) {
                    $failures[] = ['external_id' => $externalId, 'name' => $name, 'error' => $exception->getMessage()];
                    $row['status'] = 'failed';
                    $row['error'] = $exception->getMessage();
                }
            } else {
                $this->info('  FOUND: '.$chart['url']);
            }

            $evidence[] = $row;
        }

        $this->newLine();
        $this->info('=== SIGVARIS SIZE-CHART RESULT ===');
        $this->line('Selected products: '.count($selected));
        $this->line('Linked size-chart images discovered: '.$found);
        $this->line('Products without a linked size-chart image: '.count($missing));
        $this->line('Discovery/write failures: '.count($failures));
        $this->line('Database descriptions updated: '.$updated);
        $this->line('Size-chart image files created: '.$created);
        $this->line('Existing size-chart image files reused: '.$reused);

        if ((bool) $this->option('show-missing') && $missing !== []) {
            $this->newLine();
            $this->warn('Products without linked size-chart images:');
            foreach ($missing as $item) {
                $this->line('- '.$item['external_id'].' | '.$item['name']);
            }
        }

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->newLine();
            $this->error('Failures:');
            foreach ($failures as $failure) {
                $this->line('- '.($failure['external_id'] ?: '?').' | '.$failure['name'].' | '.$failure['error']);
            }
        }

        $save = trim((string) $this->option('save'));
        if ($save !== '') {
            $savePath = $this->resolvePath($save);
            @mkdir(dirname($savePath), 0755, true);
            file_put_contents($savePath, json_encode([
                'source' => 'sigvaris',
                'map_sha256' => $actualSha,
                'write' => $write,
                'selected_products' => count($selected),
                'discovered_size_charts' => $found,
                'missing_size_charts' => $missing,
                'failures' => $failures,
                'updated_products' => $updated,
                'asset_files_created' => $created,
                'asset_files_reused' => $reused,
                'products' => $evidence,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line('Saved evidence JSON to '.$savePath);
        }

        if ($failures !== []) {
            $this->error('FAIL: size-chart recovery has failures.');

            return self::FAILURE;
        }

        $this->info($write
            ? 'PASS: discovered Sigvaris size charts were linked to local image assets on draft products.'
            : 'PASS: read-only Sigvaris size-chart discovery completed. No catalogue or asset writes were performed.');

        return self::SUCCESS;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);

        return str_starts_with($value, '/') ? $value : storage_path('app/'.$value);
    }

    /** @return array<string,mixed>|null */
    private function loadJson(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('JSON file does not exist: '.$path);

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded)) {
            $this->error('JSON root must be an object.');

            return null;
        }

        return $decoded;
    }
}
