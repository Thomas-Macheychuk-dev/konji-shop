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
        $validated = $request->validated();
        $shouldUpdateCategory = array_key_exists('category_id', $validated);
        $categoryId = $validated['category_id'] ?? null;

        unset($validated['category_id']);

        $product->update($validated);

        if ($shouldUpdateCategory) {
            $product->categories()->sync($categoryId === null ? [] : [
                (int) $categoryId => ['is_primary' => true],
            ]);
        }

        return back()->with('success', 'Product updated.');
    }
}
