<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\View\View;

final class AdminProductEditController extends Controller
{
    public function __invoke(Product $product): View
    {
        $product->load([
            'variants.attributeValues.attribute',
        ]);

        return view('admin.products.edit', [
            'product' => $product,
        ]);
    }
}
