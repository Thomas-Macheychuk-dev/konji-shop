<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\ProductStatus;
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

        // The storefront only sells active products with a past published_at,
        // and the editor has no date field, so activating publishes now.
        if ($validated['status'] === ProductStatus::ACTIVE->value && $product->published_at === null) {
            $validated['published_at'] = now();
        }

        $product->update($validated);

        if ($shouldUpdateCategory) {
            $product->categories()->sync($categoryId === null ? [] : [
                (int) $categoryId => ['is_primary' => true],
            ]);
        }

        return back()->with('success', 'Produkt zaktualizowany.');
    }
}
