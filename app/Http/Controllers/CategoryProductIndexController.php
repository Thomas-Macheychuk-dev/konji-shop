<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Services\Storefront\ActiveCategorySubtree;
use App\Services\Storefront\ProductListing;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class CategoryProductIndexController extends Controller
{
    private const CATEGORY_ALIASES = [
        'bluzy-damskie' => 'bluzy-medyczne-damskie',
        'fartuchy-damskie' => 'fartuchy-medyczne-damskie',
        'spodnie-damskie' => 'spodnie-medyczne-damskie',
        'zakiety-damskie' => 'zakiety-medyczne',
        'bluzy-meskie' => 'bluzy-medyczne-meskie',
        'fartuchy-meskie' => 'fartuchy-medyczne-meskie',
        'marynarki-meskie' => 'marynarki-medyczne-meskie',
        'spodnie-meskie' => 'spodnie-medyczne-meskie',
        'skarpety-uciskowe-meskie' => 'kompresja-podkolanowki-uciskowe',
        'podkolanowki-przeciwzylakowe-ponczochy-uciskowe-meskie' => 'kompresja-podkolanowki-uciskowe',
        'akcesoria-akcesoria' => 'akcesoria-do-zakladania-produktow-uciskowych',
        'podkolanowki-uciskowe-1-stopnia-damskie' => 'kompresja-podkolanowki-uciskowe',
        'podkolanowki-uciskowe-1-stopnia-meskie' => 'kompresja-podkolanowki-uciskowe',
        'podkolanowki-uciskowe-2-stopnia-damskie' => 'kompresja-podkolanowki-uciskowe',
        'podkolanowki-uciskowe-2-stopnia-meskie' => 'kompresja-podkolanowki-uciskowe',
        'podkolanowki-uciskowe-do-biegania' => 'kompresja-podkolanowki-uciskowe',
        'podkolanowki-uciskowe-do-samolotu' => 'podkolanowki-do-samolotu',
        'kompresja-ponczochy-uciskowe-1-stopnia' => 'kompresja-ponczochy-uciskowe',
        'kompresja-ponczochy-uciskowe-2-stopnia' => 'kompresja-ponczochy-uciskowe',
        'ponczochy-uciskowe-2-stopnia-na-lato' => 'kompresja-ponczochy-uciskowe',
        'kompresja-ponczochy-uciskowe-3-stopnia' => 'kompresja-ponczochy-uciskowe',
        'ponczochy-uciskowe-damskie' => 'kompresja-ponczochy-uciskowe',
        'kompresja-rajstopy-uciskowe-1-stopnia' => 'kompresja-rajstopy-uciskowe',
        'kompresja-rajstopy-uciskowe-2-stopnia' => 'kompresja-rajstopy-uciskowe',
        'kompresja-rajstopy-uciskowe-3-stopnia' => 'kompresja-rajstopy-uciskowe',
        'rajstopy-uciskowe-damskie' => 'kompresja-rajstopy-uciskowe',
        'rajstopy-uciskowe-do-samolotu' => 'kompresja-rajstopy-uciskowe',
        'skarpety-kompresyjne-damskie-medyczne' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-kompresyjne-do-biegania' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-kompresyjne-do-biegania-damskie' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-kompresyjne-do-biegania-meskie' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-kompresyjne-do-samolotu' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-kompresyjne-na-rower' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-uciskowe-1-stopnia' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-uciskowe-2-stopnia' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-uciskowe-3-stopnia' => 'kompresja-podkolanowki-uciskowe',
        'skarpety-uciskowe-damskie' => 'kompresja-podkolanowki-uciskowe',
    ];

    public function __construct(
        private readonly ActiveCategorySubtree $activeCategorySubtree,
        private readonly StorefrontCache $cache,
        private readonly ProductListing $listing,
    ) {}

    public function __invoke(Category $category): View|RedirectResponse
    {
        if ($category->status === CategoryStatus::ARCHIVED && isset(self::CATEGORY_ALIASES[$category->slug])) {
            $targetSlug = self::CATEGORY_ALIASES[$category->slug];

            abort_unless(Category::query()->where('slug', $targetSlug)->where('status', CategoryStatus::ACTIVE->value)->exists(), 404);

            return redirect()->route('categories.show', $targetSlug, 301);
        }

        if (! $category->status->isActive()) {
            // Archived supplier subcategories (catalogue:reorganise) send visitors and search engines to the nearest active parent.
            $ancestor = $this->nearestActiveAncestor($category);
            abort_if($ancestor === null, 404);

            return redirect()->route('categories.show', $ancestor->slug, 301);
        }

        $category->load([
            'children' => function ($query): void {
                $query
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->orderBy('name');
            },
        ]);

        $categoryIds = $this->activeCategorySubtree->ids($category);

        // "?page=abc" or "?page=0" is not a page of this listing.
        abort_if(request()->has('page') && preg_match('/^[1-9]\d{0,5}$/', (string) request()->query('page')) !== 1, 404);

        $page = max(1, request()->integer('page', 1));
        $filters = $this->listing->filtersFrom(request());
        $isFiltered = $this->listing->hasActiveFilters($filters) || $filters['sort'] !== array_key_first(ProductListing::SORTS);
        $inCategory = fn () => $this->listing->query()->whereHas('categories', function ($query) use ($categoryIds): void {
            $query->whereIn('categories.id', $categoryIds);
        });

        $products = $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            sprintf('category.v3.%s.%d.%s.page.%d', strtolower(request()->getSchemeAndHttpHost()), $category->getKey(), $this->listing->cacheKey($filters), $page),
            fn () => $this->listing->apply($inCategory(), $filters)->paginate(ProductListing::PER_PAGE),
            $this->cache->categoryPageTtlSeconds(),
        );

        $brands = $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            sprintf('category.brands.v1.%d', $category->getKey()),
            fn () => $this->listing->brandOptions($inCategory()),
            $this->cache->categoryPageTtlSeconds(),
        );

        abort_if($page > 1 && $products->isEmpty(), 404);

        // The paginator is cached as an object. Keep request-specific URL state
        // out of the cached instance and retain only the pagination and filter parameters.
        $products = clone $products;
        $categoryUrl = route('categories.show', $category->slug);
        $products->withPath($categoryUrl)->appends($this->listing->queryParameters($filters, array_key_first(ProductListing::SORTS)));

        $breadcrumbCategories = $this->breadcrumbCategories($category);
        $seoTitle = $this->limitText($category->seo_title ?: $this->distinctName($category, $breadcrumbCategories), 70);
        $editorialCopy = require resource_path('content/category-copy.pl.php');
        $copy = $editorialCopy[$category->slug] ?? [];
        if (($copy['name'] ?? null) !== $category->name) {
            $copy = [];
        }
        $categoryDescription = filled($category->description)
            ? $category->description
            : ($copy['description'] ?? null);
        $seoDescription = $this->seoDescription($category, $categoryDescription, $copy['meta'] ?? null);
        $canonicalUrl = $page > 1
            ? route('categories.show', ['category' => $category->slug, 'page' => $page])
            : $categoryUrl;

        if ($page > 1) {
            $seoTitle .= ' — Strona '.$page;
        }

        return view('pages.categories.show', [
            'category' => $category,
            'categoryDescription' => $categoryDescription,
            'breadcrumbCategories' => $breadcrumbCategories,
            'products' => $products,
            'filters' => $filters,
            'isFiltered' => $isFiltered,
            'brands' => $brands,
            'duplicateNames' => $this->listing->duplicateNames($products->items()),
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            // An empty listing is a soft 404 to search engines; it returns to the index once it has products.
            // Sorted and filtered views repeat the category, so only the plain listing is indexed.
            'robots' => $products->total() === 0 || $isFiltered ? 'noindex, follow' : null,
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'website',
            'structuredData' => [$this->breadcrumbStructuredData($category, $breadcrumbCategories, $canonicalUrl)],
        ]);
    }

    /**
     * Imported trees repeat names such as "Nowości" or "Temblaki" under different parents. A repeated name
     * gets its nearest differently named ancestor ("Nowości – Kliny rehabilitacyjne"), so titles stay unique.
     *
     * @param  list<Category>  $ancestors
     */
    private function distinctName(Category $category, array $ancestors): string
    {
        $repeated = Category::query()
            ->where('name', $category->name)
            ->where('status', CategoryStatus::ACTIVE->value)
            ->whereKeyNot($category->getKey())
            ->exists();

        if (! $repeated) {
            return $category->name;
        }

        foreach (array_reverse($ancestors) as $ancestor) {
            if (mb_strtolower($ancestor->name) !== mb_strtolower($category->name)) {
                return $category->name.' – '.$ancestor->name;
            }
        }

        return $category->name;
    }

    private function nearestActiveAncestor(Category $category): ?Category
    {
        $seen = [$category->getKey() => true];
        $current = $category;

        while ($current->parent_id !== null && ! isset($seen[$current->parent_id])) {
            $seen[$current->parent_id] = true;
            $current = Category::query()->find($current->parent_id);

            if ($current === null) {
                return null;
            }

            if ($current->status->isActive()) {
                return $current;
            }
        }

        return null;
    }

    /** @return list<Category> */
    private function breadcrumbCategories(Category $category): array
    {
        $ancestors = [];
        $seen = [$category->getKey() => true];
        $current = $category;

        while ($current->parent_id !== null) {
            if (isset($seen[$current->parent_id])) {
                return [];
            }

            $parent = $current->parent()->where('status', CategoryStatus::ACTIVE->value)->first();

            if ($parent === null) {
                return [];
            }

            $seen[$parent->getKey()] = true;
            $ancestors[] = $parent;
            $current = $parent;
        }

        return array_reverse($ancestors);
    }

    /** @param list<Category> $ancestors */
    private function breadcrumbStructuredData(Category $category, array $ancestors, string $canonicalUrl): array
    {
        $items = [['name' => 'Strona główna', 'url' => route('home')]];

        foreach ($ancestors as $ancestor) {
            $items[] = ['name' => $ancestor->name, 'url' => route('categories.show', $ancestor->slug)];
        }

        $items[] = ['name' => $category->name, 'url' => $canonicalUrl];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ], $items, array_keys($items)),
        ];
    }

    private function seoDescription(Category $category, ?string $description, ?string $editorialMeta): string
    {
        $description = $category->seo_description
            ?: (filled($category->description) ? $category->description : ($editorialMeta ?: $description));

        if (! filled($description)) {
            $description = 'Przeglądaj kategorię '.$category->name.'. Sprawdź produkty, dostępne warianty i szczegóły oferty.';
        }

        return $this->limitText((string) $description, 160);
    }

    private function limitText(string $text, int $limit): string
    {
        return Str::limit(Str::squish(strip_tags($text)), $limit, '');
    }
}
