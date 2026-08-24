<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Sigvaris\SigvarisProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class ImportSigvarisProductsCommand extends Command
{
    protected $signature = 'sigvaris:import-products
        {--from=scrapers/sigvaris/import-map.json : Sigvaris import-map JSON path. Relative paths resolve under storage/app.}
        {--write : Perform local database writes. Without this flag the command is a read-only dry-run.}
        {--expected-sha256= : Approved SHA-256 fingerprint of the exact Sigvaris import-map. Required for --write.}
        {--limit= : Maximum number of mapped products to process.}
        {--offset=0 : Number of mapped products to skip before processing.}
        {--no-images : Do not download or sync product images during --write.}
        {--image-limit=0 : Maximum images to import per product. Use 0 for no limit.}
        {--refresh-images : Re-download Sigvaris images even when the local file already exists.}
        {--image-timeout=30 : Timeout in seconds for each image request.}
        {--image-attempts=5 : Maximum attempts for each image request.}
        {--image-retry-delay-ms=3000 : Milliseconds between image retry attempts.}
        {--image-request-delay-ms=250 : Milliseconds between image downloads.}
        {--show-review : Print mapping review items.}
        {--show-failures : Print failed product imports.}';

    protected $description = 'Import validated Sigvaris mapping into local Konji Shop draft products; read-only unless --write is supplied.';

    public function __construct(
        private readonly SigvarisProductImporter $importer,
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
            $this->error('No mapped Sigvaris products found in: '.$path);

            return self::FAILURE;
        }

        $hardErrors = array_values(array_filter(
            $map['errors'] ?? [],
            static fn (mixed $error): bool => is_string($error) && trim($error) !== '',
        ));

        if (($map['ready_for_local_import_implementation'] ?? false) !== true || $hardErrors !== []) {
            $this->error('Sigvaris mapping is not structurally ready for local import.');

            foreach ($hardErrors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selected = array_slice($products, $offset, $limit);
        $write = (bool) $this->option('write');
        $reviewItems = $this->selectedReviewItems($selected);

        $this->info('Sigvaris local product import');
        $actualSha256 = hash_file('sha256', $path);
        $expectedSha256 = $this->normalizedSha256Option('expected-sha256');

        $this->line('Source: '.$path);
        $this->line('Import-map SHA-256: '.$actualSha256);
        $this->line('Available mapped products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected products: '.count($selected));
        $this->line('Product status: draft (forced)');
        $this->line('Database writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Environment: '.app()->environment());

        if ($selected === []) {
            $this->warn('No products selected after offset/limit.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->printDryRunSummary($selected);
            $this->printReviewItems($reviewItems);
            $this->info('PASS: dry-run only. Re-run with --write for an explicit local draft import.');

            return self::SUCCESS;
        }

        if (! app()->environment('local', 'testing')) {
            $this->error('BLOCKED: sigvaris:import-products --write is allowed only in local/testing environments.');

            return self::FAILURE;
        }

        if ($expectedSha256 === null) {
            $this->error('BLOCKED: --write requires --expected-sha256 with the approved Sigvaris import-map fingerprint.');

            return self::FAILURE;
        }

        if (! hash_equals($expectedSha256, $actualSha256)) {
            $this->error('BLOCKED: Sigvaris import-map SHA-256 does not match the approved fingerprint.');
            $this->line('Expected: '.$expectedSha256);
            $this->line('Actual:   '.$actualSha256);

            return self::FAILURE;
        }

        $importImages = ! (bool) $this->option('no-images');
        $imageLimit = $this->imageLimitOption();
        $refreshImages = (bool) $this->option('refresh-images');
        $imageTimeout = $this->positiveIntOption('image-timeout', 30);
        $imageAttempts = $this->positiveIntOption('image-attempts', 5);
        $imageRetryDelay = $this->nonNegativeIntOption('image-retry-delay-ms', 3000);
        $imageRequestDelay = $this->nonNegativeIntOption('image-request-delay-ms', 250);

        $this->line('Images: '.($importImages ? 'download/sync' : 'skipped'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('Refresh existing images: '.($refreshImages ? 'YES' : 'NO'));

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
                $result = $this->importer->import(
                    mapped: $mappedProduct,
                    importImages: $importImages,
                    imageLimit: $imageLimit,
                    refreshImages: $refreshImages,
                    imageTimeoutSeconds: $imageTimeout,
                    imageAttempts: $imageAttempts,
                    imageRetryDelayMs: $imageRetryDelay,
                    imageRequestDelayMs: $imageRequestDelay,
                    importDocuments: true,
                );
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
        $this->info('=== SIGVARIS LOCAL IMPORT RESULT ===');
        $this->line('Products created: '.$created);
        $this->line('Products updated: '.$updated);
        $this->line('Warnings: '.count($warnings));
        $this->line('Failures: '.count($failures));
        $this->line('Variants created: '.$stats['variants_created']);
        $this->line('Variants updated: '.$stats['variants_updated']);
        $this->line('Variants archived: '.$stats['variants_archived']);
        $this->line('Categories created: '.$stats['categories_created']);
        $this->line('Categories reused: '.$stats['categories_reused']);
        $this->line('Images created: '.$stats['images_created']);
        $this->line('Images updated: '.$stats['images_updated']);
        $this->line('Images reused without download: '.$stats['images_reused']);
        $this->line('Images deleted as stale: '.$stats['images_deleted']);
        $this->line('Image failures: '.$stats['images_failed']);
        $this->line('Documents created: '.$stats['documents_created']);
        $this->line('Documents reused without download: '.$stats['documents_reused']);

        $this->printDatabaseAudit($importedProductIds);
        $this->printReviewItems($reviewItems);

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->warn('Failed Sigvaris imports:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' | external_id='.($failure['external_id'] ?? '?').' | '.$failure['error']);
            }
        }

        if ($failures === [] && (! $importImages || $stats['images_failed'] === 0)) {
            $this->info('PASS: selected Sigvaris products were imported locally as drafts.');

            return self::SUCCESS;
        }

        if ($failures === [] && $stats['images_failed'] > 0) {
            $this->error('FAIL: product rows were imported, but one or more selected image downloads failed.');
        }

        return self::FAILURE;
    }

    /** @param list<array<string, mixed>> $products */
    private function printDryRunSummary(array $products): void
    {
        $variants = 0;
        $images = 0;
        $categoryPaths = [];
        $downloads = 0;
        $videos = 0;

        foreach ($products as $product) {
            $variants += is_array($product['variants'] ?? null) ? count($product['variants']) : 0;
            $images += is_array($product['images'] ?? null) ? count($product['images']) : 0;
            $downloads += is_array($product['downloads'] ?? null) ? count($product['downloads']) : 0;
            $videos += is_array($product['videos'] ?? null) ? count($product['videos']) : 0;

            foreach (($product['categories'] ?? []) as $category) {
                if (! is_array($category) || ! is_array($category['path'] ?? null)) {
                    continue;
                }

                $path = implode(' > ', array_filter($category['path'], 'is_string'));

                if ($path !== '') {
                    $categoryPaths[$path] = true;
                }
            }
        }

        $this->info('Dry-run summary. No database writes were made. No images were downloaded.');
        $this->line('Products to create/update: '.count($products));
        $this->line('Planned variants: '.$variants);
        $this->line('Mapped images: '.$images);
        $this->line('Distinct mapped category paths: '.count($categoryPaths));
        $this->line('Preserved document links: '.$downloads);
        $this->line('Preserved product video links: '.$videos);
    }

    /** @param list<int> $productIds */
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
        $this->line('Draft product rows: '.$products->where('status', \App\Enums\ProductStatus::DRAFT)->count());
        $this->line('Variant rows: '.$products->sum('variants_count'));
        $this->line('Image rows: '.$products->sum('images_count'));
        $this->line('Category assignments: '.$products->sum('categories_count'));
        $this->line('All local Sigvaris product rows: '.Product::query()->where('external_source', 'sigvaris')->count());
    }

    /** @param list<array<string, mixed>> $products @return list<string> */
    private function selectedReviewItems(array $products): array
    {
        $items = [];

        foreach ($products as $product) {
            $name = $this->mappedProductName($product);

            foreach (($product['review_items'] ?? []) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = $name.': '.trim($item);
                }
            }
        }

        return array_values(array_unique($items));
    }

    /** @param list<string> $reviewItems */
    private function printReviewItems(array $reviewItems): void
    {
        if (! (bool) $this->option('show-review') || $reviewItems === []) {
            return;
        }

        $this->newLine();
        $this->warn('Mapping review items: '.count($reviewItems));

        foreach ($reviewItems as $item) {
            $this->line('- '.$item);
        }
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
            'images_created' => 0,
            'images_updated' => 0,
            'images_reused' => 0,
            'images_deleted' => 0,
            'images_failed' => 0,
            'documents_created' => 0,
            'documents_reused' => 0,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadMap(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('Sigvaris import-map file not found: '.$path);

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Sigvaris import-map is not valid JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'sigvaris') {
            $this->error('Sigvaris import-map has an invalid source marker.');

            return null;
        }

        return $decoded;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = 'scrapers/sigvaris/import-map.json';
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

        return is_string($value) && trim($value) !== '' ? trim($value) : '[unnamed Sigvaris product]';
    }

    /** @param array<string, mixed> $mapped */
    private function mappedExternalId(array $mapped): ?string
    {
        $value = $mapped['product']['external_id'] ?? null;

        return is_string($value) || is_numeric($value) ? trim((string) $value) : null;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(1, (int) $value);
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

    private function normalizedSha256Option(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private function imageLimitOption(): ?int
    {
        $value = $this->option('image-limit');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
