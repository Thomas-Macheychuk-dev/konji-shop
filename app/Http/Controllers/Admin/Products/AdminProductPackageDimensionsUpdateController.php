<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductPackageDimensionsRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

final class AdminProductPackageDimensionsUpdateController extends Controller
{
    public function __invoke(
        UpdateProductPackageDimensionsRequest $request,
        Product $product,
    ): RedirectResponse {
        $product->variants()->update($request->validated());

        return back()->with('success', 'Package dimensions applied to all variants.');
    }
}
