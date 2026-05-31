<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\VatRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductVariantPricesRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class AdminProductVariantPricesUpdateController extends Controller
{
    public function __invoke(
        UpdateProductVariantPricesRequest $request,
        Product $product,
    ): RedirectResponse {
        $variants = $request->validated('variants');

        DB::transaction(function () use ($product, $variants): void {
            foreach ($variants as $variantId => $data) {
                $vatRate = VatRate::from((int) $data['vat_rate']);

                $product->variants()
                    ->whereKey((int) $variantId)
                    ->update([
                        'price_net_amount' => $vatRate->netFromGross((int) $data['gross_price_amount']),
                        'currency' => $data['currency'],
                        'vat_rate' => $vatRate->value,
                    ]);
            }
        });

        return back()->with('success', 'Variant prices updated.');
    }
}
