<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Shop\ShopSettings;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function __construct(
        private readonly ShopSettings $shopSettings,
        private readonly StorefrontCache $cache,
    ) {}

    public function __invoke(Request $request): View
    {
        $searchQuery = trim($request->string('q')->toString());

        $categories = $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            'home.root-categories.v2',
            function () {
                $categories = Category::query()
                    ->whereNull('parent_id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->whereNotNull('slug')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'description']);

                $activeProductCounts = collect(DB::select(
                    <<<'SQL'
WITH RECURSIVE active_category_tree AS (
    SELECT id, id AS root_id
    FROM categories
    WHERE parent_id IS NULL
      AND status = ?
      AND deleted_at IS NULL

    UNION ALL

    SELECT categories.id, active_category_tree.root_id
    FROM categories
    INNER JOIN active_category_tree
        ON categories.parent_id = active_category_tree.id
    WHERE categories.status = ?
      AND categories.deleted_at IS NULL
)
SELECT
    active_category_tree.root_id,
    COUNT(DISTINCT products.id) AS active_products_count
FROM active_category_tree
LEFT JOIN category_product
    ON category_product.category_id = active_category_tree.id
LEFT JOIN products
    ON products.id = category_product.product_id
   AND products.status = ?
   AND products.deleted_at IS NULL
GROUP BY active_category_tree.root_id
SQL,
                    [
                        CategoryStatus::ACTIVE->value,
                        CategoryStatus::ACTIVE->value,
                        ProductStatus::ACTIVE->value,
                    ],
                ))->mapWithKeys(
                    fn (object $row): array => [
                        (int) $row->root_id => (int) $row->active_products_count,
                    ],
                );

                return $categories->each(function (Category $category) use ($activeProductCounts): void {
                    $category->setAttribute(
                        'active_products_count',
                        $activeProductCounts->get((int) $category->id, 0),
                    );
                });
            },
            $this->cache->homePageTtlSeconds(),
        );

        $featuredProducts = $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            'home.featured-products.v1',
            fn () => $this->productCardQuery()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
            $this->cache->homePageTtlSeconds(),
        );

        $searchResults = collect();

        if ($searchQuery !== '') {
            $searchResults = $this->productCardQuery()
                ->where(function (Builder $query) use ($searchQuery): void {
                    $query
                        ->where('name', 'like', '%'.$searchQuery.'%')
                        ->orWhere('short_description', 'like', '%'.$searchQuery.'%')
                        ->orWhere('description', 'like', '%'.$searchQuery.'%');
                })
                ->orderBy('name')
                ->limit(24)
                ->get();
        }

        $seoTitle = $searchQuery !== ''
            ? 'Wyniki wyszukiwania dla „'.$searchQuery.'”'
            : 'Odzież medyczna, stabilizatory ortopedyczne i produkty do regeneracji';
        $seoDescription = 'Kupuj odzież medyczną, stabilizatory, ortezy i produkty do regeneracji z dostawą na terenie Polski.';
        $canonicalUrl = route('home');

        return view('pages.home', [
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
            'searchQuery' => $searchQuery,
            'searchResults' => $searchResults,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            'robots' => $searchQuery !== '' ? 'noindex, follow' : null,
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'website',
            'structuredData' => [
                $this->websiteStructuredData($canonicalUrl),
                $this->organizationStructuredData($canonicalUrl),
            ],
        ]);
    }

    private function productCardQuery(): Builder
    {
        return Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->whereNotNull('slug')
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
     * @return array<string, mixed>
     */
    private function websiteStructuredData(string $canonicalUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->shopSettings->shopName(),
            'url' => $canonicalUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationStructuredData(string $canonicalUrl): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->shopSettings->companyName(),
            'url' => $canonicalUrl,
            'email' => $this->shopSettings->email() ?: null,
            'telephone' => $this->shopSettings->phone() ?: null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
