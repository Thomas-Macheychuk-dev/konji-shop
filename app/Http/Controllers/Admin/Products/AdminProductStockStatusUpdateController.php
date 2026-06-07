<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductStockStatusRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

final class AdminProductStockStatusUpdateController extends Controller
{
    public function __invoke(
        UpdateProductStockStatusRequest $request,
        Product $product,
    ): RedirectResponse {
        $stockStatus = StockStatus::from($request->validated('stock_status'));

        $product->variants()->update([
            'stock_status' => $stockStatus->value,
        ]);

        return back()->with('success', 'Status dostępności zastosowano do wszystkich wariantów.');
    }
}
