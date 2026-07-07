<?php

declare(strict_types=1);

namespace App\Services\Apolonia;

use App\Enums\AttributeDisplayType;
use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ApoloniaProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = ['www.apolonia.com.pl', 'apolonia.com.pl'];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * @param  array<string, mixed>  $scraped
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(
        array $scraped,
        ProductStatus $status = ProductStatus::DRAFT,
        bool $importImages = true,
        ?int $imageLimit = 10,
    ): array {
        $this->warnings = [];
        $externalId = $this->externalProductId($scraped);

        $product = DB::transaction(function () use ($scraped, $externalId, $status, $importImages, $imageLimit): Product {
            $product = $this->resolveProduct($scraped, $externalId, $status);

            $this->syncCategories($product, $scraped);
            $this->syncProductAttributes($product, $scraped);
            $this->syncVariants($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages($product, $scraped, $imageLimit);
            }

            return $product->fresh([
                'categories.parent',
                'attributeValues.attribute',
                'images',
                'variants.attributeValues.attribute',
            ]);
        });

        return [
            'product' => $product,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function resolveProduct(array $scraped, string $externalId, ProductStatus $status): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'apolonia')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'apolonia-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'apolonia-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'Apolonia product '.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->stringOrNull($scraped['seo_description'] ?? null),
            'status' => $status,
            'external_source' => 'apolonia',
            'external_id' => $externalId,
            'external_parent_sku' => $this->parentSku($scraped, $externalId),
        ];

        if ($product !== null) {
            if ($product->trashed()) {
                $product->restore();
            }

            $product->update($attributes);

            return $product;
        }

        return Product::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncCategories(Product $product, array $scraped): void
    {
        $categoryNames = $this->categoryNames($scraped);

        if ($categoryNames === []) {
            return;
        }

        $parent = null;
        $resolved = [];

        foreach ($categoryNames as $index => $categoryName) {
            $parent = $this->resolveCategory($categoryName, $parent, array_slice($categoryNames, 0, $index + 1));
            $resolved[] = $parent;
        }

        $leafCategory = end($resolved);

        if (! $leafCategory instanceof Category) {
            return;
        }

        $syncPayload = [];

        foreach ($resolved as $category) {
            $syncPayload[$category->id] = [
                'is_primary' => $category->is($leafCategory),
            ];
        }

        $product->categories()->sync($syncPayload);
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'apolonia-category-'.substr(sha1(implode('|', $pathSegments)), 0, 10);
        }

        $category = Category::withTrashed()->where('slug', $baseSlug)->first();

        if ($category !== null && $parent !== null && $category->parent_id !== null && $category->parent_id !== $parent->id) {
            $pathSlug = Str::slug(implode(' ', $pathSegments));
            $category = Category::withTrashed()->where('slug', $pathSlug)->first();
            $baseSlug = $pathSlug ?: $baseSlug;
        }

        $attributes = [
            'parent_id' => $parent?->id,
            'name' => $name,
            'status' => CategoryStatus::ACTIVE,
        ];

        if ($category !== null) {
            if ($category->trashed()) {
                $category->restore();
            }

            $category->update($attributes);

            return $category;
        }

        return Category::query()->create($attributes + [
            'slug' => $this->uniqueCategorySlug($baseSlug),
        ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncProductAttributes(Product $product, array $scraped): void
    {
        $values = [];

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null || ! $this->isSafeFilterAttributeValue($value)) {
                continue;
            }

            $values[] = $this->resolveAttributeValue($label, $value);
        }

        $syncIds = [];

        foreach ($values as $value) {
            $syncIds[$value->id] = [];
        }

        $product->attributeValues()->sync($syncIds);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncVariants(Product $product, array $scraped, ProductStatus $status): void
    {
        $candidates = $this->variantCandidates($scraped);
        $seenExternalVariantIds = [];
        $syncedVariantIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $index => $candidate) {
            $sourceExternalId = $this->stringOrNull($candidate['external_variant_id'] ?? null)
                ?: (string) ($index + 1);
            $externalVariantId = $this->limitDatabaseString('apolonia-'.$product->external_id.'-'.$sourceExternalId);

            if (isset($seenExternalVariantIds[$externalVariantId])) {
                continue;
            }

            $seenExternalVariantIds[$externalVariantId] = true;

            $attributeValueIds = $this->variantAttributeValueIds($candidate);
            $label = $this->stringOrNull($candidate['label'] ?? $candidate['name'] ?? null);
            $grossAmount = $this->moneyToMinorUnits($candidate['price_gross_amount'] ?? $scraped['price_gross_amount'] ?? null);
            $vatRate = VatRate::VAT_23;
            $isDefault = ! $defaultAssigned;

            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();

            $sku = $this->variantSku($scraped, $candidate, $product->external_id, $label, $sourceExternalId, $variant?->id);

            $attributes = [
                'sku' => $sku,
                'status' => $this->variantStatus($status),
                'price_net_amount' => $grossAmount !== null ? $vatRate->netFromGross($grossAmount) : null,
                'price_gross_amount' => $grossAmount,
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($candidate['availability'] ?? $scraped['availability'] ?? null),
                'is_default' => $isDefault,
            ];

            if ($variant !== null) {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            } else {
                $variant = $product->variants()->create($attributes + [
                    'external_variant_id' => $externalVariantId,
                ]);
            }

            $variant->attributeValues()->sync($attributeValueIds);
            $syncedVariantIds[] = $variant->id;
            $defaultAssigned = true;
        }

        if ($syncedVariantIds === []) {
            $variant = $this->syncDefaultVariant($product, $scraped, $status);
            $syncedVariantIds[] = $variant->id;
        }

        $product->variants()
            ->whereNotIn('id', $syncedVariantIds)
            ->update([
                'status' => ProductVariantStatus::ARCHIVED,
                'is_default' => false,
            ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncDefaultVariant(Product $product, array $scraped, ProductStatus $status): ProductVariant
    {
        $externalVariantId = $this->limitDatabaseString('apolonia-'.$product->external_id.'-default');
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = VatRate::VAT_23;
        $sku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->stringOrNull($scraped['ean'] ?? null)
                ?: 'APOLONIA-'.$product->external_id;

        $variant = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->where('external_variant_id', $externalVariantId)
            ->first();

        $attributes = [
            'sku' => $this->uniqueVariantSku($sku, $variant?->id),
            'status' => $this->variantStatus($status),
            'price_net_amount' => $grossAmount !== null ? $vatRate->netFromGross($grossAmount) : null,
            'price_gross_amount' => $grossAmount,
            'currency' => Currency::PLN,
            'vat_rate' => $vatRate,
            'stock_status' => $this->stockStatus($scraped['availability'] ?? null),
            'is_default' => true,
        ];

        if ($variant !== null) {
            if ($variant->trashed()) {
                $variant->restore();
            }

            $variant->update($attributes);
            $variant->attributeValues()->sync([]);

            return $variant;
        }

        return $product->variants()->create($attributes + [
            'external_variant_id' => $externalVariantId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<int>
     */
    private function variantAttributeValueIds(array $candidate): array
    {
        $ids = [];

        foreach (($candidate['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null)
                ?: $this->stringOrNull($attributeData['name'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null)
                ?: $this->stringOrNull($candidate['label'] ?? null);

            if ($label === null || $value === null || ! $this->isSafeFilterAttributeValue($value)) {
                continue;
            }

            $ids[] = $this->resolveAttributeValue($label, $value)->id;
        }

        return array_values(array_unique($ids));
    }

    private function resolveAttributeValue(string $label, string $value): AttributeValue
    {
        $attributeSlug = Str::slug($label) ?: substr(sha1($label), 0, 10);
        $externalAttributeId = 'apolonia-'.$attributeSlug;
        $valueSlug = Str::slug($value) ?: substr(sha1($value), 0, 10);

        $attribute = Attribute::query()->firstOrCreate(
            ['external_attribute_id' => $externalAttributeId],
            [
                'name' => $label,
                'slug' => $this->uniqueAttributeSlug($attributeSlug),
                'display_type' => $attributeSlug === 'kolor' ? AttributeDisplayType::COLOR_SWATCH : AttributeDisplayType::SELECT,
            ],
        );

        if ($attribute->name !== $label) {
            $attribute->update(['name' => $label]);
        }

        return AttributeValue::query()->firstOrCreate(
            [
                'attribute_id' => $attribute->id,
                'external_option_id' => $this->limitDatabaseString('apolonia-'.$attributeSlug.'-'.$valueSlug),
            ],
            [
                'value' => $value,
                'slug' => $this->uniqueAttributeValueSlug($attribute, $valueSlug),
                'sort_order' => 0,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(Product $product, array $scraped, ?int $imageLimit): void
    {
        $images = array_values(array_filter(
            $scraped['images'] ?? [],
            static fn (mixed $image): bool => is_array($image) && is_string($image['url'] ?? null)
        ));

        if ($imageLimit !== null) {
            $images = array_slice($images, 0, max(0, $imageLimit));
        }

        $syncedImageIds = [];

        foreach ($images as $index => $imageData) {
            /** @var array<string, mixed> $imageData */
            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null) {
                continue;
            }

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/apolonia/'.$product->external_id.'/gallery',
                    'public',
                    self::IMAGE_ALLOWED_HOSTS,
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped: '.$url.' — '.$exception->getMessage();

                continue;
            }

            $image = ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'source_url' => $url,
                ],
                [
                    'disk' => $imported['disk'],
                    'path' => $imported['path'],
                    'mime_type' => $imported['mime_type'],
                    'file_size' => $imported['file_size'],
                    'sha256' => $imported['sha256'],
                    'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                    'title' => $this->stringOrNull($imageData['title'] ?? null),
                    'sort_order' => $index,
                    'is_main' => $index === 0,
                ]
            );

            $syncedImageIds[] = $image->id;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->when($syncedImageIds !== [], fn ($query) => $query->whereNotIn('id', $syncedImageIds))
            ->get()
            ->each(function (ProductImage $image): void {
                if ($image->path !== '' && Storage::disk($image->disk)->exists($image->path)) {
                    Storage::disk($image->disk)->delete($image->path);
                }

                $image->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function variantCandidates(array $scraped): array
    {
        $candidates = array_values(array_filter(
            $scraped['variant_candidates'] ?? [],
            static fn (mixed $candidate): bool => is_array($candidate)
        ));

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function categoryNames(array $scraped): array
    {
        $candidates = [];

        foreach (['source_category_path', 'categories'] as $key) {
            if (! is_array($scraped[$key] ?? null)) {
                continue;
            }

            foreach ($scraped[$key] as $categoryName) {
                if (is_string($categoryName) && trim($categoryName) !== '') {
                    $candidates[] = trim($categoryName);
                }
            }

            if ($candidates !== []) {
                break;
            }
        }

        if ($candidates === [] && is_string($scraped['category'] ?? null) && trim((string) $scraped['category']) !== '') {
            $candidates[] = trim((string) $scraped['category']);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null)
            ?: $this->productIdFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->productIdFromUrl($this->stringOrNull($scraped['source_url'] ?? null));

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $name = $this->stringOrNull($scraped['name'] ?? null) ?: 'apolonia-product';

        return substr(sha1($name.'|'.json_encode($scraped)), 0, 16);
    }

    private function productIdFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (preg_match('/product-pol-(\d+)-/u', $url, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('/^product-pol-\d+-(?P<slug>.+)\.html$/u', $path, $matches) === 1) {
            return Str::slug((string) $matches['slug']) ?: null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $text = $this->stringOrNull($scraped['short_description'] ?? null)
            ?: $this->stringOrNull($scraped['seo_description'] ?? null);

        return $text !== null ? '<p>'.e(Str::limit($text, 300, '')).'</p>' : null;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped): ?string
    {
        $html = $this->stringOrNull($scraped['description_html'] ?? null);

        if ($html === null) {
            $plain = $this->stringOrNull($scraped['description_plain'] ?? null);
            $html = $plain !== null ? '<p>'.e($plain).'</p>' : null;
        }

        $sections = [];

        if ($html !== null) {
            $sections[] = '<section class="apolonia-description"><h2>Opis produktu</h2>'.$this->cleanHtml($html).'</section>';
        }

        $detailsRows = [];

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null) {
                continue;
            }

            $detailsRows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
        }

        if ($detailsRows !== []) {
            $sections[] = '<section class="apolonia-details"><h2>Dane produktu</h2><table><tbody>'.implode('', $detailsRows).'</tbody></table></section>';
        }

        $sourceUrl = $this->stringOrNull($scraped['canonical_url'] ?? null) ?: $this->stringOrNull($scraped['source_url'] ?? null);

        if ($sourceUrl !== null) {
            $sections[] = '<section class="apolonia-source"><h2>Źródło</h2><p>Dane produktu zaimportowane z Apolonia.</p></section>';
        }

        return $sections !== [] ? implode("\n", $sections) : null;
    }

    private function cleanHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#isu', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;

        return trim($html);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        return $this->limitDatabaseString(
            $this->stringOrNull($scraped['sku'] ?? null)
                ?: $this->stringOrNull($scraped['ean'] ?? null)
                    ?: 'APOLONIA-'.$externalId
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @param  array<string, mixed>  $candidate
     */
    private function variantSku(array $scraped, array $candidate, string $externalId, ?string $label, string $sourceExternalId, ?int $currentVariantId): string
    {
        $candidateSku = $this->stringOrNull($candidate['sku'] ?? null);

        if ($candidateSku !== null && str_contains($candidateSku, $externalId)) {
            return $this->uniqueVariantSku($this->limitDatabaseString($candidateSku), $currentVariantId);
        }

        $baseSku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->stringOrNull($scraped['ean'] ?? null)
                ?: 'APOLONIA';

        $variantPart = $label ?: $sourceExternalId;
        $qualifiedSku = $variantPart !== ''
            ? $baseSku.'-'.$externalId.'-'.$variantPart
            : $baseSku.'-'.$externalId;

        return $this->uniqueVariantSku($this->limitDatabaseString($qualifiedSku), $currentVariantId);
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'apolonia-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            $candidate = $suffix === 2
                ? $baseSlug.'-apolonia-'.$externalId
                : $baseSlug.'-apolonia-'.$externalId.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function productSlugExists(string $slug, ?int $currentProductId): bool
    {
        return Product::withTrashed()
            ->where('slug', $slug)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists();
    }

    private function uniqueCategorySlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'apolonia-category';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeSlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'apolonia-attribute';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Attribute::query()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueAttributeValueSlug(Attribute $attribute, string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'value';
        $candidate = $baseSlug;
        $suffix = 2;

        while (AttributeValue::query()->where('attribute_id', $attribute->id)->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueVariantSku(string $sku, ?int $currentVariantId): string
    {
        $baseSku = $this->limitDatabaseString($sku);
        $candidate = $baseSku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->when($currentVariantId !== null, fn ($query) => $query->whereKeyNot($currentVariantId))
            ->exists()) {
            $candidate = $this->limitDatabaseString($baseSku.'-'.$suffix++);
        }

        return $candidate;
    }

    private function variantStatus(ProductStatus $status): ProductVariantStatus
    {
        return match ($status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
    }

    private function stockStatus(mixed $availability): StockStatus
    {
        $availability = mb_strtolower((string) $availability);

        if (str_contains($availability, 'out_of_stock') || str_contains($availability, 'niedost')) {
            return StockStatus::OUT_OF_STOCK;
        }

        if (str_contains($availability, 'preorder')
            || str_contains($availability, 'pre_order')
            || str_contains($availability, 'available_on_backorder')
            || str_contains($availability, 'na zamowienie')
            || str_contains($availability, 'na zamówienie')) {
            return StockStatus::PREORDER;
        }

        return StockStatus::IN_STOCK;
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/[^0-9,.]/', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (int) round(((float) $value) * 100) : null;
    }

    private function isSafeFilterAttributeValue(string $value): bool
    {
        return mb_strlen($value) <= 190
            && ! str_contains($value, '<')
            && ! str_contains($value, '>')
            && substr_count($value, ' ') <= 30;
    }

    private function limitDatabaseString(string $value): string
    {
        return Str::limit($value, self::MAX_DATABASE_STRING_LENGTH, '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
