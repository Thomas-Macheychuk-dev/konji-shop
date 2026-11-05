<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Product;
use App\Services\Shop\ShopSettings;
use App\Services\Storefront\ProductListing;
use App\Services\Storefront\ProductSearch;
use App\Services\Storefront\RootCategories;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(
        private readonly ShopSettings $shopSettings,
        private readonly StorefrontCache $cache,
        private readonly ProductListing $listing,
        private readonly ProductSearch $search,
        private readonly RootCategories $rootCategories,
    ) {}

    public function __invoke(Request $request): View
    {
        $searchQuery = trim($request->string('q')->toString());

        $categories = $this->rootCategories->withProductCounts();

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
        $searchFilters = $this->listing->filtersFrom($request, ProductListing::SEARCH_SORTS);
        $searchBrands = [];
        $searchSuggestion = null;

        if ($searchQuery !== '') {
            $query = $this->search->restrict($this->listing->query(), $searchQuery);
            $searchBrands = $this->listing->brandOptions($query);

            if ($searchFilters['sort'] === 'trafnosc') {
                $this->search->rankByName($query, $searchQuery);
            }

            $searchResults = $this->listing->apply($query, $searchFilters)
                ->paginate(ProductListing::PER_PAGE)
                ->withPath(route('home'))
                ->appends(['q' => $searchQuery] + $this->listing->queryParameters($searchFilters, 'trafnosc'));

            if ($searchResults->total() === 0 && ! $this->listing->hasActiveFilters($searchFilters)) {
                $searchSuggestion = $this->search->suggestion($searchQuery);
            }
        }

        $seoTitle = $searchQuery !== ''
            ? 'Wyniki wyszukiwania dla „'.$searchQuery.'”'
            : 'Ortezy, stabilizatory i odzież medyczna';
        $seoDescription = 'Kupuj odzież medyczną, stabilizatory, ortezy i produkty do regeneracji z dostawą na terenie Polski.';
        $canonicalUrl = route('home');

        return view('pages.home', [
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
            'searchQuery' => $searchQuery,
            'searchResults' => $searchResults,
            'searchFilters' => $searchFilters,
            'searchBrands' => $searchBrands,
            'searchSuggestion' => $searchSuggestion,
            'searchIsFiltered' => $this->listing->hasActiveFilters($searchFilters) || $searchFilters['sort'] !== 'trafnosc',
            'duplicateNames' => $searchQuery === '' ? [] : $this->listing->duplicateNames($searchResults->items()),
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            'robots' => $searchQuery !== '' ? 'noindex, follow' : null,
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'website',
            'openGraphImage' => asset('images/ortezka-logo-v4.png'),
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
            'name' => config('app.name') ?: $this->shopSettings->shopName(),
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
            // The storefront name ties the legal company to the brand shown in the WebSite schema.
            'alternateName' => config('app.name') ?: $this->shopSettings->shopName(),
            'url' => $canonicalUrl,
            'logo' => asset('images/ortezka-logo-v4.png'),
            'email' => $this->shopSettings->email() ?: null,
            'telephone' => $this->shopSettings->phone() ?: null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
