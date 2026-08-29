<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Neoxmed\NeoxmedImportDatabaseAudit;
use App\Services\Neoxmed\NeoxmedImportMapper;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class MapNeoxmedImportCommand extends Command
{
    protected $signature = 'neoxmed:import-map
        {--from=scrapers/neoxmed/product-data.json : Frozen NeoxMed product-data JSON on the local filesystem disk.}
        {--expected-products=75 : Expected source product count.}
        {--expected-product-images=76 : Expected normal product image count.}
        {--expected-size-charts=80 : Expected size-chart image count.}
        {--limit= : Maximum number of products to map.}
        {--offset=0 : Number of products to skip before mapping.}
        {--save=scrapers/neoxmed/import-map.json : Save import mapping JSON on the local filesystem disk.}
        {--skip-database-audit : Skip the read-only current-database collision/category audit.}
        {--json : Print complete mapping JSON.}
        {--show-products : Print one mapping summary line per product.}
        {--show-review : Print review and blocking-review items.}
        {--show-database : Print database collision/category audit details.}';

    protected $description = 'Map the frozen NeoxMed catalogue to a read-only Konji import plan without product writes, image downloads, inferred prices, inferred VAT, or inferred size variants.';

    public function __construct(
        private readonly NeoxmedImportMapper $mapper,
        private readonly NeoxmedImportDatabaseAudit $databaseAudit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $relativePath = ltrim(trim((string) $this->option('from')), '/');
        $catalogue = $this->loadCatalogue($relativePath);
        $result = $this->mapper->mapCatalogue(
            $catalogue,
            $this->limit(),
            max(0, (int) $this->option('offset')),
        );

        $sourceProducts = $this->records($catalogue['products'] ?? []);
        $sourceProductImages = array_sum(array_map(
            static fn (array $product): int => count(is_array($product['images'] ?? null) ? $product['images'] : []),
            $sourceProducts,
        ));
        $sourceSizeCharts = array_sum(array_map(
            static fn (array $product): int => count(is_array($product['size_chart_images'] ?? null) ? $product['size_chart_images'] : []),
            $sourceProducts,
        ));

        $result['source_invariants'] = [
            'products' => count($sourceProducts),
            'product_images' => $sourceProductImages,
            'size_chart_images' => $sourceSizeCharts,
        ];

        if ($this->limit() === null && max(0, (int) $this->option('offset')) === 0) {
            $this->applyInvariantGate($result, 'products', max(0, (int) $this->option('expected-products')));
            $this->applyInvariantGate($result, 'product_images', max(0, (int) $this->option('expected-product-images')));
            $this->applyInvariantGate($result, 'size_chart_images', max(0, (int) $this->option('expected-size-charts')));
        }

        if (! (bool) $this->option('skip-database-audit')) {
            $result['database_audit'] = $this->databaseAudit->audit($result);
            foreach ($result['database_audit']['errors'] ?? [] as $error) {
                $result['errors'][] = 'Database audit: '.$error;
            }
        } else {
            $result['database_audit'] = [
                'database_writes' => false,
                'skipped' => true,
                'errors' => [],
            ];
        }

        $result['errors'] = array_values(array_unique($result['errors'] ?? []));
        $result['mapping_structurally_valid'] = ($result['mapped_product_count'] ?? 0) > 0 && $result['errors'] === [];
        $result['ready_for_local_import_implementation'] = ($result['mapping_structurally_valid'] ?? false) === true;
        $result['ready_for_database_write'] = false;

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result, $this->storageAppPath($relativePath));
        }

        $save = trim((string) $this->option('save'));
        if ($save !== '') {
            $this->saveJson($save, $result, (bool) $this->option('json'));
        }

        return ($result['mapping_structurally_valid'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    /** @return array<string, mixed> */
    private function loadCatalogue(string $relativePath): array
    {
        $path = $this->storageAppPath($relativePath);

        if (! is_file($path)) {
            throw new RuntimeException('NeoxMed product-data JSON not found under storage/app: '.$relativePath);
        }

        try {
            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw new RuntimeException('Unable to read NeoxMed product-data JSON: '.$path);
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid NeoxMed JSON at '.$path.': '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'neoxmed' || ! is_array($decoded['products'] ?? null)) {
            throw new RuntimeException('NeoxMed JSON must contain source="neoxmed" and a products array.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private function applyInvariantGate(array &$result, string $key, int $expected): void
    {
        $actual = (int) ($result['source_invariants'][$key] ?? 0);

        if ($actual !== $expected) {
            $result['errors'][] = 'Frozen '.$key.' mismatch: expected '.$expected.', actual '.$actual.'.';
        }
    }

    /** @param array<string, mixed> $result */
    private function printSummary(array $result, string $sourcePath): void
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $invariants = is_array($result['source_invariants'] ?? null) ? $result['source_invariants'] : [];
        $database = is_array($result['database_audit']['summary'] ?? null) ? $result['database_audit']['summary'] : [];

        $this->info('NeoxMed import mapping dry-run');
        $this->line('Source: '.$sourcePath);
        $this->line('Database writes: NO');
        $this->line('Images downloaded: NO');
        $this->line('Source products: '.($invariants['products'] ?? 0));
        $this->line('Mapped products: '.($result['mapped_product_count'] ?? 0));
        $this->line('Planned safe placeholder variants: '.($summary['planned_variants'] ?? 0));
        $this->line('Source product images: '.($invariants['product_images'] ?? 0));
        $this->line('Mapped product images: '.($summary['product_images'] ?? 0));
        $this->line('Source size-chart images: '.($invariants['size_chart_images'] ?? 0));
        $this->line('Mapped size-chart images: '.($summary['size_chart_images'] ?? 0));
        $this->line('Products with NFZ codes: '.($summary['products_with_nfz_codes'] ?? 0));
        $this->line('Unique NFZ codes: '.($summary['unique_nfz_codes'] ?? 0));
        $this->line('Products without source price: '.($summary['products_without_price'] ?? 0));
        $this->line('Products without source VAT: '.($summary['products_without_vat'] ?? 0));
        $this->line('Distinct source category paths: '.($summary['distinct_category_paths'] ?? 0));
        $this->line('Hard mapping errors: '.count($result['errors'] ?? []));
        $this->line('Blocking review items: '.count($result['blocking_review_items'] ?? []));
        $this->line('Ready for database write: NO');

        if (($result['database_audit']['skipped'] ?? false) !== true) {
            $this->newLine();
            $this->info('Read-only database audit:');
            $this->line('Existing NeoxMed products: '.($database['existing_neoxmed_products'] ?? 0));
            $this->line('Cross-source external ID overlaps: '.($database['external_id_overlaps_other_sources'] ?? 0));
            $this->line('Slug collisions: '.($database['slug_collisions'] ?? 0));
            $this->line('Variant SKU collisions: '.($database['variant_sku_collisions'] ?? 0));
            $this->line('Matched category slugs: '.($database['matched_category_slugs'] ?? 0));
            $this->line('Unmatched category slugs: '.($database['unmatched_category_slugs'] ?? 0));
        }

        if ((bool) $this->option('show-products')) {
            $this->newLine();
            $this->info('Mapped products:');
            foreach ($result['products'] ?? [] as $index => $mapped) {
                if (! is_array($mapped)) {
                    continue;
                }

                $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
                $this->line(sprintf(
                    '%03d. %s | source=%s | sku=%s | nfz=%d | images=%d | size-charts=%d',
                    $index + 1,
                    $product['name'] ?? 'Unnamed NeoxMed product',
                    $product['external_id'] ?? 'missing',
                    $mapped['variants'][0]['sku'] ?? 'missing',
                    count($mapped['nfz']['codes'] ?? []),
                    count($mapped['images'] ?? []),
                    count($mapped['sizing']['size_chart_images'] ?? []),
                ));
            }
        }

        if (($result['errors'] ?? []) !== []) {
            $this->newLine();
            $this->error('Hard mapping errors:');
            foreach ($result['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        if ((bool) $this->option('show-review')) {
            if (($result['blocking_review_items'] ?? []) !== []) {
                $this->newLine();
                $this->warn('Blocking review items:');
                foreach ($result['blocking_review_items'] as $item) {
                    $this->line('- '.$item);
                }
            }

            if (($result['review_items'] ?? []) !== []) {
                $this->newLine();
                $this->warn('Review items:');
                foreach ($result['review_items'] as $item) {
                    $this->line('- '.$item);
                }
            }
        }

        if ((bool) $this->option('show-database') && is_array($result['database_audit'] ?? null)) {
            $audit = $result['database_audit'];
            $this->newLine();
            $this->info('Database audit details:');
            foreach (['external_id_overlaps_other_sources', 'slug_collisions', 'variant_sku_collisions', 'unmatched_category_slugs'] as $key) {
                $this->line($key.': '.json_encode($audit[$key] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        $this->newLine();
        if (($result['mapping_structurally_valid'] ?? false) !== true) {
            $this->error('FAIL: resolve hard mapping/database-audit errors before implementing NeoxMed product creation.');
        } else {
            $this->warn('PASS WITH REVIEW: structural mapping is complete; price and VAT remain hard blockers for database writes.');
        }
    }

    /** @param array<string, mixed> $result */
    private function saveJson(string $relativePath, array $result, bool $quiet): void
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '') {
            throw new RuntimeException('NeoxMed import-map save path cannot be empty.');
        }

        $path = $this->storageAppPath($relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create NeoxMed import-map directory: '.$directory);
        }

        if (file_put_contents($path, $this->encode($result), LOCK_EX) === false) {
            throw new RuntimeException('Unable to save NeoxMed import mapping JSON: '.$path);
        }

        if (! $quiet) {
            $this->info('Saved import mapping to '.$path);
        }
    }

    private function storageAppPath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));

        if ($relativePath === '') {
            throw new RuntimeException('NeoxMed storage/app relative path cannot be empty.');
        }

        if (in_array('..', explode('/', $relativePath), true)) {
            throw new RuntimeException('NeoxMed storage/app relative path cannot contain parent-directory traversal.');
        }

        return storage_path('app/'.$relativePath);
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode NeoxMed import mapping JSON.');
        }

        return $encoded;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
