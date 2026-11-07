<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Storefront\ProductListing;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Brands ("Producent"/"Marka" values) with active products, most products first,
 * and whether their GPSR manufacturer details are filled in.
 */
final class AdminManufacturerIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim($request->string('search')->toString());
        $onlyMissing = $request->boolean('missing');
        $usingValue = $this->activeProductsUsingValue();

        $brands = $this->brandValues($usingValue)
            ->with(['attribute', 'manufacturer'])
            ->withCount(['products as active_products_count' => $usingValue])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('value', 'like', '%'.$search.'%')
                ->orWhereHas('manufacturer', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))))
            ->when($onlyMissing, fn (Builder $query) => $query->whereDoesntHave('manufacturer'))
            ->orderByDesc('active_products_count')
            ->orderBy('value')
            ->paginate(50)
            ->withQueryString();

        return view('admin.manufacturers.index', [
            'brands' => $brands,
            'search' => $search,
            'onlyMissing' => $onlyMissing,
            'coverage' => $this->coverage(),
        ]);
    }

    /**
     * Brand values some active product takes its details from, plus any value whose details are already filled in.
     */
    private function brandValues(Closure $usingValue): Builder
    {
        return AttributeValue::query()
            ->whereHas('attribute', fn (Builder $query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS))
            ->where(fn (Builder $query) => $query->whereHas('products', $usingValue)->orWhereHas('manufacturer'));
    }

    /**
     * Constrains a brand value's products to the active ones whose page shows this value's details:
     * a "Marka" value only counts products without a "Producent" value, the order
     * ProductManufacturerInformation reads them in. A brand set under both is then listed once.
     */
    private function activeProductsUsingValue(): Closure
    {
        $attributeIds = Attribute::query()->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS)->pluck('id', 'slug');
        $ranked = collect(ProductListing::BRAND_ATTRIBUTE_SLUGS)
            ->map(fn (string $slug): ?int => $attributeIds[$slug] ?? null)
            ->filter()
            ->values();

        return fn (Builder $query) => $query
            ->where('status', ProductStatus::ACTIVE->value)
            ->where(function (Builder $query) use ($ranked): void {
                foreach ($ranked as $rank => $attributeId) {
                    $preferred = $ranked->take($rank)->all();

                    $query->orWhere(fn (Builder $query) => $query
                        ->where('attribute_values.attribute_id', $attributeId)
                        ->when($preferred !== [], fn (Builder $query) => $query
                            ->whereDoesntHave('attributeValues', fn (Builder $query) => $query->whereIn('attribute_id', $preferred))));
                }
            });
    }

    /**
     * Active products by whether any of their brand values has manufacturer details.
     *
     * @return array{active: int, covered: int, without_brand: int}
     */
    private function coverage(): array
    {
        $active = fn () => Product::query()->where('status', ProductStatus::ACTIVE->value);
        $brandValue = fn (Builder $query) => $query->whereHas('attribute', fn (Builder $query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS));

        return [
            'active' => $active()->count(),
            'covered' => $active()->whereHas('attributeValues', fn (Builder $query) => $brandValue($query)->whereHas('manufacturer'))->count(),
            'without_brand' => $active()->whereDoesntHave('attributeValues', $brandValue)->count(),
        ];
    }
}
