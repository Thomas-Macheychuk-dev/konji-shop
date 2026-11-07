<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Storefront\ProductListing;
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

        $brands = $this->brandValues()
            ->with(['attribute', 'manufacturer'])
            ->withCount(['products as active_products_count' => fn (Builder $query) => $query->where('status', ProductStatus::ACTIVE->value)])
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

    private function brandValues(): Builder
    {
        return AttributeValue::query()
            ->whereHas('attribute', fn (Builder $query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS))
            ->whereHas('products', fn (Builder $query) => $query->where('status', ProductStatus::ACTIVE->value));
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
