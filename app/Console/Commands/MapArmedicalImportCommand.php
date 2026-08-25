<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Armedical\ArmedicalImportMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class MapArmedicalImportCommand extends Command
{
    private const FROZEN_V3_SHA256 = '05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8';

    protected $signature = 'armedical:import-map
        {--from=scrapers/armedical/product-data-full-v3.json : Frozen ARmedical product-data JSON on the local filesystem disk.}
        {--expected-sha256=05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8 : Expected frozen input SHA-256; empty disables the fingerprint gate.}
        {--expected-products=200 : Expected source product count.}
        {--expected-options=383 : Expected source table-option row count.}
        {--expected-images=962 : Expected source image count.}
        {--expected-documents=336 : Expected source document count.}
        {--limit= : Maximum number of products to map.}
        {--offset=0 : Number of products to skip before mapping.}
        {--save=scrapers/armedical/import-map.json : Save import mapping JSON on the local filesystem disk.}
        {--json : Print the complete mapping as JSON.}
        {--show-products : Print one mapping summary line per product.}
        {--show-review : Print review and blocking-review items.}';

    protected $description = 'Map the frozen ARmedical catalogue to a Konji Shop import plan without database writes, downloads, inferred prices, or inferred VAT.';

    public function __construct(private readonly ArmedicalImportMapper $mapper)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $relativePath = ltrim(trim((string) $this->option('from')), '/');
        $raw = $this->loadRawJson($relativePath);
        $catalogue = $this->decodeCatalogue($raw, $relativePath);
        $result = $this->mapper->mapCatalogue(
            $catalogue,
            $this->limit(),
            max(0, (int) $this->option('offset')),
        );

        $fingerprint = hash('sha256', $raw);
        $expectedFingerprint = strtolower(trim((string) $this->option('expected-sha256')));
        $sourceProducts = $this->records($catalogue['products'] ?? []);
        $sourceOptionCount = array_sum(array_map(
            static fn (array $product): int => count(is_array($product['size_options'] ?? null) ? $product['size_options'] : []),
            $sourceProducts,
        ));
        $sourceImageCount = array_sum(array_map(
            static fn (array $product): int => count(is_array($product['images'] ?? null) ? $product['images'] : []),
            $sourceProducts,
        ));
        $sourceDocumentCount = array_sum(array_map(
            static fn (array $product): int => count(is_array($product['documents'] ?? null) ? $product['documents'] : []),
            $sourceProducts,
        ));

        $result['input_fingerprint'] = [
            'sha256' => $fingerprint,
            'expected_sha256' => $expectedFingerprint !== '' ? $expectedFingerprint : null,
            'matches_expected' => $expectedFingerprint === '' || hash_equals($expectedFingerprint, $fingerprint),
            'frozen_v3_sha256' => self::FROZEN_V3_SHA256,
        ];
        $result['source_invariants'] = [
            'products' => count($sourceProducts),
            'table_option_rows' => $sourceOptionCount,
            'images' => $sourceImageCount,
            'documents' => $sourceDocumentCount,
        ];

        $this->applyInvariantGate($result, 'products', max(0, (int) $this->option('expected-products')));
        $this->applyInvariantGate($result, 'table_option_rows', max(0, (int) $this->option('expected-options')));
        $this->applyInvariantGate($result, 'images', max(0, (int) $this->option('expected-images')));
        $this->applyInvariantGate($result, 'documents', max(0, (int) $this->option('expected-documents')));

        if ($expectedFingerprint !== '' && ! hash_equals($expectedFingerprint, $fingerprint)) {
            $result['errors'][] = 'Frozen input SHA-256 mismatch: expected '.$expectedFingerprint.', actual '.$fingerprint.'.';
        }

        $result['errors'] = array_values(array_unique($result['errors'] ?? []));
        $result['mapping_structurally_valid'] = ($result['mapped_product_count'] ?? 0) > 0 && $result['errors'] === [];
        $result['ready_for_local_import_implementation'] = ($result['mapping_structurally_valid'] ?? false) === true
            && ($result['blocking_review_items'] ?? []) === [];
        $result['ready_for_database_write'] = ($result['ready_for_local_import_implementation'] ?? false) === true
            && (($result['summary']['products_without_price'] ?? 0) === 0)
            && (($result['summary']['products_without_vat'] ?? 0) === 0);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result, Storage::disk('local')->path($relativePath));
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

    private function loadRawJson(string $relativePath): string
    {
        if ($relativePath === '' || ! Storage::disk('local')->exists($relativePath)) {
            throw new RuntimeException('ARmedical product-data JSON not found on local filesystem disk: '.$relativePath);
        }

        return Storage::disk('local')->get($relativePath);
    }

    /** @return array<string, mixed> */
    private function decodeCatalogue(string $raw, string $relativePath): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid ARmedical JSON at '.$relativePath.': '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'armedical' || ! is_array($decoded['products'] ?? null)) {
            throw new RuntimeException('ARmedical JSON must contain source="armedical" and a products array.');
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
        $fingerprint = is_array($result['input_fingerprint'] ?? null) ? $result['input_fingerprint'] : [];

        $this->info('ARmedical import mapping dry-run');
        $this->line('Source: '.$sourcePath);
        $this->line('Input SHA-256: '.($fingerprint['sha256'] ?? '?'));
        $this->line('Frozen SHA gate: '.(($fingerprint['matches_expected'] ?? false) ? 'PASS' : 'FAIL'));
        $this->line('Database writes: NO');
        $this->line('Images downloaded: NO');
        $this->line('Source products: '.($invariants['products'] ?? 0));
        $this->line('Mapped products: '.($result['mapped_product_count'] ?? 0));
        $this->line('Products with source option matrices: '.($summary['products_with_table_options'] ?? 0));
        $this->line('Source table options: '.($invariants['table_option_rows'] ?? 0));
        $this->line('Default-only products: '.($summary['default_only_products'] ?? 0));
        $this->line('Planned Konji variants: '.($summary['planned_variants'] ?? 0));
        $this->line('Unique planned variant IDs: '.($summary['unique_planned_variant_ids'] ?? 0));
        $this->line('Images mapped: '.($invariants['images'] ?? 0));
        $this->line('Documents mapped: '.($invariants['documents'] ?? 0));
        $this->line('Distinct category paths: '.($summary['distinct_category_paths'] ?? 0));
        $this->line('Medical devices: '.($summary['medical_devices'] ?? 0));
        $this->line('Products without source price: '.($summary['products_without_price'] ?? 0));
        $this->line('Products without source VAT: '.($summary['products_without_vat'] ?? 0));
        $this->line('Hard mapping errors: '.count($result['errors'] ?? []));
        $this->line('Blocking review items: '.count($result['blocking_review_items'] ?? []));
        $this->line('Review items: '.count($result['review_items'] ?? []));
        $this->line('Ready for database write: '.(($result['ready_for_database_write'] ?? false) ? 'YES' : 'NO'));

        if ((bool) $this->option('show-products')) {
            $this->newLine();
            $this->info('Mapped products:');

            foreach ($result['products'] ?? [] as $index => $mapped) {
                if (! is_array($mapped)) {
                    continue;
                }

                $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
                $this->line(sprintf(
                    '%03d. %s | catalogue=%s | variants=%d | images=%d | docs=%d | categories=%d',
                    $index + 1,
                    $product['name'] ?? 'Unnamed ARmedical product',
                    $product['catalogue_number'] ?? 'missing',
                    count($mapped['variants'] ?? []),
                    count($mapped['images'] ?? []),
                    count($mapped['documents'] ?? []),
                    count($mapped['categories'] ?? []),
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

        $this->newLine();
        if (($result['mapping_structurally_valid'] ?? false) !== true) {
            $this->error('FAIL: resolve hard mapping errors before implementing ARmedical product creation.');
        } elseif (($result['ready_for_database_write'] ?? false) === true) {
            $this->info('PASS: ARmedical mapping is structurally complete and database-write prerequisites are resolved.');
        } else {
            $this->warn('PASS WITH REVIEW: mapping is structurally complete; database writes remain blocked by source-data review/pricing prerequisites.');
        }
    }

    private function saveJson(string $relativePath, array $result, bool $quiet): void
    {
        $relativePath = ltrim(trim($relativePath), '/');

        if ($relativePath === '') {
            throw new RuntimeException('ARmedical import-map save path cannot be empty.');
        }

        Storage::disk('local')->put($relativePath, $this->encode($result));

        if (! $quiet) {
            $this->info('Saved import mapping to '.Storage::disk('local')->path($relativePath));
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
            throw new RuntimeException('Unable to encode ARmedical import mapping JSON.');
        }

        return $encoded;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
