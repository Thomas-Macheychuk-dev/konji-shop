<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisImportMapper;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class MapSigvarisImportCommand extends Command
{
    protected $signature = 'sigvaris:import-map
        {--products-from=scrapers/sigvaris/product-data.json : Sigvaris product-data JSON under storage/app.}
        {--combinations-from=scrapers/sigvaris/combinations.json : Sigvaris concrete-combination JSON under storage/app.}
        {--expected-products=226 : Expected frozen source product count.}
        {--expected-combinations=14990 : Expected frozen concrete PrestaShop combination count.}
        {--limit= : Maximum number of products to map.}
        {--offset=0 : Number of products to skip before mapping.}
        {--save=scrapers/sigvaris/import-map.json : Save import mapping JSON under storage/app.}
        {--json : Print the complete mapping as JSON.}
        {--show-products : Print one mapping summary line per product.}
        {--show-review : Print non-blocking review items.}';

    protected $description = 'Map frozen Sigvaris product and concrete PrestaShop combination data to Konji Shop without database writes or downloads.';

    public function __construct(private readonly SigvarisImportMapper $mapper)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $productsPath = $this->resolvePath((string) $this->option('products-from'));
        $combinationsPath = $this->resolvePath((string) $this->option('combinations-from'));
        $products = $this->loadJson($productsPath, 'product-data');
        $combinations = $this->loadJson($combinationsPath, 'combinations');
        $result = $this->mapper->mapCatalogue(
            $products,
            $combinations,
            $this->limit(),
            max(0, (int) $this->option('offset')),
        );

        $result['input_fingerprints'] = [
            'product_data_sha256' => hash_file('sha256', $productsPath),
            'combinations_sha256' => hash_file('sha256', $combinationsPath),
        ];

        $expectedProducts = max(0, (int) $this->option('expected-products'));
        $expectedCombinations = max(0, (int) $this->option('expected-combinations'));
        $sourceCombinations = array_sum(array_map(
            static fn (array $product): int => count(is_array($product['combinations'] ?? null) ? $product['combinations'] : []),
            array_values(array_filter($combinations['products'] ?? [], 'is_array')),
        ));

        if (($result['source_product_count'] ?? null) !== $expectedProducts) {
            $result['errors'][] = 'Frozen product count mismatch: expected '.$expectedProducts.', actual '.($result['source_product_count'] ?? 0).'.';
        }
        if ($sourceCombinations !== $expectedCombinations) {
            $result['errors'][] = 'Frozen concrete combination count mismatch: expected '.$expectedCombinations.', actual '.$sourceCombinations.'.';
        }
        $result['errors'] = array_values(array_unique($result['errors'] ?? []));
        $result['ready_for_local_import_implementation'] = ($result['mapped_product_count'] ?? 0) > 0 && $result['errors'] === [];

        $json = (bool) $this->option('json');
        if ($json) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result, $productsPath, $combinationsPath, $sourceCombinations);
        }

        $save = trim((string) $this->option('save'));
        if ($save !== '') {
            $this->saveJson($save, $result, $json);
        }

        return ($result['ready_for_local_import_implementation'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');
        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    /** @return array<string,mixed> */
    private function loadJson(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new JsonException('Sigvaris '.$label.' JSON does not exist: '.$path);
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_array($decoded['products'] ?? null)) {
            throw new JsonException('Sigvaris '.$label.' JSON does not contain a products array.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $result */
    private function printSummary(array $result, string $productsPath, string $combinationsPath, int $sourceCombinations): void
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $this->info('Sigvaris import mapping dry-run');
        $this->line('Product source: '.$productsPath);
        $this->line('Combination source: '.$combinationsPath);
        $this->line('Product-data SHA-256: '.($result['input_fingerprints']['product_data_sha256'] ?? '?'));
        $this->line('Combinations SHA-256: '.($result['input_fingerprints']['combinations_sha256'] ?? '?'));
        $this->line('Database writes: NO');
        $this->line('Images downloaded: NO');
        $this->line('Source products: '.($result['source_product_count'] ?? 0));
        $this->line('Mapped products: '.($result['mapped_product_count'] ?? 0));
        $this->line('Concrete PrestaShop combinations: '.$sourceCombinations);
        $this->line('Planned Konji variants: '.($summary['planned_variants'] ?? 0));
        $this->line('Stable default variants: '.($summary['stable_default_variants'] ?? 0));
        $this->line('Unique planned variant IDs: '.($summary['unique_planned_variant_ids'] ?? 0));
        $this->line('Images mapped: '.($summary['images'] ?? 0));
        $this->line('Downloads mapped: '.($summary['downloads'] ?? 0));
        $this->line('Distinct category paths: '.($summary['distinct_category_paths'] ?? 0));
        $this->line('VAT breakdown: '.json_encode($summary['vat_breakdown'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Hard mapping errors: '.count($result['errors'] ?? []));
        $this->line('Review items: '.count($result['review_items'] ?? []));

        if ((bool) $this->option('show-products')) {
            $this->newLine();
            $this->info('Mapped products:');
            foreach ($result['products'] ?? [] as $index => $mapped) {
                if (! is_array($mapped)) {
                    continue;
                }
                $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
                $tax = is_array($mapped['tax'] ?? null) ? $mapped['tax'] : [];
                $this->line(sprintf(
                    '%03d. %s | ID=%s | variants=%d | images=%d | docs=%d | categories=%d | VAT=%s%%',
                    $index + 1,
                    $product['name'] ?? 'Unnamed Sigvaris product',
                    $product['external_id'] ?? 'missing',
                    count($mapped['variants'] ?? []),
                    count($mapped['images'] ?? []),
                    count($mapped['downloads'] ?? []),
                    count($mapped['categories'] ?? []),
                    $tax['vat_rate'] ?? '?',
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
            $this->info('PASS: Sigvaris mapping is structurally ready for local import implementation.');
        } else {
            $this->error('FAIL: resolve hard mapping errors before implementing product creation.');
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        return str_starts_with($path, '/') ? $path : storage_path('app/'.ltrim($path, '/'));
    }

    /** @param array<string,mixed> $data */
    private function saveJson(string $path, array $data, bool $quiet): void
    {
        $path = str_starts_with($path, '/') ? $path : storage_path('app/'.ltrim($path, '/'));
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Sigvaris import-map directory: '.$directory);
        }
        if (file_put_contents($path, $this->encode($data).PHP_EOL) === false) {
            throw new RuntimeException('Unable to save Sigvaris import-map JSON.');
        }
        if (! $quiet) {
            $this->info('Saved import mapping to '.$path);
        }
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Sigvaris import mapping JSON.');
        }
        return $encoded;
    }
}
