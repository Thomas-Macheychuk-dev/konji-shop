<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Armedical\ArmedicalMediaImporter;
use App\Services\Armedical\ArmedicalProductImporter;
use App\Services\Armedical\ArmedicalProductionPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class ImportArmedicalProductionCommand extends Command
{
    public const APPROVED_PRICED_MAP_SHA256 = '9617b3d1a5d549c7b590ea6c252cd0ded430cf1a31571bb8853c6dbe20a2ad20';
    public const CONFIRMATION_TOKEN = 'ARMEDICAL-PRODUCTION-187-459-9617B3D1';

    private const APPROVED_PRODUCT_DATA_SHA256 = '05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8';
    private const APPROVED_SUPPLIER_XLS_SHA256 = 'ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893';
    private const SOURCE_PRODUCTS = 200;
    private const PLANNED_VARIANTS = 506;
    private const ELIGIBLE_PRODUCTS = 187;
    private const ELIGIBLE_VARIANTS = 459;
    private const EXCLUDED_PRODUCTS = 13;
    private const UNMATCHED_VARIANTS = 47;
    private const ELIGIBLE_IMAGES = 923;
    private const ELIGIBLE_DOCUMENTS = 318;
    private const VAT_8_VARIANTS = 451;
    private const VAT_23_VARIANTS = 8;
    private const REVIEW_ITEMS = 6;
    private const BLOCKING_REVIEW_ITEMS = 1;
    private const SUPPLIER_ROWS = 245;
    private const SUPPLIER_UNIQUE_CODES = 241;

    protected $signature = 'armedical:production-import
        {--from=scrapers/armedical/import-map-priced.json : Frozen priced ARmedical map on the Laravel local disk.}
        {--stage=all : Execution stage: all, catalogue, or media.}
        {--write : Perform controlled production writes. Without this flag the command is read-only.}
        {--confirm= : Required exact confirmation token when --write is used.}
        {--expected-existing-products= : Exact ARmedical product rows expected before execution. Defaults to 0, or 187 for media-only.}
        {--expected-existing-variants= : Exact ARmedical variant rows expected before execution. Defaults to 0, or 459 for media-only.}
        {--expected-existing-images=0 : Exact ARmedical image rows/files expected before execution.}
        {--expected-existing-document-links=0 : Exact localized ARmedical document links/files expected before execution.}
        {--minimum-free-mib=500 : Minimum free space on the shared public storage volume.}
        {--probe-images=3 : Read-only source image probes before writes.}
        {--probe-documents=3 : Read-only source document probes before writes.}
        {--probe-timeout=20 : Timeout for pre-write network probes.}
        {--timeout=30 : HTTP timeout for media downloads.}
        {--attempts=5 : Maximum HTTP attempts per media request.}
        {--retry-delay-ms=3000 : Milliseconds between HTTP retries.}
        {--request-delay-ms=250 : Milliseconds between media downloads.}
        {--allow-non-production : Permit a local/testing rehearsal. Production writes still require --write and --confirm.}
        {--show-review : Print retained source/pricing review items.}
        {--show-failures : Print individual catalogue/media failures.}';

    protected $description = 'Execute the frozen 187-product ARmedical production import with mandatory pre/post gates; all products remain draft.';

    public function __construct(
        private readonly ArmedicalProductionPreflight $preflight,
        private readonly ArmedicalProductImporter $productImporter,
        private readonly ArmedicalMediaImporter $mediaImporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: armedical:production-import is production-only. Use --allow-non-production for a rehearsal.');

            return self::FAILURE;
        }

        if ($write && trim((string) $this->option('confirm')) !== self::CONFIRMATION_TOKEN) {
            $this->error('BLOCKED: production writes require --confirm='.self::CONFIRMATION_TOKEN);

            return self::FAILURE;
        }

        $stage = strtolower(trim((string) $this->option('stage')));
        if (! in_array($stage, ['all', 'catalogue', 'media'], true)) {
            $this->error('Invalid --stage. Allowed values: all, catalogue, media.');

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->option('from'));
        $map = $this->loadMap($path);
        if ($map === null) {
            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256) || ! hash_equals(self::APPROVED_PRICED_MAP_SHA256, $sha256)) {
            $this->error('BLOCKED: priced-map SHA-256 does not match the frozen production fingerprint.');
            $this->line('Expected: '.self::APPROVED_PRICED_MAP_SHA256);
            $this->line('Actual: '.($sha256 ?: '[unavailable]'));

            return self::FAILURE;
        }

        [$eligible, $excluded] = $this->splitProducts($map);
        $invariantErrors = $this->frozenMapErrors($map, $eligible, $excluded);
        if ($invariantErrors !== []) {
            $this->error('BLOCKED: frozen ARmedical map invariants no longer match the approved cohort.');
            foreach ($invariantErrors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $expectedExistingProducts = $this->nullableNonNegativeIntOption('expected-existing-products')
            ?? ($stage === 'media' ? self::ELIGIBLE_PRODUCTS : 0);
        $expectedExistingVariants = $this->nullableNonNegativeIntOption('expected-existing-variants')
            ?? ($stage === 'media' ? self::ELIGIBLE_VARIANTS : 0);
        $expectedExistingImages = $this->nonNegativeIntOption('expected-existing-images', 0);
        $expectedExistingDocumentLinks = $this->nonNegativeIntOption('expected-existing-document-links', 0);

        $preReport = $this->preflight->inspect(
            map: $map,
            expected: $this->preflightExpected(
                existingProducts: $expectedExistingProducts,
                existingVariants: $expectedExistingVariants,
                existingImages: $expectedExistingImages,
                existingDocumentLinks: $expectedExistingDocumentLinks,
            ),
            mapSha256: $sha256,
            minimumFreeMiB: $this->nonNegativeIntOption('minimum-free-mib', 500),
            probeImageCount: $this->nonNegativeIntOption('probe-images', 3),
            probeDocumentCount: $this->nonNegativeIntOption('probe-documents', 3),
            probeTimeoutSeconds: max(1, $this->nonNegativeIntOption('probe-timeout', 20)),
        );
        $preErrors = $this->stringList($preReport['errors'] ?? null);

        $this->info('ARmedical controlled production import');
        $this->line('Environment: '.app()->environment());
        $this->line('Stage: '.$stage);
        $this->line('Source: '.$path);
        $this->line('Priced-map SHA-256: '.$sha256);
        $this->line('Frozen SHA gate: PASS');
        $this->line('Eligible products: '.count($eligible));
        $this->line('Eligible variants: '.$this->variantCount($eligible));
        $this->line('Excluded unresolved products: '.count($excluded));
        $this->line('Mapped images: '.$this->imageCount($eligible));
        $this->line('Mapped documents: '.$this->documentCount($eligible));
        $this->line('Pre-write expected DB/media state: '.$expectedExistingProducts.' products / '.$expectedExistingVariants.' variants / '.$expectedExistingImages.' images / '.$expectedExistingDocumentLinks.' document links');
        $this->line('Pre-write hard errors: '.count($preErrors));
        $this->line('Database/media writes: '.($write ? 'REQUESTED' : 'NO'));
        $this->line('Product/variant status: draft (forced)');
        $this->line('Variant stock status: out_of_stock (forced)');

        if ((bool) $this->option('show-review')) {
            $this->printReview($map);
        }

        if ($preErrors !== []) {
            $this->error('BLOCKED: production preflight failed. No ARmedical writes were started.');
            foreach ($preErrors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        if (! $write) {
            $this->info('PASS: controlled production-import dry-run only. No database, filesystem, or catalogue writes were made.');
            $this->line('Write token: '.self::CONFIRMATION_TOKEN);

            return self::SUCCESS;
        }

        if ($stage === 'catalogue' || $stage === 'all') {
            if (! $this->writeCatalogue($eligible)) {
                $this->error('FAIL: catalogue stage did not complete. Media stage was not started. Re-run only after reviewing the reported failure(s).');

                return self::FAILURE;
            }

            $catalogueErrors = $this->catalogueStateErrors($eligible, $excluded);
            if ($catalogueErrors !== []) {
                $this->error('FAIL: post-catalogue gate failed. Media stage was not started.');
                foreach ($catalogueErrors as $error) {
                    $this->line('- '.$error);
                }

                return self::FAILURE;
            }

            $this->info('PASS: post-catalogue gate = 187 draft products / 459 draft out-of-stock variants.');
        }

        if ($stage === 'media' || $stage === 'all') {
            $catalogueErrors = $this->catalogueStateErrors($eligible, $excluded);
            if ($catalogueErrors !== []) {
                $this->error('BLOCKED: media stage requires the complete approved catalogue cohort first.');
                foreach ($catalogueErrors as $error) {
                    $this->line('- '.$error);
                }

                return self::FAILURE;
            }

            if (! $this->writeMedia($eligible)) {
                $this->error('FAIL: media stage did not complete. The command is idempotent; fix connectivity/source issues and re-run with exact current --expected-existing-* counts.');

                return self::FAILURE;
            }
        }

        if ($stage === 'all' || $stage === 'media') {
            $finalErrors = $this->finalStateErrors($eligible, $excluded);
            if ($finalErrors !== []) {
                $this->error('FAIL: final ARmedical production database/media audit failed.');
                foreach ($finalErrors as $error) {
                    $this->line('- '.$error);
                }

                return self::FAILURE;
            }

            $postReport = $this->preflight->inspect(
                map: $map,
                expected: $this->preflightExpected(
                    existingProducts: self::ELIGIBLE_PRODUCTS,
                    existingVariants: self::ELIGIBLE_VARIANTS,
                    existingImages: self::ELIGIBLE_IMAGES,
                    existingDocumentLinks: self::ELIGIBLE_DOCUMENTS,
                ),
                mapSha256: $sha256,
                minimumFreeMiB: $this->nonNegativeIntOption('minimum-free-mib', 500),
                probeImageCount: 0,
                probeDocumentCount: 0,
                probeTimeoutSeconds: max(1, $this->nonNegativeIntOption('probe-timeout', 20)),
            );
            $postErrors = $this->stringList($postReport['errors'] ?? null);
            if ($postErrors !== []) {
                $this->error('FAIL: strict post-write production preflight did not pass.');
                foreach ($postErrors as $error) {
                    $this->line('- '.$error);
                }

                return self::FAILURE;
            }

            $this->info('PASS: ARmedical production import complete: 187 draft products, 459 variants, 923 images, 318 local document links.');

            return self::SUCCESS;
        }

        $this->info('PASS: ARmedical catalogue production stage complete. Media remains a separate controlled stage.');

        return self::SUCCESS;
    }

    /** @param list<array<string,mixed>> $eligible */
    private function writeCatalogue(array $eligible): bool
    {
        $created = 0;
        $updated = 0;
        $failures = [];
        $total = count($eligible);

        $this->newLine();
        $this->info('=== ARMEDICAL PRODUCTION CATALOGUE WRITE ===');

        foreach ($eligible as $index => $mapped) {
            $name = $this->mappedName($mapped);
            $externalId = $this->mappedExternalId($mapped);
            $this->line(sprintf('Catalogue %d/%d: %s | external_id=%s', $index + 1, $total, $name, $externalId ?? '?'));

            try {
                $result = $this->productImporter->import($mapped);
                $result['action'] === 'created' ? $created++ : $updated++;
            } catch (Throwable $exception) {
                $failures[] = $name.' | external_id='.($externalId ?? '?').' | '.$exception->getMessage();
                $this->error('  FAILED: '.$exception->getMessage());
            }
        }

        $this->line('Products created: '.$created);
        $this->line('Products updated: '.$updated);
        $this->line('Product failures: '.count($failures));

        if ((bool) $this->option('show-failures')) {
            foreach ($failures as $failure) {
                $this->line('- '.$failure);
            }
        }

        return $failures === [];
    }

    /** @param list<array<string,mixed>> $eligible */
    private function writeMedia(array $eligible): bool
    {
        $stats = [
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
        $failures = [];
        $total = count($eligible);
        $timeout = max(1, $this->nonNegativeIntOption('timeout', 30));
        $attempts = max(1, $this->nonNegativeIntOption('attempts', 5));
        $retryDelayMs = $this->nonNegativeIntOption('retry-delay-ms', 3000);
        $requestDelayMs = $this->nonNegativeIntOption('request-delay-ms', 250);

        $this->newLine();
        $this->info('=== ARMEDICAL PRODUCTION MEDIA WRITE ===');

        foreach ($eligible as $index => $mapped) {
            $name = $this->mappedName($mapped);
            $externalId = $this->mappedExternalId($mapped);
            $this->line(sprintf('Media %d/%d: %s | external_id=%s', $index + 1, $total, $name, $externalId ?? '?'));

            try {
                $result = $this->mediaImporter->import(
                    mapped: $mapped,
                    importImages: true,
                    importDocuments: true,
                    imageLimit: null,
                    refreshImages: false,
                    refreshDocuments: false,
                    timeoutSeconds: $timeout,
                    attempts: $attempts,
                    retryDelayMs: $retryDelayMs,
                    requestDelayMs: $requestDelayMs,
                );
                foreach ($result['stats'] as $key => $value) {
                    $stats[$key] = ($stats[$key] ?? 0) + $value;
                }
                if ($result['warnings'] !== []) {
                    foreach ($result['warnings'] as $warning) {
                        $this->warn('  '.$warning);
                    }
                }
            } catch (Throwable $exception) {
                $failures[] = $name.' | external_id='.($externalId ?? '?').' | '.$exception->getMessage();
                $this->error('  FAILED: '.$exception->getMessage());
            }
        }

        $this->line('Images created: '.$stats['images_created']);
        $this->line('Images reused: '.$stats['images_reused']);
        $this->line('Image failures: '.$stats['images_failed']);
        $this->line('Documents created: '.$stats['documents_created']);
        $this->line('Documents reused: '.$stats['documents_reused']);
        $this->line('Document failures: '.$stats['documents_failed']);
        $this->line('Product failures: '.count($failures));

        if ((bool) $this->option('show-failures')) {
            foreach ($failures as $failure) {
                $this->line('- '.$failure);
            }
        }

        return $failures === [] && $stats['images_failed'] === 0 && $stats['documents_failed'] === 0;
    }

    /** @param list<array<string,mixed>> $eligible @param list<array<string,mixed>> $excluded @return list<string> */
    private function catalogueStateErrors(array $eligible, array $excluded): array
    {
        $errors = [];
        $eligibleIds = array_values(array_filter(array_map(fn (array $mapped): ?string => $this->mappedExternalId($mapped), $eligible)));
        $excludedIds = array_values(array_filter(array_map(fn (array $mapped): ?string => $this->mappedExternalId($mapped), $excluded)));
        $products = Product::query()
            ->where('external_source', 'armedical')
            ->whereIn('external_id', $eligibleIds)
            ->with('variants')
            ->get();

        if ($products->count() !== self::ELIGIBLE_PRODUCTS) {
            $errors[] = 'ARmedical product rows expected '.self::ELIGIBLE_PRODUCTS.', actual '.$products->count().'.';
        }
        if ($products->filter(fn (Product $product): bool => $product->status === ProductStatus::DRAFT)->count() !== self::ELIGIBLE_PRODUCTS) {
            $errors[] = 'all eligible ARmedical products must remain draft.';
        }

        $variants = $products->flatMap->variants;
        if ($variants->count() !== self::ELIGIBLE_VARIANTS) {
            $errors[] = 'ARmedical variant rows expected '.self::ELIGIBLE_VARIANTS.', actual '.$variants->count().'.';
        }
        if ($variants->filter(fn ($variant): bool => $variant->status === ProductVariantStatus::DRAFT)->count() !== self::ELIGIBLE_VARIANTS) {
            $errors[] = 'all eligible ARmedical variants must remain draft.';
        }
        if ($variants->filter(fn ($variant): bool => $variant->stock_status === StockStatus::OUT_OF_STOCK)->count() !== self::ELIGIBLE_VARIANTS) {
            $errors[] = 'all eligible ARmedical variants must remain out_of_stock.';
        }
        if (Product::query()->where('external_source', 'armedical')->whereIn('external_id', $excludedIds)->exists()) {
            $errors[] = 'one or more unresolved/excluded ARmedical products exist in the database.';
        }

        return $errors;
    }

    /** @param list<array<string,mixed>> $eligible @param list<array<string,mixed>> $excluded @return list<string> */
    private function finalStateErrors(array $eligible, array $excluded): array
    {
        $errors = $this->catalogueStateErrors($eligible, $excluded);
        $products = Product::query()->where('external_source', 'armedical')->with(['variants', 'images'])->get();
        $variants = $products->flatMap->variants;
        $imageRows = $products->sum(fn (Product $product): int => $product->images->count());
        $documentLinks = $products->sum(fn (Product $product): int => substr_count((string) $product->description, 'data-armedical-document-source='));

        if ($products->count() !== self::ELIGIBLE_PRODUCTS) {
            $errors[] = 'total ARmedical products expected '.self::ELIGIBLE_PRODUCTS.', actual '.$products->count().'.';
        }
        if ($imageRows !== self::ELIGIBLE_IMAGES) {
            $errors[] = 'ARmedical image rows expected '.self::ELIGIBLE_IMAGES.', actual '.$imageRows.'.';
        }
        if ($documentLinks !== self::ELIGIBLE_DOCUMENTS) {
            $errors[] = 'ARmedical local document links expected '.self::ELIGIBLE_DOCUMENTS.', actual '.$documentLinks.'.';
        }
        if ($variants->filter(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_8)->count() !== self::VAT_8_VARIANTS) {
            $errors[] = '8% VAT variants expected '.self::VAT_8_VARIANTS.'.';
        }
        if ($variants->filter(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_23)->count() !== self::VAT_23_VARIANTS) {
            $errors[] = '23% VAT variants expected '.self::VAT_23_VARIANTS.'.';
        }

        return array_values(array_unique($errors));
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} */
    private function splitProducts(array $map): array
    {
        $eligible = [];
        $excluded = [];
        foreach ($map['products'] ?? [] as $product) {
            if (! is_array($product)) {
                continue;
            }
            $this->productImporter->isFullyPriced($product) ? $eligible[] = $product : $excluded[] = $product;
        }

        return [$eligible, $excluded];
    }

    /** @param list<array<string,mixed>> $eligible @param list<array<string,mixed>> $excluded @return list<string> */
    private function frozenMapErrors(array $map, array $eligible, array $excluded): array
    {
        $errors = [];
        $checks = [
            ['source products', count(array_filter($map['products'] ?? [], 'is_array')), self::SOURCE_PRODUCTS],
            ['planned variants', (int) ($map['pricing_summary']['planned_variants'] ?? -1), self::PLANNED_VARIANTS],
            ['eligible products', count($eligible), self::ELIGIBLE_PRODUCTS],
            ['eligible variants', $this->variantCount($eligible), self::ELIGIBLE_VARIANTS],
            ['excluded products', count($excluded), self::EXCLUDED_PRODUCTS],
            ['unmatched variants', (int) ($map['pricing_summary']['unmatched_variants'] ?? -1), self::UNMATCHED_VARIANTS],
            ['eligible images', $this->imageCount($eligible), self::ELIGIBLE_IMAGES],
            ['eligible documents', $this->documentCount($eligible), self::ELIGIBLE_DOCUMENTS],
        ];
        foreach ($checks as [$label, $actual, $expected]) {
            if ($actual !== $expected) {
                $errors[] = $label.' expected '.$expected.', actual '.$actual.'.';
            }
        }

        if (($map['input_fingerprint']['sha256'] ?? null) !== self::APPROVED_PRODUCT_DATA_SHA256) {
            $errors[] = 'frozen v3 product-data SHA changed.';
        }
        if (($map['supplier_price_list']['metadata']['source_sha256'] ?? null) !== self::APPROVED_SUPPLIER_XLS_SHA256) {
            $errors[] = 'supplier XLS SHA changed.';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function preflightExpected(int $existingProducts, int $existingVariants, int $existingImages, int $existingDocumentLinks): array
    {
        return [
            'source_products' => self::SOURCE_PRODUCTS,
            'planned_variants' => self::PLANNED_VARIANTS,
            'eligible_products' => self::ELIGIBLE_PRODUCTS,
            'eligible_variants' => self::ELIGIBLE_VARIANTS,
            'excluded_products' => self::EXCLUDED_PRODUCTS,
            'unmatched_variants' => self::UNMATCHED_VARIANTS,
            'images' => self::ELIGIBLE_IMAGES,
            'documents' => self::ELIGIBLE_DOCUMENTS,
            'vat_8_variants' => self::VAT_8_VARIANTS,
            'vat_23_variants' => self::VAT_23_VARIANTS,
            'review_items' => self::REVIEW_ITEMS,
            'blocking_review_items' => self::BLOCKING_REVIEW_ITEMS,
            'supplier_rows' => self::SUPPLIER_ROWS,
            'supplier_unique_codes' => self::SUPPLIER_UNIQUE_CODES,
            'existing_products' => $existingProducts,
            'existing_variants' => $existingVariants,
            'existing_images' => $existingImages,
            'existing_document_links' => $existingDocumentLinks,
            'sha256' => self::APPROVED_PRICED_MAP_SHA256,
            'product_data_sha256' => self::APPROVED_PRODUCT_DATA_SHA256,
            'supplier_xls_sha256' => self::APPROVED_SUPPLIER_XLS_SHA256,
        ];
    }

    private function resolvePath(string $relative): string
    {
        $relative = trim($relative) !== '' ? trim($relative) : 'scrapers/armedical/import-map-priced.json';
        if (str_starts_with($relative, '/')) {
            return $relative;
        }

        return Storage::disk('local')->path(ltrim($relative, '/'));
    }

    /** @return array<string,mixed>|null */
    private function loadMap(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('ARmedical priced import-map not found: '.$path);

            return null;
        }

        try {
            $map = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid ARmedical priced import-map JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($map) || ($map['source'] ?? null) !== 'armedical') {
            $this->error('ARmedical priced import-map root/source is invalid.');

            return null;
        }

        return $map;
    }

    /** @param list<array<string,mixed>> $products */
    private function variantCount(array $products): int
    {
        return array_sum(array_map(static fn (array $product): int => count(array_filter($product['variants'] ?? [], 'is_array')), $products));
    }

    /** @param list<array<string,mixed>> $products */
    private function imageCount(array $products): int
    {
        return array_sum(array_map(static fn (array $product): int => count(array_filter($product['images'] ?? [], 'is_array')), $products));
    }

    /** @param list<array<string,mixed>> $products */
    private function documentCount(array $products): int
    {
        return array_sum(array_map(static fn (array $product): int => count(array_filter($product['documents'] ?? [], 'is_array')), $products));
    }

    private function mappedName(array $mapped): string
    {
        return trim((string) ($mapped['product']['name'] ?? 'ARmedical product'));
    }

    private function mappedExternalId(array $mapped): ?string
    {
        $value = $mapped['product']['external_id'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function nullableNonNegativeIntOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function printReview(array $map): void
    {
        $review = $this->stringList($map['review_items'] ?? null);
        $blocking = $this->stringList($map['blocking_review_items'] ?? null);

        if ($review !== []) {
            $this->newLine();
            $this->warn('Retained priced-map review items:');
            foreach ($review as $item) {
                $this->line('- '.$item);
            }
        }
        if ($blocking !== []) {
            $this->newLine();
            $this->warn('Blocking source items retained only in excluded products:');
            foreach ($blocking as $item) {
                $this->line('- '.$item);
            }
        }
    }
}
