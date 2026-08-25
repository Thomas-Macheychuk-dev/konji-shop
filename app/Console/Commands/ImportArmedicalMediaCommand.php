<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Armedical\ArmedicalMediaImporter;
use App\Services\Armedical\ArmedicalProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class ImportArmedicalMediaCommand extends Command
{
    public const APPROVED_PRICED_MAP_SHA256 = '9617b3d1a5d549c7b590ea6c252cd0ded430cf1a31571bb8853c6dbe20a2ad20';

    private const APPROVED_SOURCE_PRODUCTS = 200;

    private const APPROVED_PLANNED_VARIANTS = 506;

    private const APPROVED_ELIGIBLE_PRODUCTS = 187;

    private const APPROVED_ELIGIBLE_VARIANTS = 459;

    private const APPROVED_EXCLUDED_PRODUCTS = 13;

    private const APPROVED_UNMATCHED_VARIANTS = 47;

    private const APPROVED_ELIGIBLE_IMAGES = 923;

    private const APPROVED_ELIGIBLE_DOCUMENTS = 318;

    private const APPROVED_SUPPLIER_XLS_SHA256 = 'ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893';

    protected $signature = 'armedical:import-media
        {--from=scrapers/armedical/import-map-priced.json : Frozen priced ARmedical map. Relative paths resolve under storage/app.}
        {--write : Download/sync media for the already imported local draft cohort. Without this flag the command is read-only.}
        {--limit= : Maximum number of eligible products to process.}
        {--offset=0 : Number of eligible products to skip before processing.}
        {--no-images : Do not download or sync product images.}
        {--no-documents : Do not download or localize product documents.}
        {--image-limit=0 : Maximum images per product. Use 0 for all mapped images.}
        {--refresh-images : Re-download mapped images even when the local file exists.}
        {--refresh-documents : Re-download mapped documents even when a localized file exists.}
        {--timeout=30 : HTTP timeout in seconds for media requests.}
        {--attempts=5 : Maximum HTTP attempts per media request.}
        {--retry-delay-ms=3000 : Milliseconds between HTTP retry attempts.}
        {--request-delay-ms=250 : Milliseconds between media downloads.}
        {--show-failures : Print failed product media imports.}';

    protected $description = 'Read-only preflight or local-only image/document synchronization for the frozen resolved ARmedical cohort.';

    public function __construct(
        private readonly ArmedicalProductImporter $productImporter,
        private readonly ArmedicalMediaImporter $mediaImporter,
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
        $eligible = [];
        $excluded = [];

        foreach ($products as $product) {
            if ($this->productImporter->isFullyPriced($product)) {
                $eligible[] = $product;
            } else {
                $excluded[] = $product;
            }
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selected = array_slice($eligible, $offset, $limit);
        $write = (bool) $this->option('write');
        $importImages = ! (bool) $this->option('no-images');
        $importDocuments = ! (bool) $this->option('no-documents');
        $imageLimit = $this->imageLimitOption();
        $actualSha256 = hash_file('sha256', $path);
        $fullDbErrors = $this->fullCohortDatabaseErrors($eligible, $excluded);

        $this->info('ARmedical local media import');
        $this->line('Source: '.$path);
        $this->line('Priced-map SHA-256: '.$actualSha256);
        $this->line('Approved SHA gate: '.(hash_equals(self::APPROVED_PRICED_MAP_SHA256, $actualSha256) ? 'PASS' : 'FAIL'));
        $this->line('Eligible imported products expected: '.count($eligible));
        $this->line('Eligible planned variants expected: '.$this->variantCount($eligible));
        $this->line('Mapped images in eligible cohort: '.$this->imageCount($eligible));
        $this->line('Mapped documents in eligible cohort: '.$this->documentCount($eligible));
        $this->line('Selected products: '.count($selected));
        $this->line('Selected mapped images: '.$this->imageCount($selected));
        $this->line('Selected mapped documents: '.$this->documentCount($selected));
        $this->line('Images enabled: '.($importImages ? 'YES' : 'NO'));
        $this->line('Documents enabled: '.($importDocuments ? 'YES' : 'NO'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'all' : (string) $imageLimit));
        $this->line('Database/media writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Catalogue fields/variants/pricing mutation: NO (product description resource links may be localized)');
        $this->line('Environment: '.app()->environment());
        $this->line('Existing local cohort gate: '.($fullDbErrors === [] ? 'PASS' : 'FAIL'));

        if ($fullDbErrors !== []) {
            foreach ($fullDbErrors as $error) {
                $this->line('- '.$error);
            }
        }

        if ($selected === []) {
            $this->warn('No eligible products selected after offset/limit.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->info('Dry-run summary. No HTTP media requests, database writes, or filesystem writes were made.');
            $this->line('Products ready for selected media stage: '.count($selected));
            $this->line('Images planned: '.($importImages ? $this->imageCount($selected) : 0));
            $this->line('Documents planned: '.($importDocuments ? $this->documentCount($selected) : 0));
            $this->info('PASS: media preflight only. The 13 unresolved source products remain excluded.');

            return self::SUCCESS;
        }

        if (! app()->environment('local', 'testing')) {
            $this->error('BLOCKED: armedical:import-media --write is allowed only in local/testing environments.');

            return self::FAILURE;
        }

        if (! hash_equals(self::APPROVED_PRICED_MAP_SHA256, $actualSha256)) {
            $this->error('BLOCKED: ARmedical priced-map SHA-256 does not match the frozen approved fingerprint.');

            return self::FAILURE;
        }

        $invariantErrors = $this->approvedMapInvariantErrors($map, $products, $eligible, $excluded);

        if ($invariantErrors !== []) {
            $this->error('BLOCKED: frozen ARmedical media cohort invariants do not match the approved audit.');

            foreach ($invariantErrors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        if ($fullDbErrors !== []) {
            $this->error('BLOCKED: the full 187-product/459-variant local ARmedical cohort is not intact.');

            return self::FAILURE;
        }

        if (! $importImages && ! $importDocuments) {
            $this->warn('Nothing to do: both images and documents are disabled.');

            return self::SUCCESS;
        }

        $stats = $this->emptyStats();
        $warnings = [];
        $failures = [];
        $selectedProductIds = [];
        $total = count($selected);
        $timeout = $this->positiveIntOption('timeout', 30);
        $attempts = $this->positiveIntOption('attempts', 5);
        $retryDelayMs = $this->nonNegativeIntOption('retry-delay-ms', 3000);
        $requestDelayMs = $this->nonNegativeIntOption('request-delay-ms', 250);

        foreach ($selected as $index => $mappedProduct) {
            $name = $this->mappedProductName($mappedProduct);
            $externalId = $this->mappedExternalId($mappedProduct);
            $this->line(sprintf('Media %d/%d: %s | external_id=%s', $index + 1, $total, $name, $externalId ?? '?'));

            try {
                $result = $this->mediaImporter->import(
                    mapped: $mappedProduct,
                    importImages: $importImages,
                    importDocuments: $importDocuments,
                    imageLimit: $imageLimit,
                    refreshImages: (bool) $this->option('refresh-images'),
                    refreshDocuments: (bool) $this->option('refresh-documents'),
                    timeoutSeconds: $timeout,
                    attempts: $attempts,
                    retryDelayMs: $retryDelayMs,
                    requestDelayMs: $requestDelayMs,
                );
                $product = $result['product'];
                $selectedProductIds[] = $product->id;

                foreach ($result['stats'] as $key => $value) {
                    $stats[$key] = ($stats[$key] ?? 0) + $value;
                }

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $name.': '.$warning;
                    $this->warn('  '.$warning);
                }

                $this->info(sprintf(
                    '  product ID %d | image rows=%d | local document links=%d',
                    $product->id,
                    $product->images->count(),
                    $this->localDocumentLinkCount($product->description),
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
        $this->info('=== ARMEDICAL LOCAL MEDIA RESULT ===');
        $this->line('Products processed: '.count($selectedProductIds));
        $this->line('Warnings: '.count($warnings));
        $this->line('Product failures: '.count($failures));
        $this->line('Images created: '.$stats['images_created']);
        $this->line('Images updated: '.$stats['images_updated']);
        $this->line('Images reused without download: '.$stats['images_reused']);
        $this->line('Images deleted as stale ARmedical rows: '.$stats['images_deleted']);
        $this->line('Image failures: '.$stats['images_failed']);
        $this->line('Documents created: '.$stats['documents_created']);
        $this->line('Documents reused without download: '.$stats['documents_reused']);
        $this->line('Document failures: '.$stats['documents_failed']);
        $this->line('Descriptions updated with local document links: '.$stats['descriptions_updated']);
        $this->line('Catalogue fields/variants/pricing mutation: 0');

        $this->printSelectedAudit($selectedProductIds);

        if ((bool) $this->option('show-failures') && $failures !== []) {
            $this->warn('Failed ARmedical media products:');

            foreach ($failures as $failure) {
                $this->line('- '.$failure['name'].' | external_id='.($failure['external_id'] ?? '?').' | '.$failure['error']);
            }
        }

        if ($failures === [] && $stats['images_failed'] === 0 && $stats['documents_failed'] === 0) {
            $this->info('PASS: selected ARmedical media is localized; products remain draft and catalogue/pricing data was not rewritten.');

            return self::SUCCESS;
        }

        $this->error('FAIL: one or more selected ARmedical media items could not be localized.');

        return self::FAILURE;
    }

    /** @param list<array<string, mixed>> $eligible @param list<array<string, mixed>> $excluded @return list<string> */
    private function fullCohortDatabaseErrors(array $eligible, array $excluded): array
    {
        $errors = [];
        $eligibleIds = array_values(array_filter(array_map(fn (array $product): ?string => $this->mappedExternalId($product), $eligible)));
        $excludedIds = array_values(array_filter(array_map(fn (array $product): ?string => $this->mappedExternalId($product), $excluded)));
        $local = Product::query()
            ->where('external_source', 'armedical')
            ->whereIn('external_id', $eligibleIds)
            ->withCount('variants')
            ->get();

        if ($local->count() !== self::APPROVED_ELIGIBLE_PRODUCTS) {
            $errors[] = 'eligible local products expected '.self::APPROVED_ELIGIBLE_PRODUCTS.', got '.$local->count().'.';
        }

        if ($local->where('status', ProductStatus::DRAFT)->count() !== self::APPROVED_ELIGIBLE_PRODUCTS) {
            $errors[] = 'all eligible local ARmedical products must remain draft.';
        }

        if ((int) $local->sum('variants_count') !== self::APPROVED_ELIGIBLE_VARIANTS) {
            $errors[] = 'eligible local variants expected '.self::APPROVED_ELIGIBLE_VARIANTS.', got '.$local->sum('variants_count').'.';
        }

        $unexpectedExcluded = Product::query()
            ->where('external_source', 'armedical')
            ->whereIn('external_id', $excludedIds)
            ->count();

        if ($unexpectedExcluded !== 0) {
            $errors[] = 'unresolved/excluded ARmedical products must remain absent; found '.$unexpectedExcluded.'.';
        }

        return $errors;
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
            'eligible mapped images' => [$this->imageCount($eligible), self::APPROVED_ELIGIBLE_IMAGES],
            'eligible mapped documents' => [$this->documentCount($eligible), self::APPROVED_ELIGIBLE_DOCUMENTS],
        ];

        foreach ($expected as $label => [$actual, $approved]) {
            if ($actual !== $approved) {
                $errors[] = $label.' expected '.$approved.', got '.$actual.'.';
            }
        }

        if (($supplier['source_sha256'] ?? null) !== self::APPROVED_SUPPLIER_XLS_SHA256) {
            $errors[] = 'supplier XLS SHA-256 does not match the approved 2026 price list.';
        }

        return $errors;
    }

    /** @param list<int> $productIds */
    private function printSelectedAudit(array $productIds): void
    {
        $productIds = array_values(array_unique($productIds));
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->withCount(['variants', 'images'])
            ->get();

        $this->newLine();
        $this->info('=== SELECTED MEDIA DATABASE AUDIT ===');
        $this->line('Product rows: '.$products->count());
        $this->line('Draft product rows: '.$products->where('status', ProductStatus::DRAFT)->count());
        $this->line('Variant rows (unchanged cohort data): '.$products->sum('variants_count'));
        $this->line('Image rows: '.$products->sum('images_count'));
        $this->line('Local document links: '.$products->sum(fn (Product $product): int => $this->localDocumentLinkCount($product->description)));
    }

    /** @param list<array<string, mixed>> $products */
    private function variantCount(array $products): int
    {
        return array_sum(array_map(static fn (array $product): int => is_array($product['variants'] ?? null) ? count($product['variants']) : 0, $products));
    }

    /** @param list<array<string, mixed>> $products */
    private function imageCount(array $products): int
    {
        return array_sum(array_map(static fn (array $product): int => is_array($product['images'] ?? null) ? count($product['images']) : 0, $products));
    }

    /** @param list<array<string, mixed>> $products */
    private function documentCount(array $products): int
    {
        return array_sum(array_map(static fn (array $product): int => is_array($product['documents'] ?? null) ? count($product['documents']) : 0, $products));
    }

    private function localDocumentLinkCount(?string $description): int
    {
        return $description === null ? 0 : substr_count($description, 'data-armedical-document-source=');
    }

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'images_created' => 0,
            'images_updated' => 0,
            'images_reused' => 0,
            'images_deleted' => 0,
            'images_failed' => 0,
            'documents_created' => 0,
            'documents_reused' => 0,
            'documents_failed' => 0,
            'descriptions_updated' => 0,
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

    private function positiveIntOption(string $name, int $default): int
    {
        return max(1, $this->nonNegativeIntOption($name, $default));
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
