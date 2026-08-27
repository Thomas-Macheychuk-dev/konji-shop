<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\Storage\PublicFilesystemUrl;
use Illuminate\Support\Facades\Storage;

final class SigvarisProductionImportAudit
{
    private const SOURCE = 'sigvaris';

    /**
     * @param  list<array<string, mixed>>  $selected
     * @param  array{products:int, variants:int, images:int}  $expectedPost
     * @return array<string, mixed>
     */
    public function inspect(array $selected, array $expectedPost): array
    {
        $errors = [];
        $selectedProductIds = [];
        $expectedSelectedVariants = 0;
        $expectedSelectedImages = 0;
        $actualSelectedVariants = 0;
        $actualSelectedImages = 0;
        $expectedSelectedDocuments = 0;
        $actualSelectedDocuments = 0;
        $categoryAssignments = 0;

        foreach ($selected as $index => $mapped) {
            if (! is_array($mapped)) {
                continue;
            }

            $productData = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $externalId = $this->stringOrNull($productData['external_id'] ?? null);
            $name = $this->stringOrNull($productData['name'] ?? null) ?? 'product '.($index + 1);

            if ($externalId === null) {
                $errors[] = $name.': mapped product has no external ID.';

                continue;
            }

            $selectedProductIds[] = $externalId;
            $expectedVariants = array_values(array_filter($mapped['variants'] ?? [], 'is_array'));
            $expectedImages = array_values(array_filter($mapped['images'] ?? [], 'is_array'));
            $expectedSelectedVariants += count($expectedVariants);
            $expectedSelectedImages += count($expectedImages);

            $rows = Product::withTrashed()
                ->where('external_source', self::SOURCE)
                ->where('external_id', $externalId)
                ->get();

            if ($rows->count() !== 1) {
                $errors[] = sprintf('%s: expected exactly one Sigvaris product row for external ID %s; actual %d.', $name, $externalId, $rows->count());

                continue;
            }

            $product = $rows->first();

            if (! $product instanceof Product) {
                $errors[] = $name.': unable to load imported product.';

                continue;
            }

            if ($product->trashed()) {
                $errors[] = $name.': imported product is soft-deleted.';
            }

            if ($product->status !== ProductStatus::DRAFT) {
                $errors[] = $name.': imported product is not draft.';
            }

            if ($product->published_at !== null) {
                $errors[] = $name.': published_at must remain NULL.';
            }

            $expectedParentSku = $this->stringOrNull($productData['external_parent_sku'] ?? null);

            if ($expectedParentSku !== null && $product->external_parent_sku !== $expectedParentSku) {
                $errors[] = $name.': parent SKU does not match approved mapping.';
            }

            $product->load([
                'categories',
                'attributeValues.attribute',
                'images',
            ]);

            if ($product->categories->isEmpty()) {
                $errors[] = $name.': imported product has no categories.';
            }

            $primaryCategories = $product->categories->filter(
                static fn ($category): bool => (bool) ($category->pivot?->is_primary ?? false),
            );

            if ($primaryCategories->count() !== 1) {
                $errors[] = $name.': expected exactly one primary category.';
            }

            $categoryAssignments += $product->categories->count();

            $expectedProducer = null;

            foreach (array_values(array_filter($mapped['attributes'] ?? [], 'is_array')) as $attribute) {
                if (($attribute['source'] ?? null) !== 'source_manufacturer') {
                    continue;
                }

                foreach (array_values(array_filter($attribute['values'] ?? [], 'is_array')) as $value) {
                    $expectedProducer = $this->stringOrNull($value['value_label'] ?? null);

                    if ($expectedProducer !== null) {
                        break 2;
                    }
                }
            }

            $actualProducers = $product->attributeValues
                ->filter(static fn ($value): bool => $value->attribute?->name === 'Producent')
                ->pluck('value')
                ->map(static fn (mixed $value): string => (string) $value)
                ->values()
                ->all();

            if ($expectedProducer === null) {
                if ($actualProducers !== []) {
                    $errors[] = $name.': Producent must remain unset because the approved map has no source manufacturer.';
                }
            } elseif ($actualProducers !== [$expectedProducer]) {
                $errors[] = $name.': Producent does not exactly match the approved source manufacturer.';
            }

            $actualVariants = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->get();
            $actualSelectedVariants += $actualVariants->count();

            $expectedVariantsById = [];

            foreach ($expectedVariants as $variant) {
                $variantId = $this->stringOrNull($variant['external_variant_id'] ?? null);

                if ($variantId !== null) {
                    $expectedVariantsById[$variantId] = $variant;
                }
            }

            $actualVariantsById = [];

            foreach ($actualVariants as $variant) {
                if ($variant->trashed()) {
                    $errors[] = $name.': variant '.$variant->external_variant_id.' is soft-deleted.';
                }

                $variantId = $this->stringOrNull($variant->external_variant_id);

                if ($variantId === null) {
                    $errors[] = $name.': imported variant has no external ID.';

                    continue;
                }

                if (isset($actualVariantsById[$variantId])) {
                    $errors[] = $name.': duplicate imported variant external ID '.$variantId.'.';

                    continue;
                }

                $actualVariantsById[$variantId] = $variant;
            }

            $expectedVariantIds = array_keys($expectedVariantsById);
            $actualVariantIds = array_keys($actualVariantsById);
            sort($expectedVariantIds);
            sort($actualVariantIds);

            if ($expectedVariantIds !== $actualVariantIds) {
                $errors[] = $name.': imported variant IDs do not exactly match the approved mapping.';
            }

            foreach ($expectedVariantsById as $variantId => $expectedVariant) {
                $actualVariant = $actualVariantsById[$variantId] ?? null;

                if (! $actualVariant instanceof ProductVariant) {
                    continue;
                }

                $expectedSku = $this->stringOrNull($expectedVariant['sku'] ?? null);
                $expectedStatus = $this->stringOrNull($expectedVariant['status'] ?? null);

                if ($expectedSku !== null && $actualVariant->sku !== $expectedSku) {
                    $errors[] = $name.': variant '.$variantId.' SKU differs from approved mapping.';
                }

                if ($expectedStatus !== null && $actualVariant->status->value !== $expectedStatus) {
                    $errors[] = $name.': variant '.$variantId.' status differs from approved mapping.';
                }

                $expectedDefault = ($expectedVariant['is_default'] ?? false) === true;

                // Selling prices are governed by the official Sigvaris price-list
                // workflow, not by the scraped source-shop price embedded in the
                // frozen import map. Production import therefore validates that a
                // usable local price exists without requiring equality to the old
                // mapped shop price.
                if ($actualVariant->price_gross_amount === null || $actualVariant->price_gross_amount <= 0) {
                    $errors[] = $name.': variant '.$variantId.' has no valid local gross price.';
                }

                if ($actualVariant->price_net_amount === null || $actualVariant->price_net_amount <= 0) {
                    $errors[] = $name.': variant '.$variantId.' has no valid local net price.';
                }

                if (! in_array($actualVariant->vat_rate?->value, [8, 23], true)) {
                    $errors[] = $name.': variant '.$variantId.' has unsupported local VAT.';
                }

                if ($actualVariant->currency?->value !== 'PLN') {
                    $errors[] = $name.': variant '.$variantId.' local currency must be PLN.';
                }

                if ((bool) $actualVariant->is_default !== $expectedDefault) {
                    $errors[] = $name.': variant '.$variantId.' default flag differs from approved mapping.';
                }
            }

            $actualImages = ProductImage::query()
                ->where('product_id', $product->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            $actualSelectedImages += $actualImages->count();

            $expectedImageUrls = [];

            foreach ($expectedImages as $image) {
                $url = $this->stringOrNull($image['source_url'] ?? null);

                if ($url !== null) {
                    $expectedImageUrls[] = $url;
                }
            }

            $actualImageUrls = $actualImages
                ->pluck('source_url')
                ->filter()
                ->map(static fn (mixed $value): string => (string) $value)
                ->values()
                ->all();

            sort($expectedImageUrls);
            sort($actualImageUrls);

            if ($expectedImageUrls !== $actualImageUrls) {
                $errors[] = $name.': imported image source URLs do not exactly match the approved mapping.';
            }

            if ($actualImages->where('is_main', true)->count() !== 1) {
                $errors[] = $name.': expected exactly one main image.';
            }

            foreach ($actualImages as $image) {
                if ($image->disk !== 'public') {
                    $errors[] = $name.': image '.$image->id.' is not stored on the public disk.';

                    continue;
                }

                if (! str_starts_with((string) $image->path, 'products/sigvaris/'.$externalId.'/gallery/')) {
                    $errors[] = $name.': image '.$image->id.' has an unexpected storage path.';
                }

                if (! Storage::disk($image->disk)->exists($image->path)) {
                    $errors[] = $name.': image '.$image->id.' file is missing from storage.';
                }
            }

            $description = (string) $product->description;
            $decodedDescription = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (str_contains(strtolower($description), '<img')) {
                $errors[] = $name.': description still contains a remote/source image element.';
            }

            if (str_contains($decodedDescription, 'sklep-sigvaris.com/module/prestadogpsrmanager/download')) {
                $errors[] = $name.': description still contains an external Sigvaris GPSR document URL.';
            }

            foreach (array_values(array_filter($mapped['downloads'] ?? [], 'is_array')) as $resource) {
                $expectedSelectedDocuments++;
                $label = $this->stringOrNull($resource['label'] ?? null) ?: 'Instrukcja / dokument PDF';
                $href = $this->localDocumentHref($description, $externalId, $label);

                if ($href === null) {
                    $errors[] = $name.': description is missing local GPSR document '.$label.'.';

                    continue;
                }

                $storagePath = PublicFilesystemUrl::path($href);

                if ($storagePath === null || ! str_starts_with($storagePath, 'products/sigvaris/')) {
                    $errors[] = $name.': local GPSR document has an invalid href '.$href.'.';

                    continue;
                }

                if (! Storage::disk('public')->exists($storagePath)) {
                    $errors[] = $name.': local GPSR document file is missing from storage: '.$storagePath.'.';

                    continue;
                }

                $actualSelectedDocuments++;
            }
        }

        $globalProducts = Product::withTrashed()->where('external_source', self::SOURCE)->count();
        $globalVariants = ProductVariant::withTrashed()
            ->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))
            ->count();
        $globalImages = ProductImage::query()
            ->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))
            ->count();

        foreach ([
            'products' => $globalProducts,
            'variants' => $globalVariants,
            'images' => $globalImages,
        ] as $key => $actual) {
            $wanted = $expectedPost[$key];

            if ($actual !== $wanted) {
                $errors[] = sprintf('Global Sigvaris %s count mismatch: expected %d; actual %d.', $key, $wanted, $actual);
            }
        }

        return [
            'source' => self::SOURCE,
            'mode' => 'production_import_audit',
            'database_writes' => false,
            'image_writes' => false,
            'document_writes' => false,
            'selected_external_product_ids' => array_values(array_unique($selectedProductIds)),
            'metrics' => [
                'selected_products_expected' => count(array_unique($selectedProductIds)),
                'selected_products_found' => Product::query()
                    ->where('external_source', self::SOURCE)
                    ->whereIn('external_id', array_values(array_unique($selectedProductIds)))
                    ->count(),
                'selected_variants_expected' => $expectedSelectedVariants,
                'selected_variants_actual' => $actualSelectedVariants,
                'selected_images_expected' => $expectedSelectedImages,
                'selected_images_actual' => $actualSelectedImages,
                'selected_documents_expected' => $expectedSelectedDocuments,
                'selected_documents_actual' => $actualSelectedDocuments,
                'category_assignments' => $categoryAssignments,
                'global_products' => $globalProducts,
                'global_variants' => $globalVariants,
                'global_images' => $globalImages,
            ],
            'expected_post_counts' => $expectedPost,
            'errors' => array_values(array_unique($errors)),
            'passed' => $errors === [],
        ];
    }

    private function localDocumentHref(string $description, string $externalId, string $label): ?string
    {
        preg_match_all(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#isu',
            $description,
            $matches,
            PREG_SET_ORDER,
        );

        $expectedLabel = $this->normalizeLabel($label);
        $prefix = 'products/sigvaris/'.$externalId.'/documents/';

        foreach ($matches as $match) {
            $href = html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $anchorLabel = $this->normalizeLabel((string) ($match[2] ?? ''));
            $storagePath = PublicFilesystemUrl::path($href);

            if ($anchorLabel === $expectedLabel && $storagePath !== null && str_starts_with($storagePath, $prefix)) {
                return $href;
            }
        }

        return null;
    }

    private function normalizeLabel(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
