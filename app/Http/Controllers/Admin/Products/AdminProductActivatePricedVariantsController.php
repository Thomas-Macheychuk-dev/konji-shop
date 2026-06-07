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
            return back()->with('success', 'Brak wycenionych wariantów w szkicu gotowych do aktywacji.');
        }

        return back()->with('success', trans_choice('{1} :count wyceniony wariant został aktywowany.|[2,*] :count wycenione warianty zostały aktywowane.', $updated, ['count' => $updated]));
    }
}
