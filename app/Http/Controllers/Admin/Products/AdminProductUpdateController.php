<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductDetailsRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

final class AdminProductUpdateController extends Controller
{
    public function __invoke(
        UpdateProductDetailsRequest $request,
        Product $product,
    ): RedirectResponse {
        $product->update($request->validated());

        return back()->with('success', 'Product updated.');
    }
}
