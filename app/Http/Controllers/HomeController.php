<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Shop\ShopSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(
        private readonly ShopSettings $shopSettings,
    ) {}

    public function __invoke(Request $request): View
    {
        $searchQuery = trim($request->string('q')->toString());

        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('status', CategoryStatus::ACTIVE->value)
            ->whereNotNull('slug')
            ->withCount([
                'products as active_products_count' => fn (Builder $query): Builder => $query
                    ->where('products.status', ProductStatus::ACTIVE->value),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        $featuredProducts = $this->productCardQuery()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

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
                'mainImage',
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
