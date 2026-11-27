<?php

declare(strict_types=1);

namespace App\Services\OldShop;

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
use App\Services\Products\ProductBrandAttribute;
use App\Support\Seo\ProductMetaDescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Imports products read by oldshop:crawl as drafts, at the old shop's gross prices and VAT ("keep our prices").
 * One variant per size/colour combination the old shop sold, with Rozmiar/Kolor options.
 *
 * The product goes into the new shop's category of the same name as its old default category ("Ortezy i
 * stabilizatory"), else into a "<Brand>" category with the old category below it. Re-running updates prices and
 * content in place and keeps the product status.
 */
final class OldShopProductImporter
{
    public const SOURCE = 'ortezka-old';

    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
        private readonly ProductBrandAttribute $brandAttribute,
    ) {}

    /**
     * What the import would write, without writing.
     *
     * @param  array<string, mixed>  $page
     * @return array{external_id: string, name: string, brand: string|null, category: list<string>, existing_category: bool, variants: list<array<string, mixed>>, unpriced: int}
     */
    public function plan(array $page): array
    {
        $externalId = 'p'.(int) $page['id_product'];
        $vat = (int) ($page['vat_rate'] ?? 23) === 8 ? VatRate::VAT_8 : VatRate::VAT_23;
        $combinations = (array) ($page['combinations'] ?? []);
        $reference = $this->normaliseSku((string) ($page['reference'] ?? '')) ?: 'OLD-'.(int) $page['id_product'];
        $variants = [];
        $unpriced = 0;

        foreach ($combinations as $combination) {
            $gross = isset($combination['price_gross']) ? (int) round((float) $combination['price_gross'] * 100) : 0;

            if ($gross <= 0) {
                $unpriced++;

                continue;
            }

            $idpa = $combination['id_product_attribute'] ?? null;
            $options = [];

            foreach ((array) ($combination['options'] ?? []) as $label => $value) {
                $options[$this->attributeName((string) $label)] = (string) $value;
            }

            $variants[] = [
                'external_variant_id' => self::SOURCE.'-'.$externalId.'-'.($idpa ?? 'default'),
                'sku' => count($combinations) > 1 && $idpa !== null ? $reference.'-'.$idpa : $reference,
                'price_gross_amount' => $gross,
                'price_net_amount' => $vat->netFromGross($gross),
                'vat_rate' => $vat->value,
                'options' => $options,
            ];
        }

        $category = $this->stringOrNull($page['category'] ?? null);

        // Products the old shop filed under its home page go straight into the brand's category.
        if ($category !== null && in_array(Str::slug($category), ['strona-glowna', 'home'], true)) {
            $category = null;
        }

        return [
            'external_id' => $externalId,
            'name' => $this->limitDatabaseString((string) ($this->stringOrNull($page['name'] ?? null) ?? 'Produkt '.$externalId)),
            'brand' => $this->brandName($page),
            'category' => array_values(array_filter([$category])),
            'existing_category' => $category !== null && $this->existingCategory($category) !== null,
            'variants' => $variants,
            'unpriced' => $unpriced,
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(array $page, bool $importImages = true): array
    {
        $this->warnings = [];
        $plan = $this->plan($page);

        if ($plan['variants'] === []) {
            throw new InvalidArgumentException('No priced combination for '.$plan['name']);
        }

        $product = DB::transaction(function () use ($page, $plan, $importImages): Product {
            $product = $this->resolveProduct($page, $plan);
            $this->syncCategory($product, $plan);
            $this->syncProductAttributes($product, $plan);
            $this->syncVariants($product, $plan);

            if ($importImages) {
                $this->syncImages($product, $page);
            }

            return $product->fresh(['categories', 'images', 'variants.attributeValues.attribute']);
        });

        return ['product' => $product, 'warnings' => $this->warnings];
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function brandName(array $page): ?string
    {
        $brand = $this->stringOrNull($page['brand'] ?? null);

        // The old shop writes some brands in capitals ("THUASNE").
        return $brand !== null && $brand === Str::upper($brand) ? Str::title(Str::lower($brand)) : $brand;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $plan
     */
    private function resolveProduct(array $page, array $plan): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $plan['external_id'])
            ->first();

        $description = $this->stringOrNull($page['description_html'] ?? null);
        $short = $this->stringOrNull($page['short_description_html'] ?? null);
        $attributes = [
            'name' => $plan['name'],
            'slug' => $product?->slug ?? $this->uniqueProductSlug(Str::slug($plan['name']) ?: 'produkt-'.$plan['external_id'], null),
            'short_description' => $short,
            'description' => $description,
            'seo_title' => $plan['name'],
            'seo_description' => ProductMetaDescription::build($description, $short, $plan['name']),
            'status' => $product?->status ?? ProductStatus::DRAFT,
            'external_source' => self::SOURCE,
            'external_id' => $plan['external_id'],
            'external_parent_sku' => $this->limitDatabaseString((string) ($page['source_url'] ?? $plan['external_id'])),
        ];

        if ($product === null) {
            return Product::query()->create($attributes);
        }

        if ($product->trashed()) {
            $product->restore();
        }

        $product->update($attributes);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function syncCategory(Product $product, array $plan): void
    {
        // A product moved into the shop's own categories (oldshop:recategorise) stays there on re-import.
        if ($product->categories()->exists()) {
            return;
        }

        $name = $plan['category'][0] ?? null;
        $category = $name !== null ? $this->existingCategory($name) : null;

        if ($category === null) {
            $root = $this->category(Str::slug($plan['brand'] ?? 'inne-produkty'), $plan['brand'] ?? 'Inne produkty', null);
            $category = $name !== null ? $this->category($root->slug.'-'.Str::slug($name), $name, $root->id) : $root;
        }

        $product->categories()->sync([$category->id => ['is_primary' => true]]);
    }

    private function existingCategory(string $name): ?Category
    {
        return Category::query()->where('status', CategoryStatus::ACTIVE->value)->where('slug', Str::slug($name))->first()
            ?? Category::query()->where('status', CategoryStatus::ACTIVE->value)->where('name', $name)->first();
    }

    private function category(string $slug, string $name, ?int $parentId): Category
    {
        $category = Category::withTrashed()->where('slug', $slug)->first();

        if ($category === null) {
            return Category::query()->create([
                'parent_id' => $parentId,
                'name' => $this->limitDatabaseString($name),
                'slug' => $this->limitDatabaseString($slug),
                'status' => CategoryStatus::ACTIVE,
            ]);
        }

        if ($category->trashed()) {
            $category->restore();
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function syncProductAttributes(Product $product, array $plan): void
    {
        if ($plan['brand'] !== null) {
            $this->brandAttribute->attach($product, $plan['brand']);
        }

        // The old shop sells medical devices at 8% VAT.
        if (collect($plan['variants'])->every(fn (array $variant): bool => $variant['vat_rate'] === VatRate::VAT_8->value)) {
            $product->attributeValues()->syncWithoutDetaching([$this->resolveAttributeValue('Wyrób medyczny', 'Tak')->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function syncVariants(Product $product, array $plan): void
    {
        $status = match ($product->status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
        $syncedIds = [];

        foreach ($plan['variants'] as $index => $candidate) {
            $variant = ProductVariant::withTrashed()->where('external_variant_id', $candidate['external_variant_id'])->first();
            $attributes = [
                'product_id' => $product->id,
                'external_variant_id' => $candidate['external_variant_id'],
                'sku' => $this->uniqueSku($candidate['sku'], $variant?->id),
                'status' => $status,
                'price_net_amount' => $candidate['price_net_amount'],
                'price_gross_amount' => $candidate['price_gross_amount'],
                'currency' => Currency::PLN,
                'vat_rate' => VatRate::from($candidate['vat_rate']),
                'stock_status' => StockStatus::IN_STOCK,
                'is_default' => $index === 0,
            ];

            if ($variant === null) {
                $variant = ProductVariant::query()->create($attributes);
            } else {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            }

            $variant->attributeValues()->sync(array_map(
                fn (string $name, string $value): int => $this->resolveAttributeValue($name, $value)->id,
                array_keys($candidate['options']),
                array_values($candidate['options']),
            ));
            $syncedIds[] = $variant->id;
        }

        ProductVariant::query()->where('product_id', $product->id)->whereNotIn('id', $syncedIds)->delete();
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function syncImages(Product $product, array $page): void
    {
        $rows = [];

        foreach (array_values(array_unique(array_map('strval', (array) ($page['images'] ?? [])))) as $url) {
            try {
                $rows[] = $this->remoteImageImporter->import($url, 'products/ortezka-old/'.$product->external_id.'/gallery', 'public', [OldShopScraper::HOST, 'www.'.OldShopScraper::HOST]);
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for '.$product->external_id.': '.$url.' — '.$exception->getMessage();
            }
        }

        if ($rows === []) {
            return;
        }

        ProductImage::query()->where('product_id', $product->id)->whereNotIn('path', array_column($rows, 'path'))->delete();
        $alt = $this->limitDatabaseString($product->name);

        foreach ($rows as $order => $row) {
            ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'path' => $row['path']],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $alt,
                    'title' => $alt,
                    'is_main' => $order === 0,
                    'sort_order' => $order,
                ],
            );
        }
    }

    /** "ROZMIAR" → "Rozmiar", "KOLOR" → "Kolor": the names the new shop's option groups use. */
    private function attributeName(string $label): string
    {
        $label = trim($label, " :\t\n");

        return Str::ucfirst(Str::lower($label)) ?: 'Wariant';
    }

    private function resolveAttributeValue(string $attributeName, string $value): AttributeValue
    {
        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => Str::slug($attributeName)],
            ['name' => $attributeName, 'external_attribute_id' => null, 'display_type' => AttributeDisplayType::SELECT],
        );

        return AttributeValue::query()->firstOrCreate(
            [
                'attribute_id' => $attribute->id,
                'slug' => Str::slug(str_replace(['/', '\\', ',', '.'], '-', $value)) ?: 'wartosc-'.substr(sha1($value), 0, 10),
            ],
            ['value' => $this->limitDatabaseString($value), 'external_option_id' => null, 'sort_order' => 0],
        );
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId): string
    {
        $baseSlug = $this->limitDatabaseString($baseSlug);
        $candidate = $baseSlug;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $candidate)->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))->exists()) {
            $candidate = $this->limitDatabaseString($baseSlug.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueSku(string $sku, ?int $currentVariantId): string
    {
        $sku = $this->limitDatabaseString($sku);
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()->where('sku', $candidate)->when($currentVariantId !== null, fn ($query) => $query->whereKeyNot($currentVariantId))->exists()) {
            $candidate = $this->limitDatabaseString($sku.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($sku)) ?? $sku;

        return Str::upper(trim(preg_replace('/-+/', '-', $sku) ?? $sku, '-._'));
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        return mb_strlen($value) <= self::MAX_DATABASE_STRING_LENGTH
            ? $value
            : rtrim(mb_substr($value, 0, self::MAX_DATABASE_STRING_LENGTH - 11), '-_ .').'_'.substr(sha1($value), 0, 10);
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
