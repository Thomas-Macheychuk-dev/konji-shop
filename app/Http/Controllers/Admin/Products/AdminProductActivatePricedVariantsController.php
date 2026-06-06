<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\ProductVariantStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

final class AdminProductActivatePricedVariantsController extends Controller
{
    public function __invoke(Product $product): RedirectResponse
    {
        $updated = $product->variants()
            ->where('status', ProductVariantStatus::DRAFT->value)
            ->whereNotNull('price_net_amount')
            ->whereNotNull('currency')
            ->whereNotNull('vat_rate')
            ->update([
                'status' => ProductVariantStatus::ACTIVE->value,
            ]);

        if ($updated === 0) {
            return back()->with('success', 'No priced draft variants were ready to activate.');
        }

        return back()->with('success', $updated.' priced variant'.($updated === 1 ? '' : 's').' activated.');
    }
}
