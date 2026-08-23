<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zamst\ZamstImportMapper;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class MapZamstImportCommand extends Command
{
    protected $signature = 'zamst:import-map
        {--from=scrapers/zamst/product-data.json : Zamst product-data JSON under storage/app.}
        {--limit= : Maximum number of products to map.}
        {--offset=0 : Number of products to skip before mapping.}
        {--save=scrapers/zamst/import-map.json : Save the import mapping JSON under storage/app.}
        {--json : Print the complete import mapping as JSON.}
        {--show-products : Print one mapping summary line per product.}
        {--show-review : Print VAT/content review items.}';

    protected $description = 'Map Zamst scraped data to the planned Konji Shop import contract without database writes or downloads.';

    public function __construct(
        private readonly ZamstImportMapper $mapper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourcePath = $this->resolvePath((string) $this->option('from'));
        $catalogue = $this->loadJson($sourcePath);
        $result = $this->mapper->mapCatalogue(
            $catalogue,
            $this->limit(),
            max(0, (int) $this->option('offset')),
        );
        $json = (bool) $this->option('json');

        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result, $sourcePath);
        }

        $save = trim((string) $this->option('save'));

        if ($save !== '') {
            $this->saveJson($save, $result, $json);
        }

        return ($result['ready_for_local_import_implementation'] ?? false) === true
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path): array
    {
        if (! is_file($path)) {
            throw new JsonException('Zamst product-data JSON does not exist: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! is_array($decoded['products'] ?? null)) {
            throw new JsonException('Zamst product-data JSON does not contain a products array.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private function printSummary(array $result, string $sourcePath): void
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $this->info('Zamst import mapping dry-run');
        $this->line('Source: '.$sourcePath);
        $this->line('Database writes: NO');
        $this->line('Images downloaded: NO');
        $this->line('Source products: '.($result['source_product_count'] ?? 0));
        $this->line('Selected products: '.($result['selected_product_count'] ?? 0));
        $this->line('Mapped products: '.($result['mapped_product_count'] ?? 0));
        $this->line('Unique external product IDs: '.($summary['unique_external_product_ids'] ?? 0));
        $this->line('Source WooCommerce variants: '.($summary['source_variants'] ?? 0));
        $this->line('Planned Konji variants: '.($summary['planned_variants'] ?? 0));
        $this->line('Unique planned variant IDs: '.($summary['unique_planned_external_variant_ids'] ?? 0));
        $this->line('Distinct category paths: '.($summary['distinct_category_paths'] ?? 0));
        $this->line('Manufacturer: '.($summary['manufacturer'] ?? 'Zamst'));
        $this->line('Images mapped: '.($summary['images'] ?? 0));
        $this->line('Downloads mapped: '.($summary['downloads'] ?? 0));
        $this->line('Product videos mapped: '.($summary['product_videos'] ?? 0));
        $this->line('Non-product video links filtered: '.($summary['filtered_non_product_videos'] ?? 0));
        $this->line('Products requiring VAT review: '.($summary['vat_review_products'] ?? 0));
        $this->line('Hard mapping errors: '.count($result['errors'] ?? []));
        $this->line('Review items: '.count($result['review_items'] ?? []));

        if ((bool) $this->option('show-products')) {
            $this->newLine();
            $this->info('Mapped products:');

            foreach ($result['products'] ?? [] as $index => $mapped) {
                $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
                $tax = is_array($mapped['tax'] ?? null) ? $mapped['tax'] : [];
                $this->line(sprintf(
                    '%02d. %s | ID=%s | variants=%d | images=%d | categories=%d | VAT=%s%%%s',
                    $index + 1,
                    $product['name'] ?? 'Unnamed Zamst product',
                    $product['external_id'] ?? 'missing',
                    count($mapped['variants'] ?? []),
                    count($mapped['images'] ?? []),
                    count($mapped['categories'] ?? []),
                    $tax['vat_rate'] ?? '?',
                    ($tax['requires_review'] ?? false) ? ' REVIEW' : '',
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

        if ((bool) $this->option('show-review') && ($result['review_items'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Review items:');

            foreach ($result['review_items'] as $item) {
                $this->line('- '.$item);
            }
        }

        $this->newLine();

        if (($result['ready_for_local_import_implementation'] ?? false) === true) {
            $this->info('PASS: mapping is structurally ready for the local-import implementation patch.');
        } else {
            $this->error('FAIL: resolve hard mapping errors before implementing product creation.');
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = 'scrapers/zamst/product-data.json';
        }

        return str_starts_with($path, '/') ? $path : storage_path('app/'.ltrim($path, '/'));
    }

    /** @param array<string, mixed> $data */
    private function saveJson(string $relativePath, array $data, bool $quiet): void
    {
        $path = str_starts_with($relativePath, '/')
            ? $relativePath
            : storage_path('app/'.ltrim($relativePath, '/'));
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Zamst import-map directory: '.$directory);
        }

        if (file_put_contents($path, $this->encode($data).PHP_EOL) === false) {
            throw new RuntimeException('Unable to save Zamst import-map JSON.');
        }

        if (! $quiet) {
            $this->info('Saved import mapping to '.$path);
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Zamst import mapping JSON.');
        }

        return $encoded;
    }
}
