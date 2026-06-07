<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductVariantStockStatusRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class AdminProductVariantStockStatusUpdateController extends Controller
{
    public function __invoke(
        UpdateProductVariantStockStatusRequest $request,
        Product $product,
    ): RedirectResponse {
        $variants = $request->validated('variants');

        DB::transaction(function () use ($product, $variants): void {
            foreach ($variants as $variantId => $data) {
                $stockStatus = StockStatus::from($data['stock_status']);

                $product->variants()
                    ->whereKey((int) $variantId)
                    ->update([
                        'stock_status' => $stockStatus->value,
                    ]);
            }
        });

        return back()->with('success', 'Statusy dostępności wariantów zostały zaktualizowane.');
    }
}
