<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sorting and filtering shared by category pages and search results.
 *
 * A product's listing price is its cheapest active variant, so "price ascending" and the price
 * filter agree with the "od" price a shopper can actually buy at.
 */
final class ProductListing
{
    public const SORTS = [
        'polecane' => 'Polecane',
        'cena-rosnaco' => 'Cena: od najniższej',
        'cena-malejaco' => 'Cena: od najwyższej',
        'najnowsze' => 'Najnowsze',
        'nazwa' => 'Nazwa: A–Z',
    ];

    /** Search results add relevance, applied by ProductSearch::rankByName(). */
    public const SEARCH_SORTS = ['trafnosc' => 'Trafność'] + self::SORTS;

    public const BRAND_ATTRIBUTE_SLUGS = ['producent', 'marka'];

    public const PER_PAGE = 24;

    /**
     * Active products with what a product card needs, plus their listing price.
     */
    public function query(): Builder
    {
        return Product::query()
            ->select('products.*')
            ->selectSub($this->listingPriceSubquery(), 'listing_price')
            ->where('products.status', ProductStatus::ACTIVE->value)
            ->whereNotNull('products.slug')
            ->with([
                'images',
                'attributeValueImages',
                'categories:id,name,slug',
                'variants' => function (HasMany $query): void {
                    $query
                        ->where('status', ProductVariantStatus::ACTIVE->value)
                        ->orderByDesc('is_default')
                        ->orderBy('id');
                },
            ]);
    }

    /**
     * @param  array<string, string>  $sorts
     * @return array{sort: string, price_min: ?int, price_max: ?int, brands: list<string>, available: bool}
     */
    public function filtersFrom(Request $request, array $sorts = self::SORTS): array
    {
        $defaultSort = array_key_first($sorts);
        $sort = $request->string('sort')->toString();
        $brands = array_values(array_unique(array_filter(
            array_map(static fn ($brand): string => is_scalar($brand) ? trim((string) $brand) : '', (array) $request->query('marka', [])),
            static fn (string $brand): bool => preg_match('/^[a-z0-9-]{1,100}$/', $brand) === 1,
        )));
        sort($brands);

        $priceMin = $this->priceFrom($request->query('cena_od'));
        $priceMax = $this->priceFrom($request->query('cena_do'));

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        return [
            'sort' => array_key_exists($sort, $sorts) ? $sort : $defaultSort,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'brands' => $brands,
            'available' => $request->boolean('dostepne'),
        ];
    }

    /**
     * @param  array{sort: string, price_min: ?int, price_max: ?int, brands: list<string>, available: bool}  $filters
     */
    public function hasActiveFilters(array $filters): bool
    {
        return $filters['price_min'] !== null || $filters['price_max'] !== null || $filters['brands'] !== [] || $filters['available'];
    }

    /**
     * Lower-cased names that appear more than once among the listed products, whose cards then show a code.
     *
     * @param  iterable<Product>  $products
     * @return list<string>
     */
    public function duplicateNames(iterable $products): array
    {
        $counts = [];

        foreach ($products as $product) {
            $name = mb_strtolower((string) $product->name);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return array_map(strval(...), array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
    }

    /**
     * The filters as query-string parameters, for pagination links.
     *
     * @param  array{sort: string, price_min: ?int, price_max: ?int, brands: list<string>, available: bool}  $filters
     * @return array<string, mixed>
     */
    public function queryParameters(array $filters, string $defaultSort): array
    {
        return array_filter([
            'sort' => $filters['sort'] === $defaultSort ? null : $filters['sort'],
            'cena_od' => $filters['price_min'] === null ? null : $filters['price_min'] / 100,
            'cena_do' => $filters['price_max'] === null ? null : $filters['price_max'] / 100,
            'marka' => $filters['brands'] === [] ? null : $filters['brands'],
            'dostepne' => $filters['available'] ? 1 : null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param  array{sort: string, price_min: ?int, price_max: ?int, brands: list<string>, available: bool}  $filters
     */
    public function cacheKey(array $filters): string
    {
        return substr(sha1(json_encode($filters, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param  array{sort: string, price_min: ?int, price_max: ?int, brands: list<string>, available: bool}  $filters
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if ($filters['price_min'] !== null || $filters['price_max'] !== null || $filters['available']) {
            $query->whereHas('variants', function (Builder $variants) use ($filters): void {
                $variants
                    ->where('status', ProductVariantStatus::ACTIVE->value)
                    ->where('price_gross_amount', '>', 0);

                if ($filters['price_min'] !== null) {
                    $variants->where('price_gross_amount', '>=', $filters['price_min']);
                }

                if ($filters['price_max'] !== null) {
                    $variants->where('price_gross_amount', '<=', $filters['price_max']);
                }

                if ($filters['available']) {
                    $variants
                        ->whereNotNull('price_gross_amount')
                        ->where('stock_status', '!=', StockStatus::OUT_OF_STOCK->value);
                }
            });
        }

        if ($filters['brands'] !== []) {
            $query->whereHas('attributeValues', function (Builder $values) use ($filters): void {
                $values
                    ->whereIn('attribute_values.slug', $filters['brands'])
                    ->whereHas('attribute', fn (Builder $attribute) => $attribute->whereIn('slug', self::BRAND_ATTRIBUTE_SLUGS));
            });
        }

        return $this->sort($query, $filters['sort']);
    }

    /**
     * Lists products outside the given categories (accessory subcategories) after the rest, whatever the sort.
     * Call before apply(): the sort only breaks ties within each group.
     *
     * @param  list<int>  $mainCategoryIds
     */
    public function accessoriesLast(Builder $query, array $mainCategoryIds): Builder
    {
        $inMain = DB::table('category_product')
            ->whereColumn('category_product.product_id', 'products.id')
            ->whereIn('category_product.category_id', $mainCategoryIds);

        return $query->orderByRaw('NOT EXISTS ('.$inMain->toSql().')', $inMain->getBindings());
    }

    /**
     * Brands found among the products of a listing, most common first.
     *
     * @return list<array{slug: string, name: string, count: int}>
     */
    public function brandOptions(Builder $listing): array
    {
        $productIds = (clone $listing)->setEagerLoads([])->reorder()->select('products.id');

        return DB::table('product_attribute_value')
            ->join('attribute_values', 'attribute_values.id', '=', 'product_attribute_value.attribute_value_id')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->whereIn('attributes.slug', self::BRAND_ATTRIBUTE_SLUGS)
            ->whereIn('product_attribute_value.product_id', $productIds->toBase())
            ->groupBy('attribute_values.slug', 'attribute_values.value')
            ->select('attribute_values.slug', 'attribute_values.value', DB::raw('COUNT(DISTINCT product_attribute_value.product_id) AS products_count'))
            ->orderByDesc('products_count')
            ->orderBy('attribute_values.value')
            ->limit(30)
            ->get()
            ->map(static fn (object $row): array => ['slug' => (string) $row->slug, 'name' => (string) $row->value, 'count' => (int) $row->products_count])
            ->all();
    }

    private function sort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            // Unpriced products cannot be bought, so they go last whatever the direction.
            'cena-rosnaco' => $query->orderByRaw('listing_price IS NULL')->orderBy('listing_price')->orderBy('products.id'),
            'cena-malejaco' => $query->orderByRaw('listing_price IS NULL')->orderByDesc('listing_price')->orderBy('products.id'),
            'najnowsze' => $query->orderByDesc('products.published_at')->orderByDesc('products.id'),
            'nazwa' => $query->orderBy('products.name')->orderBy('products.id'),
            default => $query->orderByRaw('listing_price IS NULL')->orderByDesc('products.published_at')->orderByDesc('products.id'),
        };
    }

    private function listingPriceSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('product_variants')
            ->selectRaw('MIN(price_gross_amount)')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('product_variants.status', ProductVariantStatus::ACTIVE->value)
            // Some imports carry 0 zł placeholder variants that cannot be bought.
            ->where('product_variants.price_gross_amount', '>', 0)
            ->whereNull('product_variants.deleted_at');
    }

    /** Whole złoty from the query string, in grosze. */
    private function priceFrom(mixed $value): ?int
    {
        if (! is_scalar($value) || ! is_numeric(str_replace(',', '.', (string) $value))) {
            return null;
        }

        $amount = (float) str_replace(',', '.', (string) $value);

        return $amount < 0 || $amount > 1_000_000 ? null : (int) round($amount * 100);
    }
}
