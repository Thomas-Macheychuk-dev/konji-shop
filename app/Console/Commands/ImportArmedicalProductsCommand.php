<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Armedical\ArmedicalProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class ImportArmedicalProductsCommand extends Command
{
    public const APPROVED_PRICED_MAP_SHA256 = '9617b3d1a5d549c7b590ea6c252cd0ded430cf1a31571bb8853c6dbe20a2ad20';

    private const APPROVED_SOURCE_PRODUCTS = 200;

    private const APPROVED_PLANNED_VARIANTS = 506;

    private const APPROVED_ELIGIBLE_PRODUCTS = 187;

    private const APPROVED_ELIGIBLE_VARIANTS = 459;

    private const APPROVED_EXCLUDED_PRODUCTS = 13;

    private const APPROVED_UNMATCHED_VARIANTS = 47;

    private const APPROVED_SUPPLIER_XLS_SHA256 = 'ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893';

    protected $signature = 'armedical:import-products
        {--from=scrapers/armedical/import-map-priced.json : Priced ARmedical import-map JSON. Relative paths resolve under storage/app.}
        {--write : Perform local database writes for the fully priced cohort. Without this flag the command is read-only.}
        {--limit= : Maximum number of eligible products to process.}
        {--offset=0 : Number of eligible products to skip before processing.}
        {--show-excluded : Print products excluded because one or more variants remain unresolved.}
        {--show-review : Print review items attached to the priced map.}
        {--show-failures : Print failed product imports.}';

    protected $description = 'Import only the frozen fully priced ARmedical cohort as local draft products; media remains untouched.';

    public function __construct(
        private readonly ArmedicalProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('from'));
        $map = $this->loadMap($path);

        if ($map === null) {
            return self::FAILURE;
        }

        $products = array_values(array_filter(
            $map['products'] ?? [],
            static fn (mixed $product): bool => is_array($product),
        ));

        if ($products === []) {
            $this->error('No mapped ARmedical products found in: '.$path);

            return self::FAILURE;
        }

        $eligible = [];
        $excluded = [];

        foreach ($products as $product) {
            if ($this->importer->isFullyPriced($product)) {
                $eligible[] = $product;
            } else {
                $excluded[] = $product;
            }
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selected = array_slice($eligible, $offset, $limit);
        $write = (bool) $this->option('write');
        $actualSha256 = hash_file('sha256', $path);

        $this->info('ARmedical local product import');
        $this->line('Source: '.$path);
        $this->line('Priced-map SHA-256: '.$actualSha256);
        $this->line('Approved priced-map SHA-256: '.self::APPROVED_PRICED_MAP_SHA256);
        $this->line('Approved SHA gate: '.(hash_equals(self::APPROVED_PRICED_MAP_SHA256, $actualSha256) ? 'PASS' : 'FAIL'));
        $this->line('Mapped products: '.count($products));
        $this->line('Fully priced eligible products: '.count($eligible));
        $this->line('Excluded unresolved products: '.count($excluded));
        $this->line('Eligible planned variants: '.$this->variantCount($eligible));
        $this->line('Selected products: '.count($selected));
        $this->line('Selected variants: '.$this->variantCount($selected));
        $this->line('Product status: draft (forced)');
        $this->line('Variant status: draft (forced)');
        $this->line('Variant stock status: out_of_stock (conservative; source availability is unknown)');
        $this->line('Images/documents downloaded: NO');
        $this->line('Existing media rows: untouched');
        $this->line('Database writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Environment: '.app()->environment());

        $this->printExcluded($excluded);
        $this->printReviewItems($this->stringList($map['review_items'] ?? null));

        if ($selected === []) {
            $this->warn('No eligible products selected after offset/limit.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->info('Dry-run summary. No database writes were made. No media was downloaded.');
            $this->line('Products to create/update: '.count($selected));
            $this->line('Planned variants: '.$this->variantCount($selected));
            $this->line('Mapped source images preserved for later media stage: '.$this->imageCount($selected));
            $this->line('Mapped source documents preserved for later media stage: '.$this->documentCount($selected));
            $this->info('PASS: dry-run only. The unresolved cohort remains excluded from writes.');

            return self::SUCCESS;
        }

        if (! app()->environment('local', 'testing')) {
            $this->error('BLOCKED: armedical:import-products --write is allowed only in local/testing environments.');

            return self::FAILURE;
        }

        if (! hash_equals(self::APPROVED_PRICED_MAP_SHA256, $actualSha256)) {
            $this->error('BLOCKED: ARmedical priced-map SHA-256 does not match the frozen approved fingerprint.');

            return self::FAILURE;
        }

        $invariantErrors = $this->approvedMapInvariantErrors($map, $products, $eligible, $excluded);

        if ($invariantErrors !== []) {
            $this->error('BLOCKED: frozen ARmedical priced-map cohort invariants do not match the approved audit.');

            foreach ($invariantErrors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $failures = [];
        $warnings = [];
        $stats = $this->emptyStats();
        $importedProductIds = [];
        $total = count($selected);

        foreach ($selected as $index => $mappedProduct) {
            $name = $this->mappedProductName($mappedProduct);
            $externalId = $this->mappedExternalId($mappedProduct);
            $this->line(sprintf('Importing %d/%d: %s | external_id=%s', $index + 1, $total, $name, $externalId ?? '?'));

            try {
                $result = $this->importer->import($mappedProduct);
                $product = $result['product'];
                $importedProductIds[] = $product->id;

                if ($result['action'] === 'created') {
                    $created++;
                } else {
                    $updated++;
                }

                foreach ($result['stats'] as $key => $value) {
                    $stats[$key] = ($stats[$key] ?? 0) + $value;
                }

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $name.': '.$warning;
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf(
                    '  %s product ID %d | variants=%d | images=%d | categories=%d',
                    strtoupper($result['action']),
                    $product->id,
                    $product->variants->count(),
                    $product->images->count(),
                    $product->categories->count(),
                ));
            } catch (Throwable $exception) {
                $failures[] = [
                    'name' => $name,
                    'external_id' => $externalId,
                    'error' => $exception->getMessage(),
                ];
                $this->error('  FAILED: '.$exception->getMessage());
            }
        }

        $this->newLine();
        $this->info('=== ARMEDICAL LOCAL IMPORT RESULT ===');
        $this->line('Products created: '.$created);
        $this->line('Products updated: '.$updated);
        $this->line('Warnings: '.count($warnings));
        $this->line('Failures: '.count($failures));
        $this->line('Variants created: '.$stats['variants_created']);
        $this->line('Variants updated: '.$stats['variants_updated']);
        $this->line('Variants archived: '.$stats['variants_archived']);
        $this->line('Categories created: '.$stats['categories_created']);
        $this->line('Categories reused: '.$stats['categories_reused']);
        $this->line('Media downloads: 0');

        $this->printDatabaseAudit($importedProductIds);

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->warn('Failed ARmedical imports:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' | external_id='.($failure['external_id'] ?? '?').' | '.$failure['error']);
            }
        }

        if ($failures === []) {
            $this->info('PASS: selected fully priced ARmedical products were imported locally as drafts; unresolved products and media remained untouched.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /** @param array<string, mixed> $map @param list<array<string, mixed>> $products @param list<array<string, mixed>> $eligible @param list<array<string, mixed>> $excluded @return list<string> */
    private function approvedMapInvariantErrors(array $map, array $products, array $eligible, array $excluded): array
    {
        $errors = [];
        $pricingSummary = is_array($map['pricing_summary'] ?? null) ? $map['pricing_summary'] : [];
        $supplier = is_array($map['supplier_price_list']['metadata'] ?? null) ? $map['supplier_price_list']['metadata'] : [];
        $expected = [
            'mapped products' => [count($products), self::APPROVED_SOURCE_PRODUCTS],
            'planned variants' => [(int) ($pricingSummary['planned_variants'] ?? -1), self::APPROVED_PLANNED_VARIANTS],
            'eligible products' => [count($eligible), self::APPROVED_ELIGIBLE_PRODUCTS],
            'eligible variants' => [$this->variantCount($eligible), self::APPROVED_ELIGIBLE_VARIANTS],
            'excluded products' => [count($excluded), self::APPROVED_EXCLUDED_PRODUCTS],
            'unmatched variants' => [(int) ($pricingSummary['unmatched_variants'] ?? -1), self::APPROVED_UNMATCHED_VARIANTS],
        ];

        foreach ($expected as $label => [$actual, $approved]) {
            if ($actual !== $approved) {
                $errors[] = $label.' expected '.$approved.', got '.$actual.'.';
            }
        }

        if (($pricingSummary['matched_variants'] ?? null) !== self::APPROVED_ELIGIBLE_VARIANTS) {
            $errors[] = 'pricing_summary matched_variants must be '.self::APPROVED_ELIGIBLE_VARIANTS.'.';
        }

        if (($pricingSummary['fully_priced_products'] ?? null) !== self::APPROVED_ELIGIBLE_PRODUCTS) {
            $errors[] = 'pricing_summary fully_priced_products must be '.self::APPROVED_ELIGIBLE_PRODUCTS.'.';
        }

        if (($pricingSummary['unpriced_products'] ?? null) !== self::APPROVED_EXCLUDED_PRODUCTS) {
            $errors[] = 'pricing_summary unpriced_products must be '.self::APPROVED_EXCLUDED_PRODUCTS.'.';
        }

        if (($supplier['source_sha256'] ?? null) !== self::APPROVED_SUPPLIER_XLS_SHA256) {
            $errors[] = 'supplier XLS SHA-256 does not match the approved 2026 price list.';
        }

        return $errors;
    }

    /** @param list<array<string, mixed>> $products */
    private function printDatabaseAudit(array $productIds): void
    {
        $productIds = array_values(array_unique($productIds));
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->withCount(['variants', 'images', 'categories'])
            ->get();

        $this->newLine();
        $this->info('=== SELECTED DATABASE AUDIT ===');
        $this->line('Imported product rows: '.$products->count());
        $this->line('Draft product rows: '.$products->where('status', ProductStatus::DRAFT)->count());
        $this->line('Variant rows: '.$products->sum('variants_count'));
        $this->line('Image rows: '.$products->sum('images_count'));
        $this->line('Category assignments: '.$products->sum('categories_count'));
        $this->line('All local ARmedical product rows: '.Product::query()->where('external_source', 'armedical')->count());
    }

    /** @param list<array<string, mixed>> $excluded */
    private function printExcluded(array $excluded): void
    {
        if (! (bool) $this->option('show-excluded') || $excluded === []) {
            return;
        }

        $this->newLine();
        $this->warn('Excluded unresolved ARmedical products: '.count($excluded));

        foreach ($excluded as $product) {
            $unmatched = 0;

            foreach (($product['variants'] ?? []) as $variant) {
                if (is_array($variant) && ($variant['pricing_resolution']['status'] ?? null) !== 'matched') {
                    $unmatched++;
                }
            }

            $catalogue = $this->stringOrNull($product['product']['catalogue_number'] ?? null) ?? '?';
            $this->line('- '.$catalogue.' | '.$this->mappedProductName($product).' | unresolved variants='.$unmatched);
        }
    }

    /** @param list<string> $reviewItems */
    private function printReviewItems(array $reviewItems): void
    {
        if (! (bool) $this->option('show-review') || $reviewItems === []) {
            return;
        }

        $this->newLine();
        $this->warn('Priced-map review items: '.count($reviewItems));

        foreach ($reviewItems as $item) {
            $this->line('- '.$item);
        }
    }

    /** @param list<array<string, mixed>> $products */
    private function variantCount(array $products): int
    {
        $count = 0;

        foreach ($products as $product) {
            $count += is_array($product['variants'] ?? null) ? count($product['variants']) : 0;
        }

        return $count;
    }

    /** @param list<array<string, mixed>> $products */
    private function imageCount(array $products): int
    {
        $count = 0;

        foreach ($products as $product) {
            $count += is_array($product['images'] ?? null) ? count($product['images']) : 0;
        }

        return $count;
    }

    /** @param list<array<string, mixed>> $products */
    private function documentCount(array $products): int
    {
        $count = 0;

        foreach ($products as $product) {
            $count += is_array($product['documents'] ?? null) ? count($product['documents']) : 0;
        }

        return $count;
    }

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'categories_created' => 0,
            'categories_reused' => 0,
            'variants_created' => 0,
            'variants_updated' => 0,
            'variants_archived' => 0,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadMap(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('ARmedical priced import-map file not found: '.$path);

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('ARmedical priced import-map is not valid JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'armedical') {
            $this->error('ARmedical priced import-map has an invalid source marker.');

            return null;
        }

        return $decoded;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = 'scrapers/armedical/import-map-priced.json';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $relative = ltrim($path, '/');
        $storagePath = storage_path('app/'.$relative);

        if (is_file($storagePath)) {
            return $storagePath;
        }

        $localPath = Storage::disk('local')->path($relative);

        return is_file($localPath) ? $localPath : $storagePath;
    }

    /** @param array<string, mixed> $mapped */
    private function mappedProductName(array $mapped): string
    {
        $value = $mapped['product']['name'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : '[unnamed ARmedical product]';
    }

    /** @param array<string, mixed> $mapped */
    private function mappedExternalId(array $mapped): ?string
    {
        return $this->stringOrNull($mapped['product']['external_id'] ?? null);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            $string = $this->stringOrNull($item);

            if ($string !== null) {
                $result[] = $string;
            }
        }

        return $result;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
}
