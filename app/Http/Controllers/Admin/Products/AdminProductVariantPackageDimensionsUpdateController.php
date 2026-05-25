<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductVariantPackageDimensionsRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class AdminProductVariantPackageDimensionsUpdateController extends Controller
{
    public function __invoke(
        UpdateProductVariantPackageDimensionsRequest $request,
        Product $product,
    ): RedirectResponse {
        $variants = $request->validated('variants');

        DB::transaction(function () use ($product, $variants): void {
            foreach ($variants as $variantId => $data) {
                $product->variants()
                    ->whereKey((int) $variantId)
                    ->update([
                        'package_weight_grams' => $data['package_weight_grams'] ?? null,
                        'package_length_mm' => $data['package_length_mm'] ?? null,
                        'package_width_mm' => $data['package_width_mm'] ?? null,
                        'package_height_mm' => $data['package_height_mm'] ?? null,
                    ]);
            }
        });

        return back()->with('success', 'Variant package dimensions updated.');
    }
}
