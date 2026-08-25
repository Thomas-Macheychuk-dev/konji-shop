<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ArmedicalProductionPreflight
{
    private const SOURCE = 'armedical';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /** @var list<string> */
    private const ALLOWED_MEDIA_HOSTS = ['armedical.pl', 'www.armedical.pl'];

    public function __construct(private readonly ArmedicalProductImporter $productImporter)
    {
    }

    /**
     * @param  array<string,mixed>  $map
     * @param  array<string,int|string|null>  $expected
     * @return array<string,mixed>
     */
    public function inspect(
        array $map,
        array $expected,
        string $mapSha256,
        int $minimumFreeMiB = 500,
        int $probeImageCount = 0,
        int $probeDocumentCount = 0,
        int $probeTimeoutSeconds = 20,
    ): array {
        $checks = [];
        $errors = [];
        $review = $this->stringList($map['review_items'] ?? null);
        $topLevelBlocking = $this->stringList($map['blocking_review_items'] ?? null);
        $products = array_values(array_filter($map['products'] ?? [], 'is_array'));
        $eligible = [];
        $excluded = [];

        foreach ($products as $mapped) {
            if ($this->productImporter->isFullyPriced($mapped)) {
                $eligible[] = $mapped;
            } else {
                $excluded[] = $mapped;
            }
        }

        $metrics = $this->catalogueMetrics($products, $eligible, $excluded);
        $mapErrors = $this->stringList($map['errors'] ?? null);

        $this->hardCheck($checks, $errors, 'mapping.source', ($map['source'] ?? null) === self::SOURCE, 'Mapping source must be armedical.');
        $this->hardCheck($checks, $errors, 'mapping.database_writes', ($map['database_writes'] ?? null) === false, 'Approved priced map must state database_writes=false.');
        $this->hardCheck($checks, $errors, 'mapping.images_downloaded', ($map['images_downloaded'] ?? null) === false, 'Approved priced map must state images_downloaded=false.');
        $this->hardCheck($checks, $errors, 'mapping.errors', $mapErrors === [], $mapErrors === [] ? 'No hard mapping/pricing errors.' : implode(' | ', $mapErrors));
        $this->fingerprintCheck($checks, $errors, 'mapping.sha256', $mapSha256, $this->stringOrNull($expected['sha256'] ?? null), 'Priced import map');

        $fingerprint = is_array($map['input_fingerprint'] ?? null) ? $map['input_fingerprint'] : [];
        $this->fingerprintCheck(
            $checks,
            $errors,
            'mapping.product_data_sha256',
            $this->stringOrNull($fingerprint['sha256'] ?? null) ?? '',
            $this->stringOrNull($expected['product_data_sha256'] ?? null),
            'Frozen ARmedical v3 product data',
        );

        $supplier = is_array($map['supplier_price_list'] ?? null) ? $map['supplier_price_list'] : [];
        $supplierMeta = is_array($supplier['metadata'] ?? null) ? $supplier['metadata'] : [];
        $supplierSummary = is_array($supplier['summary'] ?? null) ? $supplier['summary'] : [];
        $this->fingerprintCheck(
            $checks,
            $errors,
            'pricing.supplier_xls_sha256',
            $this->stringOrNull($supplierMeta['source_sha256'] ?? null) ?? '',
            $this->stringOrNull($expected['supplier_xls_sha256'] ?? null),
            'Supplier XLS',
        );
        $this->hardCheck($checks, $errors, 'pricing.effective_from', ($supplierMeta['effective_from'] ?? null) === '2026-03-04', 'Supplier price list effective date must be 2026-03-04.');
        $this->hardCheck($checks, $errors, 'pricing.price_column', ($supplierMeta['price_column'] ?? null) === 'Cena netto', 'Supplier price column must be Cena netto.');
        $this->hardCheck($checks, $errors, 'pricing.vat_column', ($supplierMeta['vat_column'] ?? null) === 'VAT %', 'Supplier VAT column must be VAT %.');
        $this->hardCheck($checks, $errors, 'pricing.promo_column_ignored', ($supplierMeta['ignored_price_column'] ?? null) === 'Pakiet 5+1 cena*', 'Promotional 5+1 column must remain excluded from normal selling-price mapping.');

        $this->metricCheck($checks, $errors, 'catalogue.source_products', $metrics['source_products'], $expected['source_products'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.planned_variants', $metrics['planned_variants'], $expected['planned_variants'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.eligible_products', $metrics['eligible_products'], $expected['eligible_products'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.eligible_variants', $metrics['eligible_variants'], $expected['eligible_variants'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.excluded_products', $metrics['excluded_products'], $expected['excluded_products'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.unmatched_variants', $metrics['unmatched_variants'], $expected['unmatched_variants'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.images', $metrics['images'], $expected['images'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.documents', $metrics['documents'], $expected['documents'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.vat_8_variants', $metrics['vat_8_variants'], $expected['vat_8_variants'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.vat_23_variants', $metrics['vat_23_variants'], $expected['vat_23_variants'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.review_items', count($review), $expected['review_items'] ?? null);
        $this->metricCheck($checks, $errors, 'catalogue.blocking_review_items', count($topLevelBlocking), $expected['blocking_review_items'] ?? null);
        $this->metricCheck($checks, $errors, 'pricing.supplier_rows', (int) ($supplierSummary['rows'] ?? -1), $expected['supplier_rows'] ?? null);
        $this->metricCheck($checks, $errors, 'pricing.supplier_unique_codes', (int) ($supplierSummary['unique_codes'] ?? -1), $expected['supplier_unique_codes'] ?? null);

        $this->hardCheck($checks, $errors, 'catalogue.unique_product_ids', $metrics['unique_product_ids'] === $metrics['eligible_products'], sprintf('Unique eligible external product IDs: %d/%d.', $metrics['unique_product_ids'], $metrics['eligible_products']));
        $this->hardCheck($checks, $errors, 'catalogue.unique_slugs', $metrics['unique_slugs'] === $metrics['eligible_products'], sprintf('Unique eligible slugs: %d/%d.', $metrics['unique_slugs'], $metrics['eligible_products']));
        $this->hardCheck($checks, $errors, 'catalogue.unique_variant_ids', $metrics['unique_variant_ids'] === $metrics['eligible_variants'], sprintf('Unique eligible variant IDs: %d/%d.', $metrics['unique_variant_ids'], $metrics['eligible_variants']));
        $this->hardCheck($checks, $errors, 'catalogue.unique_variant_skus', $metrics['unique_variant_skus'] === $metrics['eligible_variants'], sprintf('Unique eligible variant SKUs: %d/%d.', $metrics['unique_variant_skus'], $metrics['eligible_variants']));
        $this->hardCheck($checks, $errors, 'catalogue.product_invariants', $metrics['product_invariant_failures'] === [], $metrics['product_invariant_failures'] === [] ? 'All eligible mapped products satisfy production invariants.' : implode(' | ', $metrics['product_invariant_failures']));
        $this->hardCheck($checks, $errors, 'catalogue.excluded_cohort', $metrics['excluded_invariant_failures'] === [], $metrics['excluded_invariant_failures'] === [] ? 'The unresolved cohort remains excluded and contains unresolved pricing.' : implode(' | ', $metrics['excluded_invariant_failures']));
        $this->hardCheck($checks, $errors, 'catalogue.media_urls', $metrics['media_url_failures'] === [], $metrics['media_url_failures'] === [] ? 'All eligible image/document URLs are approved ARmedical upload URLs.' : implode(' | ', $metrics['media_url_failures']));

        $pricingSummary = is_array($map['pricing_summary'] ?? null) ? $map['pricing_summary'] : [];
        $this->hardCheck($checks, $errors, 'pricing.summary_matched', (int) ($pricingSummary['matched_variants'] ?? -1) === $metrics['eligible_variants'], 'pricing_summary matched_variants must equal the eligible variant cohort.');
        $this->hardCheck($checks, $errors, 'pricing.summary_unmatched', (int) ($pricingSummary['unmatched_variants'] ?? -1) === $metrics['unmatched_variants'], 'pricing_summary unmatched_variants must equal the excluded unresolved variant count.');
        $this->hardCheck($checks, $errors, 'pricing.summary_products', (int) ($pricingSummary['fully_priced_products'] ?? -1) === $metrics['eligible_products'] && (int) ($pricingSummary['unpriced_products'] ?? -1) === $metrics['excluded_products'], 'pricing_summary product cohort counts must match the frozen eligible/excluded split.');

        $this->databaseChecks($checks, $errors, $metrics, $expected, $eligible);
        $this->storageChecks($checks, $errors, $expected, max(0, $minimumFreeMiB));
        $this->deploymentConfigChecks($checks, $errors);

        if ($probeImageCount > 0) {
            $this->probeImages($checks, $errors, $metrics['image_urls'], $probeImageCount, max(1, $probeTimeoutSeconds));
        } else {
            $checks[] = ['name' => 'network.image_probe', 'status' => 'SKIPPED', 'message' => 'Image probe was not requested.'];
        }

        if ($probeDocumentCount > 0) {
            $this->probeDocuments($checks, $errors, $metrics['document_urls'], $probeDocumentCount, max(1, $probeTimeoutSeconds));
        } else {
            $checks[] = ['name' => 'network.document_probe', 'status' => 'SKIPPED', 'message' => 'Document probe was not requested.'];
        }

        return [
            'source' => self::SOURCE,
            'mode' => 'production_preflight',
            'database_writes' => false,
            'filesystem_writes' => false,
            'map_sha256' => $mapSha256,
            'environment' => app()->environment(),
            'metrics' => $metrics,
            'checks' => $checks,
            'errors' => array_values(array_unique($errors)),
            'review_items' => array_values(array_unique(array_map('trim', $review))),
            'source_blocking_review_items' => array_values(array_unique(array_map('trim', $topLevelBlocking))),
            'ready_for_production_execution_patch' => $errors === [],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $products
     * @param  list<array<string,mixed>>  $eligible
     * @param  list<array<string,mixed>>  $excluded
     * @return array<string,mixed>
     */
    private function catalogueMetrics(array $products, array $eligible, array $excluded): array
    {
        $productIds = [];
        $slugs = [];
        $variantIds = [];
        $variantSkus = [];
        $imageUrls = [];
        $documentUrls = [];
        $productFailures = [];
        $excludedFailures = [];
        $mediaUrlFailures = [];
        $eligibleVariants = 0;
        $images = 0;
        $documents = 0;
        $vat8 = 0;
        $vat23 = 0;

        foreach ($eligible as $index => $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $name = $this->stringOrNull($product['name'] ?? null) ?? 'eligible product '.($index + 1);
            $externalId = $this->stringOrNull($product['external_id'] ?? null);
            $slug = $this->stringOrNull($product['slug'] ?? null);

            if ($externalId !== null) {
                $productIds[$externalId] = true;
            }
            if ($slug !== null) {
                $slugs[$slug] = true;
            }

            if (($mapped['source'] ?? null) !== self::SOURCE || ($product['external_source'] ?? null) !== self::SOURCE) {
                $productFailures[] = $name.': source/external_source must be armedical.';
            }
            if ($externalId === null || ! str_starts_with($externalId, 'armedical-')) {
                $productFailures[] = $name.': missing or invalid ARmedical external product ID.';
            }
            if ($slug === null) {
                $productFailures[] = $name.': missing product slug.';
            }
            if (($product['status'] ?? null) !== 'draft') {
                $productFailures[] = $name.': mapped product status must be draft.';
            }
            if ($this->stringList($mapped['blocking_review_items'] ?? null) !== []) {
                $productFailures[] = $name.': eligible product still contains blocking review items.';
            }
            if (! $this->productImporter->isFullyPriced($mapped)) {
                $productFailures[] = $name.': eligible product is no longer fully priced.';
            }

            $variants = array_values(array_filter($mapped['variants'] ?? [], 'is_array'));
            if ($variants === []) {
                $productFailures[] = $name.': no variants.';
            }

            $defaultCount = 0;
            foreach ($variants as $variant) {
                $eligibleVariants++;
                $variantId = $this->stringOrNull($variant['external_variant_id'] ?? null);
                $sku = $this->stringOrNull($variant['sku'] ?? null);
                $net = $this->positiveIntOrNull($variant['price_net_minor'] ?? null);
                $gross = $this->positiveIntOrNull($variant['price_gross_minor'] ?? null);
                $vat = is_numeric($variant['vat_rate'] ?? null) ? (int) $variant['vat_rate'] : null;

                if ($variantId !== null) {
                    $variantIds[$variantId] = true;
                }
                if ($sku !== null) {
                    $variantSkus[$sku] = true;
                }
                if (($variant['is_default'] ?? false) === true) {
                    $defaultCount++;
                }

                if ($variantId === null || ($externalId !== null && ! str_starts_with($variantId, $externalId.'-'))) {
                    $productFailures[] = $name.': variant external ID is missing or outside the product namespace.';
                }
                if ($sku === null || ! str_starts_with($sku, 'ARMEDICAL-')) {
                    $productFailures[] = $name.': variant SKU is missing or outside the ARMEDICAL namespace.';
                }
                if (($variant['status'] ?? null) !== 'draft') {
                    $productFailures[] = $name.': mapped variant status must be draft.';
                }
                if (($variant['currency'] ?? null) !== 'PLN') {
                    $productFailures[] = $name.': mapped variant currency must be PLN.';
                }
                if (($variant['pricing_resolution']['status'] ?? null) !== 'matched') {
                    $productFailures[] = $name.': variant pricing_resolution must be matched.';
                }
                if ($net === null || $gross === null || ! in_array($vat, [8, 23], true)) {
                    $productFailures[] = $name.': variant price/VAT is incomplete.';
                } elseif ($gross !== (int) round($net * (100 + $vat) / 100)) {
                    $productFailures[] = $name.': gross price does not reconcile to supplier net + VAT.';
                }

                if ($vat === 8) {
                    $vat8++;
                } elseif ($vat === 23) {
                    $vat23++;
                }
            }

            if ($defaultCount !== 1) {
                $productFailures[] = $name.': exactly one default variant is required; got '.$defaultCount.'.';
            }

            foreach (array_values(array_filter($mapped['images'] ?? [], 'is_array')) as $image) {
                $images++;
                $url = $this->stringOrNull($image['source_url'] ?? null);
                if ($url !== null) {
                    $imageUrls[] = $url;
                }
                if (! $this->approvedMediaUrl($url)) {
                    $mediaUrlFailures[] = $name.': unapproved image URL '.($url ?? '(missing)').'.';
                }
            }

            foreach (array_values(array_filter($mapped['documents'] ?? [], 'is_array')) as $document) {
                $documents++;
                $url = $this->stringOrNull($document['source_url'] ?? null);
                if ($url !== null) {
                    $documentUrls[] = $url;
                }
                if (! $this->approvedMediaUrl($url)) {
                    $mediaUrlFailures[] = $name.': unapproved document URL '.($url ?? '(missing)').'.';
                }
            }
        }

        foreach ($excluded as $index => $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $name = $this->stringOrNull($product['name'] ?? null) ?? 'excluded product '.($index + 1);
            $unmatched = 0;
            foreach (array_values(array_filter($mapped['variants'] ?? [], 'is_array')) as $variant) {
                if (($variant['pricing_resolution']['status'] ?? null) !== 'matched') {
                    $unmatched++;
                }
            }
            if ($unmatched === 0 && $this->stringList($mapped['blocking_review_items'] ?? null) === []) {
                $excludedFailures[] = $name.': excluded product no longer contains unresolved pricing or a blocking source-data review item.';
            }
        }

        $pricingSummary = [];
        $plannedVariants = 0;
        $unmatchedVariants = 0;
        foreach ($products as $mapped) {
            foreach (array_values(array_filter($mapped['variants'] ?? [], 'is_array')) as $variant) {
                $plannedVariants++;
                if (($variant['pricing_resolution']['status'] ?? null) !== 'matched') {
                    $unmatchedVariants++;
                }
            }
        }

        return [
            'source_products' => count($products),
            'planned_variants' => $plannedVariants,
            'eligible_products' => count($eligible),
            'eligible_variants' => $eligibleVariants,
            'excluded_products' => count($excluded),
            'unmatched_variants' => $unmatchedVariants,
            'images' => $images,
            'documents' => $documents,
            'vat_8_variants' => $vat8,
            'vat_23_variants' => $vat23,
            'unique_product_ids' => count($productIds),
            'unique_slugs' => count($slugs),
            'unique_variant_ids' => count($variantIds),
            'unique_variant_skus' => count($variantSkus),
            'product_ids' => array_keys($productIds),
            'slugs' => array_keys($slugs),
            'variant_ids' => array_keys($variantIds),
            'variant_skus' => array_keys($variantSkus),
            'image_urls' => $imageUrls,
            'document_urls' => $documentUrls,
            'product_invariant_failures' => array_values(array_unique($productFailures)),
            'excluded_invariant_failures' => array_values(array_unique($excludedFailures)),
            'media_url_failures' => array_values(array_unique($mediaUrlFailures)),
            'pricing_summary' => $pricingSummary,
        ];
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors @param array<string,mixed> $metrics @param array<string,int|string|null> $expected @param list<array<string,mixed>> $eligible */
    private function databaseChecks(array &$checks, array &$errors, array $metrics, array $expected, array $eligible): void
    {
        try {
            DB::connection()->getPdo();
            $this->hardCheck($checks, $errors, 'database.connection', true, 'Database connection succeeded.');
        } catch (Throwable $exception) {
            $this->hardCheck($checks, $errors, 'database.connection', false, 'Database connection failed: '.$exception->getMessage());
            return;
        }

        $expectedProducts = (int) ($expected['existing_products'] ?? 0);
        $expectedVariants = (int) ($expected['existing_variants'] ?? 0);
        $expectedImages = (int) ($expected['existing_images'] ?? 0);
        $expectedDocumentLinks = (int) ($expected['existing_document_links'] ?? 0);

        $sourceProducts = Product::withTrashed()->where('external_source', self::SOURCE);
        $actualProducts = (clone $sourceProducts)->count();
        $actualVariants = ProductVariant::withTrashed()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->count();
        $actualImages = ProductImage::query()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->count();
        $actualDocumentLinks = Product::withTrashed()->where('external_source', self::SOURCE)->pluck('description')->sum(
            static fn (mixed $description): int => is_string($description) ? substr_count($description, 'data-armedical-document-source=') : 0,
        );

        $this->hardCheck($checks, $errors, 'database.existing_products', $actualProducts === $expectedProducts, sprintf('Expected %d; actual %d.', $expectedProducts, $actualProducts));
        $this->hardCheck($checks, $errors, 'database.existing_variants', $actualVariants === $expectedVariants, sprintf('Expected %d; actual %d.', $expectedVariants, $actualVariants));
        $this->hardCheck($checks, $errors, 'database.existing_images', $actualImages === $expectedImages, sprintf('Expected %d; actual %d.', $expectedImages, $actualImages));
        $this->hardCheck($checks, $errors, 'database.existing_document_links', $actualDocumentLinks === $expectedDocumentLinks, sprintf('Expected %d; actual %d.', $expectedDocumentLinks, $actualDocumentLinks));

        $approvedProductIds = array_fill_keys($metrics['product_ids'], true);
        $approvedVariantIds = array_fill_keys($metrics['variant_ids'], true);
        $approvedImageUrls = array_fill_keys($metrics['image_urls'], true);

        $unexpectedProductIds = Product::withTrashed()->where('external_source', self::SOURCE)
            ->pluck('external_id')->filter()->map(static fn (mixed $value): string => (string) $value)
            ->filter(static fn (string $value): bool => ! isset($approvedProductIds[$value]))->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.existing_product_ids', $unexpectedProductIds === [], $unexpectedProductIds === [] ? 'All existing ARmedical product IDs belong to the eligible frozen cohort.' : 'Unexpected ARmedical product IDs: '.implode(', ', $unexpectedProductIds));

        $unexpectedVariantIds = ProductVariant::withTrashed()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))
            ->pluck('external_variant_id')->filter()->map(static fn (mixed $value): string => (string) $value)
            ->filter(static fn (string $value): bool => ! isset($approvedVariantIds[$value]))->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.existing_variant_ids', $unexpectedVariantIds === [], $unexpectedVariantIds === [] ? 'All existing ARmedical variant IDs belong to the eligible frozen cohort.' : 'Unexpected ARmedical variant IDs: '.implode(', ', $unexpectedVariantIds));

        $unexpectedImageUrls = ProductImage::query()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))
            ->pluck('source_url')->filter()->map(static fn (mixed $value): string => (string) $value)
            ->filter(static fn (string $value): bool => ! isset($approvedImageUrls[$value]))->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.existing_image_urls', $unexpectedImageUrls === [], $unexpectedImageUrls === [] ? 'All existing ARmedical image source URLs belong to the eligible frozen cohort.' : 'Unexpected ARmedical image URLs: '.implode(', ', array_slice($unexpectedImageUrls, 0, 20)));

        $resolvedSlugOwners = [];
        $slugResolutions = [];

        foreach ($eligible as $mapped) {
            $productData = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $externalId = $this->stringOrNull($productData['external_id'] ?? null);
            $baseSlug = $this->stringOrNull($productData['slug'] ?? null);

            if ($externalId === null || $baseSlug === null) {
                continue;
            }

            $existingProductId = Product::withTrashed()
                ->where('external_source', self::SOURCE)
                ->where('external_id', $externalId)
                ->value('id');
            $resolvedSlug = $this->productImporter->resolveUniqueProductSlug(
                $baseSlug,
                is_numeric($existingProductId) ? (int) $existingProductId : null,
                $externalId,
            );
            $resolvedSlugOwners[$resolvedSlug][] = $externalId;

            if ($resolvedSlug !== $baseSlug) {
                $slugResolutions[] = $baseSlug.' -> '.$resolvedSlug;
            }
        }

        $resolvedSlugCollisions = [];
        foreach ($resolvedSlugOwners as $slug => $owners) {
            if (count($owners) > 1) {
                $resolvedSlugCollisions[] = $slug.' ['.implode(', ', $owners).']';
            }
        }

        $this->hardCheck(
            $checks,
            $errors,
            'database.slug_collisions',
            $resolvedSlugCollisions === [],
            $resolvedSlugCollisions !== []
                ? 'Resolved product slug collisions remain: '.implode(', ', $resolvedSlugCollisions)
                : ($slugResolutions === []
                    ? 'No non-ARmedical product slug collisions.'
                    : 'Non-ARmedical base slug collision(s) will be resolved deterministically: '.implode('; ', $slugResolutions)),
        );

        $externalIdCollisions = Product::withTrashed()->whereIn('external_id', $metrics['product_ids'])
            ->where(function ($query): void {
                $query->whereNull('external_source')->orWhere('external_source', '!=', self::SOURCE);
            })->pluck('external_id')->filter()->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.external_id_collisions', $externalIdCollisions === [], $externalIdCollisions === [] ? 'No non-ARmedical external product ID collisions.' : 'Colliding external product IDs: '.implode(', ', $externalIdCollisions));

        $skuCollisions = ProductVariant::withTrashed()->whereIn('sku', $metrics['variant_skus'])
            ->whereHas('product', function ($query): void {
                $query->withTrashed()->where(function ($productQuery): void {
                    $productQuery->whereNull('external_source')->orWhere('external_source', '!=', self::SOURCE);
                });
            })->pluck('sku')->filter()->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.variant_sku_collisions', $skuCollisions === [], $skuCollisions === [] ? 'No non-ARmedical variant SKU collisions.' : 'Colliding variant SKUs: '.implode(', ', $skuCollisions));

        $variantIdCollisions = ProductVariant::withTrashed()->whereIn('external_variant_id', $metrics['variant_ids'])
            ->whereHas('product', function ($query): void {
                $query->withTrashed()->where(function ($productQuery): void {
                    $productQuery->whereNull('external_source')->orWhere('external_source', '!=', self::SOURCE);
                });
            })->pluck('external_variant_id')->filter()->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.variant_external_id_collisions', $variantIdCollisions === [], $variantIdCollisions === [] ? 'No non-ARmedical external variant ID collisions.' : 'Colliding external variant IDs: '.implode(', ', $variantIdCollisions));

        $sourceDraftProducts = Product::withTrashed()->where('external_source', self::SOURCE)->where('status', ProductStatus::DRAFT->value)->count();
        $sourceDraftVariants = ProductVariant::withTrashed()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->where('status', ProductVariantStatus::DRAFT->value)->count();
        $sourceOutOfStock = ProductVariant::withTrashed()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->where('stock_status', StockStatus::OUT_OF_STOCK->value)->count();
        $this->hardCheck($checks, $errors, 'database.draft_only_products', $sourceDraftProducts === $actualProducts, sprintf('Draft ARmedical products: %d/%d.', $sourceDraftProducts, $actualProducts));
        $this->hardCheck($checks, $errors, 'database.draft_only_variants', $sourceDraftVariants === $actualVariants, sprintf('Draft ARmedical variants: %d/%d.', $sourceDraftVariants, $actualVariants));
        $this->hardCheck($checks, $errors, 'database.conservative_stock', $sourceOutOfStock === $actualVariants, sprintf('Out-of-stock ARmedical variants: %d/%d.', $sourceOutOfStock, $actualVariants));
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors @param array<string,int|string|null> $expected */
    private function storageChecks(array &$checks, array &$errors, array $expected, int $minimumFreeMiB): void
    {
        $disk = config('filesystems.disks.public');
        $driver = is_array($disk) ? ($disk['driver'] ?? null) : null;
        $root = is_array($disk) ? ($disk['root'] ?? null) : null;
        $this->hardCheck($checks, $errors, 'storage.public_disk', is_array($disk), 'The public filesystem disk must be configured.');
        $this->hardCheck($checks, $errors, 'storage.public_driver', $driver === 'local', 'ARmedical production media import expects the public disk to use the shared local storage volume.');

        if (! is_string($root) || trim($root) === '') {
            $this->hardCheck($checks, $errors, 'storage.public_root', false, 'Public local disk root is missing.');
            return;
        }

        $this->hardCheck($checks, $errors, 'storage.public_root', is_dir($root), 'Public disk root: '.$root);
        $this->hardCheck($checks, $errors, 'storage.public_writable', is_dir($root) && is_writable($root), 'Public disk root must be writable by the app container.');

        $free = @disk_free_space($root);
        $minimumBytes = $minimumFreeMiB * 1024 * 1024;
        $this->hardCheck($checks, $errors, 'storage.free_space', is_float($free) && $free >= $minimumBytes, is_float($free) ? sprintf('Free space %.1f MiB; minimum %d MiB.', $free / 1024 / 1024, $minimumFreeMiB) : 'Unable to determine free space on the public disk.');

        try {
            $url = Storage::disk('public')->url('products/armedical/preflight.txt');
            $this->hardCheck($checks, $errors, 'storage.public_url', is_string($url) && $url !== '', 'Public media URL generation: '.$url);
        } catch (Throwable $exception) {
            $this->hardCheck($checks, $errors, 'storage.public_url', false, 'Unable to generate public media URL: '.$exception->getMessage());
        }

        $files = Storage::disk('public')->allFiles('products/armedical');
        $documentFiles = array_values(array_filter($files, static fn (string $path): bool => str_contains($path, '/documents/')));
        $imageFiles = array_values(array_filter($files, static fn (string $path): bool => ! str_contains($path, '/documents/')));
        $expectedImageFiles = (int) ($expected['existing_images'] ?? 0);
        $expectedDocumentFiles = (int) ($expected['existing_document_links'] ?? 0);
        $this->hardCheck($checks, $errors, 'storage.existing_image_files', count($imageFiles) === $expectedImageFiles, sprintf('Expected %d existing ARmedical image files; actual %d.', $expectedImageFiles, count($imageFiles)));
        $this->hardCheck($checks, $errors, 'storage.existing_document_files', count($documentFiles) === $expectedDocumentFiles, sprintf('Expected %d existing ARmedical document files; actual %d.', $expectedDocumentFiles, count($documentFiles)));

        $zeroByte = [];
        foreach ($files as $path) {
            if (Storage::disk('public')->size($path) === 0) {
                $zeroByte[] = $path;
            }
        }
        $this->hardCheck($checks, $errors, 'storage.zero_byte_files', $zeroByte === [], $zeroByte === [] ? 'No zero-byte ARmedical media files.' : 'Zero-byte ARmedical files: '.implode(', ', array_slice($zeroByte, 0, 20)));
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors */
    private function deploymentConfigChecks(array &$checks, array &$errors): void
    {
        $path = base_path('docker-compose.prod.yml');
        if (! is_file($path)) {
            $this->hardCheck($checks, $errors, 'deployment.shared_storage', false, 'docker-compose.prod.yml is unavailable for shared-storage verification.');
            return;
        }

        $contents = (string) file_get_contents($path);
        $sharedMountCount = substr_count($contents, 'app_storage:/var/www/html/storage');
        $hasWebLink = str_contains($contents, 'ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage');
        $this->hardCheck($checks, $errors, 'deployment.shared_storage', $sharedMountCount >= 2, 'Shared app_storage mount occurrences: '.$sharedMountCount.'.');
        $this->hardCheck($checks, $errors, 'deployment.public_storage_link', $hasWebLink, 'Production web service must expose storage/app/public through public/storage.');
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors @param list<string> $urls */
    private function probeImages(array &$checks, array &$errors, array $urls, int $count, int $timeoutSeconds): void
    {
        $selected = $this->probeSelection($urls, $count);
        if ($selected === []) {
            $this->hardCheck($checks, $errors, 'network.image_probe', false, 'No eligible ARmedical image URLs are available for probing.');
            return;
        }

        $failures = [];
        foreach ($selected as $url) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8', 'Referer' => 'https://armedical.pl/'])
                    ->timeout($timeoutSeconds)->get($this->encodedUrl($url));
                $contentType = strtolower((string) $response->header('Content-Type'));
                if (! $response->successful() || $response->body() === '' || ! str_starts_with($contentType, 'image/')) {
                    $failures[] = $url.' [HTTP '.$response->status().'; '.$contentType.']';
                }
            } catch (Throwable $exception) {
                $failures[] = $url.' ['.$exception->getMessage().']';
            }
        }

        $this->hardCheck($checks, $errors, 'network.image_probe', $failures === [], $failures === [] ? sprintf('Fetched %d/%d ARmedical image probes into memory only.', count($selected), count($selected)) : implode(' | ', $failures));
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors @param list<string> $urls */
    private function probeDocuments(array &$checks, array &$errors, array $urls, int $count, int $timeoutSeconds): void
    {
        $selected = $this->probeSelection($urls, $count);
        if ($selected === []) {
            $this->hardCheck($checks, $errors, 'network.document_probe', false, 'No eligible ARmedical document URLs are available for probing.');
            return;
        }

        $failures = [];
        foreach ($selected as $url) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/octet-stream,*/*;q=0.8', 'Referer' => 'https://armedical.pl/'])
                    ->timeout($timeoutSeconds)->get($this->encodedUrl($url));
                $contentType = strtolower((string) $response->header('Content-Type'));
                $body = $response->body();
                if (! $response->successful() || $body === '' || str_contains($contentType, 'text/html')) {
                    $failures[] = $url.' [HTTP '.$response->status().'; '.$contentType.']';
                }
            } catch (Throwable $exception) {
                $failures[] = $url.' ['.$exception->getMessage().']';
            }
        }

        $this->hardCheck($checks, $errors, 'network.document_probe', $failures === [], $failures === [] ? sprintf('Fetched %d/%d ARmedical document probes into memory only.', count($selected), count($selected)) : implode(' | ', $failures));
    }

    /** @param list<string> $urls @return list<string> */
    private function probeSelection(array $urls, int $count): array
    {
        $unique = array_values(array_unique(array_filter($urls, static fn (string $url): bool => trim($url) !== '')));
        if ($unique === [] || $count <= 0) {
            return [];
        }

        $selected = [];
        foreach ($unique as $url) {
            if (preg_match('/[^\x00-\x7F]/', $url) === 1) {
                $selected[] = $url;
                break;
            }
        }

        $total = count($unique);
        $slots = max(1, $count);
        for ($i = 0; $i < $slots && count($selected) < $count; $i++) {
            $index = $slots === 1 ? 0 : (int) round(($total - 1) * $i / ($slots - 1));
            $url = $unique[$index];
            if (! in_array($url, $selected, true)) {
                $selected[] = $url;
            }
        }

        foreach ($unique as $url) {
            if (count($selected) >= $count) {
                break;
            }
            if (! in_array($url, $selected, true)) {
                $selected[] = $url;
            }
        }

        return array_slice($selected, 0, $count);
    }

    private function approvedMediaUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::ALLOWED_MEDIA_HOSTS, true)
            && str_starts_with((string) ($parts['path'] ?? ''), '/wp-content/uploads/');
    }

    private function encodedUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $segments = explode('/', (string) ($parts['path'] ?? ''));
        $encodedPath = implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), $segments));
        $result = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $result .= ':'.$parts['port'];
        }
        $result .= $encodedPath;
        if (isset($parts['query'])) {
            $result .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $result .= '#'.$parts['fragment'];
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors */
    private function metricCheck(array &$checks, array &$errors, string $name, int $actual, mixed $expected): void
    {
        $wanted = is_numeric($expected) ? (int) $expected : -1;
        $this->hardCheck($checks, $errors, $name, $wanted < 0 || $actual === $wanted, sprintf('Expected %d; actual %d.', $wanted, $actual));
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors */
    private function fingerprintCheck(array &$checks, array &$errors, string $name, string $actual, ?string $expected, string $label): void
    {
        $pass = $expected === null || ($actual !== '' && hash_equals(strtolower($expected), strtolower($actual)));
        $this->hardCheck($checks, $errors, $name, $pass, $label.' SHA-256 expected '.($expected ?? '(not enforced)').'; actual '.($actual !== '' ? $actual : '(missing)').'.');
    }

    /** @param list<array<string,mixed>> $checks @param list<string> $errors */
    private function hardCheck(array &$checks, array &$errors, string $name, bool $pass, string $message): void
    {
        $checks[] = ['name' => $name, 'status' => $pass ? 'PASS' : 'FAIL', 'message' => $message];
        if (! $pass) {
            $errors[] = $name.': '.$message;
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value), static fn (string $item): bool => $item !== ''));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }
}
