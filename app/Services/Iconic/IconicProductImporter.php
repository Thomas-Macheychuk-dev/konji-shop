<?php

declare(strict_types=1);

namespace App\Services\Iconic;

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
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class IconicProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * Approved Iconic source roots. Products may belong to more than one root.
     *
     * @var array<string, string>
     */
    private const ROOT_CATEGORY_MAP = [
        'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej' => 'Zaopatrzenie Stopy Cukrzycowej',
        'https://sklep.iconic.pl/produkty/podologia' => 'Podologia',
        'https://sklep.iconic.pl/produkty/zaopatrzenie-ortopedyczne-stopy' => 'Zaopatrzenie Ortopedyczne Stopy',
        'https://sklep.iconic.pl/produkty/zaopatrzenie-po-zabiegach-na-hallux-valgus' => 'Zaopatrzenie po operacjach Hallux Valgus',
        'https://sklep.iconic.pl/produkty/materialy-do-produkcji-wkladek' => 'Materiały do produkcji wkładek',
    ];

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = ['sklep.iconic.pl'];

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
        ?VatRate $vatRate = null,
        bool $importImages = true,
        ?int $imageLimit = 10,
    ): array {
        $this->warnings = [];

        $externalId = $this->externalProductId($scraped);

        if ($this->booleanValue($scraped['is_on_order'] ?? null) === true
            || $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null) === null) {
            throw new InvalidArgumentException(
                'Iconic product '.$externalId.' is on-order or has no authoritative gross price and is not eligible for commerce import.'
            );
        }

        $resolvedVatRate = $this->resolveVatRate($scraped, $vatRate);

        $product = DB::transaction(function () use (
            $scraped,
            $externalId,
            $resolvedVatRate,
            $importImages,
            $imageLimit,
        ): Product {
            $product = $this->resolveProduct($scraped, $externalId);
            $status = $product->status;

            $this->syncCategories($product, $scraped);
            $this->syncProductAttributes($product, $scraped);
            $this->syncVariants($product, $scraped, $resolvedVatRate, $status);

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
    private function resolveProduct(array $scraped, string $externalId): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'iconic')
            ->where('external_id', $externalId)
            ->first();

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: 'Iconic product '.$externalId;

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'iconic-product-'.$externalId;
        }

        $status = $product?->status ?? ProductStatus::DRAFT;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped),
            'description' => $this->productDescriptionHtml($scraped),
            'seo_title' => $this->plainTextSnippet($scraped['seo_title'] ?? null, 300) ?: $name,
            'seo_description' => $this->plainTextSnippet($scraped['seo_description'] ?? null, 300),
            'status' => $status,
            'external_source' => 'iconic',
            'external_id' => $externalId,
            'external_parent_sku' => $this->parentSku($externalId),
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
        $rootNames = $this->rootCategoryNames($scraped);

        if ($rootNames === []) {
            throw new InvalidArgumentException(
                'Iconic product '.$product->external_id.' is missing approved root provenance.'
            );
        }

        $sourcePath = $this->sourceCategoryPath($scraped);
        if ($sourcePath !== [] && $this->isApprovedRootCategoryName($sourcePath[0])) {
            array_shift($sourcePath);
        }

        $resolved = [];
        $primaryCategoryId = null;

        foreach ($rootNames as $rootIndex => $rootName) {
            $parent = $this->resolveCategory($rootName, null, [$rootName]);
            $resolved[$parent->id] = $parent;

            foreach ($sourcePath as $segmentIndex => $segment) {
                $parent = $this->resolveCategory(
                    $segment,
                    $parent,
                    array_merge([$rootName], array_slice($sourcePath, 0, $segmentIndex + 1)),
                );
                $resolved[$parent->id] = $parent;
            }

            if ($rootIndex === 0) {
                $primaryCategoryId = $parent->id;
            }
        }

        $syncPayload = [];

        foreach ($resolved as $category) {
            $syncPayload[$category->id] = [
                'is_primary' => $category->id === $primaryCategoryId,
            ];
        }

        $product->categories()->sync($syncPayload);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function rootCategoryNames(array $scraped): array
    {
        $context = is_array($scraped['raw_context'] ?? null)
            ? $scraped['raw_context']
            : [];
        $roots = is_array($context['listing_roots'] ?? null)
            ? $context['listing_roots']
            : [];

        $names = [];

        foreach ($roots as $rootUrl) {
            if (! is_string($rootUrl)) {
                continue;
            }

            $rootUrl = rtrim(trim($rootUrl), '/');
            $name = self::ROOT_CATEGORY_MAP[$rootUrl] ?? null;

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    private function isApprovedRootCategoryName(string $name): bool
    {
        $key = Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        foreach (self::ROOT_CATEGORY_MAP as $approvedName) {
            $approvedKey = Str::of($approvedName)
                ->ascii()
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', ' ')
                ->trim()
                ->value();

            if ($key === $approvedKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function sourceCategoryPath(array $scraped): array
    {
        $path = is_array($scraped['source_category_path'] ?? null)
            ? $scraped['source_category_path']
            : (is_array($scraped['categories'] ?? null) ? $scraped['categories'] : []);

        $result = [];

        foreach ($path as $segment) {
            $segment = $this->stringOrNull($segment);

            if ($segment !== null && ! in_array($segment, $result, true)) {
                $result[] = $segment;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function resolveCategory(string $name, ?Category $parent, array $pathSegments): Category
    {
        $baseSlug = $parent === null
            ? Str::slug($name)
            : Str::slug(implode(' ', $pathSegments));

        if ($baseSlug === '') {
            $baseSlug = 'iconic-category-'.substr(sha1(implode('|', $pathSegments)), 0, 10);
        }

        $category = Category::withTrashed()->where('slug', $baseSlug)->first();

        if ($category !== null && $category->parent_id !== $parent?->id) {
            $category = null;
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

        if ($this->booleanValue($scraped['is_medical_device'] ?? null) === true) {
            $values[] = $this->resolveAttributeValue('Wyrób medyczny', 'Tak');

            $medicalClass = $this->stringOrNull($scraped['medical_device_class'] ?? null);

            if ($medicalClass !== null) {
                $values[] = $this->resolveAttributeValue('Klasa wyrobu medycznego', $medicalClass);
            }
        }

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
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
    private function syncVariants(
        Product $product,
        array $scraped,
        VatRate $vatRate,
        ProductStatus $productStatus,
    ): void {
        $candidates = $this->variantCandidates($scraped);
        $seenExternalVariantIds = [];
        $syncedVariantIds = [];
        $defaultAssigned = false;

        foreach ($candidates as $index => $candidate) {
            $sourceExternalId = $this->stringOrNull($candidate['external_variant_id'] ?? null)
                ?: (string) ($index + 1);
            $externalVariantId = $this->limitDatabaseString(
                'iconic-'.$product->external_id.'-'.$sourceExternalId
            );

            if (isset($seenExternalVariantIds[$externalVariantId])) {
                continue;
            }

            $seenExternalVariantIds[$externalVariantId] = true;

            $grossAmount = $this->moneyToMinorUnits(
                $candidate['price_gross_amount'] ?? $scraped['price_gross_amount'] ?? null
            );

            if ($grossAmount === null) {
                throw new InvalidArgumentException(
                    'Iconic product '.$product->external_id.' has no valid gross price.'
                );
            }

            $attributeValueIds = $this->variantAttributeValueIds($candidate);
            $sku = $this->variantSku($product->external_id, $sourceExternalId);
            $isDefault = ! $defaultAssigned;

            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $externalVariantId)
                ->first();

            $attributes = [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
                'sku' => $this->uniqueSku($sku, $product->id, $externalVariantId),
                'status' => $this->variantStatusForProductStatus($productStatus),
                'price_net_amount' => $vatRate->netFromGross($grossAmount),
                'price_gross_amount' => $grossAmount,
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($scraped),
                'is_default' => $isDefault,
            ];

            if ($variant !== null) {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            } else {
                $variant = ProductVariant::query()->create($attributes);
            }

            $variant->attributeValues()->sync($attributeValueIds);
            $syncedVariantIds[] = $variant->id;
            $defaultAssigned = true;
        }

        if ($syncedVariantIds === []) {
            return;
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedVariantIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(Product $product, array $scraped, ?int $imageLimit): void
    {
        $sourceImages = is_array($scraped['images'] ?? null) ? $scraped['images'] : [];

        if ($sourceImages === []) {
            return;
        }

        $imageRows = [];
        $seenUrls = [];
        $maxImages = $imageLimit !== null && $imageLimit > 0 ? $imageLimit : null;

        foreach ($sourceImages as $imageData) {
            if (! is_array($imageData)) {
                continue;
            }

            if ($maxImages !== null && count($imageRows) >= $maxImages) {
                break;
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/iconic/'.$product->external_id.'/gallery',
                    'public',
                    self::IMAGE_ALLOWED_HOSTS,
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for Iconic product '
                    .$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $alt = $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name;

            $imageRows[] = [
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'alt_text' => $alt,
                'title' => $alt,
                'sort_order' => count($imageRows),
                'is_main' => count($imageRows) === 0,
            ];
        }

        if ($imageRows === []) {
            $this->warnings[] = 'No Iconic images were imported for product '.$product->external_id
                .'; existing images were preserved.';

            return;
        }

        $paths = array_column($imageRows, 'path');

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('path', $paths)
            ->delete();

        foreach ($imageRows as $row) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'path' => $row['path'],
                ],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $row['alt_text'],
                    'title' => $row['title'],
                    'is_main' => $row['is_main'],
                    'sort_order' => $row['sort_order'],
                ],
            );
        }
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

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label === null || $value === null) {
                continue;
            }

            $ids[] = $this->resolveAttributeValue($label, $value)->id;
        }

        return array_values(array_unique($ids));
    }

    private function resolveAttributeValue(string $attributeName, string $value): AttributeValue
    {
        $attributeName = trim($attributeName, " \t\n\r\0\x0B:");
        $value = trim($value);

        $attributeSlug = Str::slug($attributeName)
            ?: 'iconic-attribute-'.substr(sha1($attributeName), 0, 10);
        $valueSlug = Str::slug(str_replace(['/', '\\', ',', '.'], '-', $value))
            ?: 'iconic-value-'.substr(sha1($value), 0, 10);

        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => $attributeSlug],
            [
                'name' => $attributeName,
                'external_attribute_id' => null,
                'display_type' => AttributeDisplayType::SELECT,
            ],
        );

        return AttributeValue::query()->firstOrCreate(
            [
                'attribute_id' => $attribute->id,
                'slug' => $valueSlug,
            ],
            [
                'value' => $this->limitDatabaseString($value),
                'external_option_id' => null,
                'sort_order' => 0,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array<string, mixed>>
     */
    private function variantCandidates(array $scraped): array
    {
        $candidates = [];

        foreach (($scraped['variant_candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates !== []) {
            return $candidates;
        }

        return [[
            'external_variant_id' => 'default',
            'attributes' => [],
            'price_gross_amount' => $scraped['price_gross_amount'] ?? null,
            'currency' => $scraped['currency'] ?? 'PLN',
        ]];
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function resolveVatRate(array $scraped, ?VatRate $fallback): VatRate
    {
        $value = $scraped['vat_rate'] ?? null;

        if (is_int($value)) {
            $rate = VatRate::tryFrom($value);

            if ($rate !== null) {
                return $rate;
            }
        }

        if (is_string($value) && trim($value) !== '' && ctype_digit(trim($value))) {
            $rate = VatRate::tryFrom((int) trim($value));

            if ($rate !== null) {
                return $rate;
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new InvalidArgumentException(
            'Iconic VAT rate is not explicit. Add vat_rate to the product data or pass an explicit VAT override.'
        );
    }

    private function parentSku(string $externalId): string
    {
        return $this->limitDatabaseString(
            $this->normaliseSku('ICO-'.$externalId)
        );
    }

    private function variantSku(string $externalId, string $sourceExternalId): string
    {
        $sku = 'ICO-'.$externalId;

        if ($sourceExternalId !== 'default') {
            $sku .= '-'.$sourceExternalId;
        }

        return $this->normaliseSku($sku);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function shortDescriptionHtml(array $scraped): ?string
    {
        $summary = $this->plainTextSnippet($scraped['seo_description'] ?? null, 500)
            ?: $this->firstParagraphText($scraped['description_html'] ?? null, 500);

        return $summary === null ? null : '<p>'.e($summary).'</p>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productDescriptionHtml(array $scraped): ?string
    {
        $sections = [];
        $mainHtml = $this->cleanImportedHtml(
            $this->stringOrNull($scraped['description_html'] ?? null)
        );

        if ($mainHtml !== null) {
            $sections[] = $mainHtml;
        }

        $parameterSection = $this->parameterSection($scraped);

        if ($parameterSection !== null) {
            $sections[] = $parameterSection;
        }

        $variantSection = $this->variantSection($scraped);

        if ($variantSection !== null) {
            $sections[] = $variantSection;
        }

        $metadataSection = $this->metadataSection($scraped);

        if ($metadataSection !== null) {
            $sections[] = $metadataSection;
        }

        if ($sections === []) {
            return null;
        }

        return implode("\n", $sections);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parameterSection(array $scraped): ?string
    {
        $rows = [];

        foreach (($scraped['attributes'] ?? []) as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $label = $this->stringOrNull($attributeData['label'] ?? null);
            $value = $this->stringOrNull($attributeData['value'] ?? null);

            if ($label !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="iconic-parameters"><h2>Parametry produktu</h2><table><tbody>'
            .implode('', array_values(array_unique($rows)))
            .'</tbody></table></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function variantSection(array $scraped): ?string
    {
        $items = [];

        foreach (($scraped['variant_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $label = $this->stringOrNull($candidate['label'] ?? null);

            if ($label !== null) {
                $items[] = '<li>'.e($label).'</li>';
            }
        }

        if ($items === []) {
            return null;
        }

        return '<section class="iconic-variants"><h2>Dostępne warianty</h2><ul>'
            .implode('', array_values(array_unique($items)))
            .'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function metadataSection(array $scraped): ?string
    {
        $rows = [];

        foreach ([
            'Dostępność' => $this->stringOrNull($scraped['availability_label'] ?? null),
            'Termin realizacji' => $this->stringOrNull($scraped['shipping_time'] ?? null),
        ] as $label => $value) {
            if ($value !== null) {
                $rows[] = '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="iconic-product-meta"><h2>Dane produktu</h2><table><tbody>'
            .implode('', $rows)
            .'</tbody></table></section>';
    }

    private function cleanImportedHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?\s*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+(?:style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/<a\b[^>]*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<\/a>/isu', '', $html) ?? $html;
        $html = strip_tags(
            $html,
            '<p><br><strong><b><em><i><ul><ol><li><h2><h3><table><thead><tbody><tr><th><td>'
        );
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null)
            ?: $this->stringOrNull($scraped['slug'] ?? null);

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $sourceUrl = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: (string) ($scraped['name'] ?? 'iconic-product');

        return substr(sha1($sourceUrl), 0, 32);
    }

    private function isSafeFilterAttributeValue(string $value): bool
    {
        return mb_strlen($value) <= 120 && substr_count($value, ' ') <= 12;
    }

    private function stockStatus(array $scraped): StockStatus
    {
        $availability = Str::of((string) ($scraped['availability'] ?? ''))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        $label = Str::of((string) ($scraped['availability_label'] ?? ''))
            ->lower()
            ->ascii()
            ->value();

        if (in_array($availability, ['out_of_stock', 'unavailable', 'sold_out', 'not_available'], true)
            || Str::of($label)->contains(['brak', 'niedostepn', 'wyprzedan'])) {
            return StockStatus::OUT_OF_STOCK;
        }

        if (in_array($availability, ['preorder', 'on_order'], true)) {
            return StockStatus::PREORDER;
        }

        return StockStatus::IN_STOCK;
    }

    private function variantStatusForProductStatus(ProductStatus $status): ProductVariantStatus
    {
        return match ($status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'iconic-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists()) {
            $candidate = $suffix === 2
                ? $baseSlug.'-iconic-'.$externalId
                : $baseSlug.'-iconic-'.$externalId.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueCategorySlug(string $baseSlug): string
    {
        $candidate = $baseSlug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueSku(string $sku, int $productId, string $externalVariantId): string
    {
        $sku = $this->limitDatabaseString($this->normaliseSku($sku) ?: 'ICO-'.$externalVariantId);
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->where(function ($query) use ($productId, $externalVariantId): void {
                $query
                    ->where('product_id', '!=', $productId)
                    ->orWhere('external_variant_id', '!=', $externalVariantId);
            })
            ->exists()) {
            $candidate = $this->limitDatabaseString($sku.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        $sku = html_entity_decode(trim($sku), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($sku === '') {
            return '';
        }

        $sku = str_replace(['/', '\\'], '-', $sku);
        $sku = preg_replace('/\s+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/-+/', '-', $sku) ?? $sku;

        return Str::upper(trim($sku, '-._'));
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? $normalized;

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function firstParagraphText(mixed $value, int $limit): ?string
    {
        $html = $this->stringOrNull($value);

        if ($html === null) {
            return null;
        }

        if (preg_match('/<p\b[^>]*>(.*?)<\/p>/isu', $html, $matches) === 1) {
            return $this->plainTextSnippet($matches[1], $limit);
        }

        return $this->plainTextSnippet($html, $limit);
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($string)) ?? strip_tags($string));

        if ($text === '') {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit($text, $limit, '');
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = end($parts);

        return is_string($slug) && $slug !== '' ? Str::slug($slug) : null;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return null;
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= self::MAX_DATABASE_STRING_LENGTH) {
            return $value;
        }

        $hash = substr(sha1($value), 0, 10);
        $prefixLength = self::MAX_DATABASE_STRING_LENGTH - mb_strlen($hash) - 1;

        return rtrim(mb_substr($value, 0, max(1, $prefixLength)), '-_ .').'_'.$hash;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
