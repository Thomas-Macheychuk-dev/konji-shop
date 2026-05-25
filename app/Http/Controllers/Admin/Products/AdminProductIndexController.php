<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AdminProductIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $missingPackageData = $request->boolean('missing_package_data');

        $products = Product::query()
            ->withCount('variants')
            ->withCount([
                'variants as variants_missing_package_data_count' => function ($query): void {
                    $this->missingPackageDataQuery($query);
                },
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('external_source', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhere('external_parent_sku', 'like', "%{$search}%")
                        ->orWhereHas('variants', function ($query) use ($search): void {
                            $query->where('sku', 'like', "%{$search}%");
                        });
                });
            })
            ->when($missingPackageData, function ($query): void {
                $query->whereHas('variants', function ($query): void {
                    $this->missingPackageDataQuery($query);
                });
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'search' => $search,
            'missingPackageData' => $missingPackageData,
        ]);
    }

    private function missingPackageDataQuery($query): void
    {
        $query->where(function ($query): void {
            $query
                ->whereNull('package_weight_grams')
                ->orWhereNull('package_length_mm')
                ->orWhereNull('package_width_mm')
                ->orWhereNull('package_height_mm')
                ->orWhere('package_weight_grams', '<=', 0)
                ->orWhere('package_length_mm', '<=', 0)
                ->orWhere('package_width_mm', '<=', 0)
                ->orWhere('package_height_mm', '<=', 0);
        });
    }
}
