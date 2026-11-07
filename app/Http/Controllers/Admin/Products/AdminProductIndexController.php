<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AdminProductIndexController extends Controller
{
    /**
     * Filter value for products without an external source (created by hand).
     */
    public const NO_EXTERNAL_SOURCE = '__none__';

    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $externalSource = trim((string) $request->query('external_source', ''));
        $missingPackageData = $request->boolean('missing_package_data');
        $withoutPricedVariants = $request->boolean('without_priced_variants');
        $unavailable = $request->boolean('unavailable');

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
            ->when($externalSource === self::NO_EXTERNAL_SOURCE, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->whereNull('external_source')
                        ->orWhere('external_source', '');
                });
            })
            ->when($externalSource !== '' && $externalSource !== self::NO_EXTERNAL_SOURCE, function ($query) use ($externalSource): void {
                $query->where('external_source', $externalSource);
            })
            ->when($missingPackageData, function ($query): void {
                $query->whereHas('variants', function ($query): void {
                    $this->missingPackageDataQuery($query);
                });
            })
            ->when($withoutPricedVariants, function ($query): void {
                $query->whereDoesntHave('variants', function ($query): void {
                    $this->pricedQuery($query);
                });
            })
            ->when($unavailable, function ($query): void {
                $this->unavailableQuery($query);
            })
            ->orderBy('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'search' => $search,
            'externalSource' => $externalSource,
            'externalSources' => Product::query()
                ->whereNotNull('external_source')
                ->where('external_source', '!=', '')
                ->distinct()
                ->orderBy('external_source')
                ->pluck('external_source'),
            'noExternalSource' => self::NO_EXTERNAL_SOURCE,
            'missingPackageData' => $missingPackageData,
            'withoutPricedVariants' => $withoutPricedVariants,
            'unavailable' => $unavailable,
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

    /**
     * Mirrors the pricing CartService::addItem() needs: a gross price, or a net price with a VAT rate.
     */
    private function pricedQuery($query): void
    {
        $query
            ->whereNotNull('currency')
            ->whereNotNull('vat_rate')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('price_gross_amount')
                    ->orWhereNotNull('price_net_amount');
            });
    }

    /**
     * Products a customer cannot add to the cart: not active, not yet published,
     * or without an active, in-stock (or preorder), priced variant.
     */
    private function unavailableQuery($query): void
    {
        $query->where(function ($query): void {
            $query
                ->where('status', '!=', ProductStatus::ACTIVE->value)
                ->orWhereNull('published_at')
                ->orWhere('published_at', '>', now())
                ->orWhereDoesntHave('variants', function ($query): void {
                    $query
                        ->where('status', ProductVariantStatus::ACTIVE->value)
                        ->where(function ($query): void {
                            $query
                                ->whereNull('stock_status')
                                ->orWhere('stock_status', '!=', StockStatus::OUT_OF_STOCK->value);
                        });

                    $this->pricedQuery($query);
                });
        });
    }
}
