<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\VatRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductPricesRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

final class AdminProductPricesUpdateController extends Controller
{
    public function __invoke(
        UpdateProductPricesRequest $request,
        Product $product,
    ): RedirectResponse {
        $vatRate = VatRate::from((int) $request->validated('vat_rate'));
        $grossPriceAmount = $request->grossPriceAmount();

        $product->variants()->update([
            'price_net_amount' => $vatRate->netFromGross($grossPriceAmount),
            'price_gross_amount' => $grossPriceAmount,
            'currency' => $request->validated('currency'),
            'vat_rate' => $vatRate->value,
        ]);

        return back()->with('success', 'Price applied to all variants.');
    }
}
