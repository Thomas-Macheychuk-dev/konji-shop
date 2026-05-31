<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\CategoryStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\View\View;

final class AdminProductEditController extends Controller
{
    public function __invoke(Product $product): View
    {
        $product->load([
            'mainImage',
            'images',
            'attributeValueImages.attributeValue.attribute',
            'variants.attributeValues.attribute',
            'categories',
        ]);

        $categories = Category::query()
            ->where('status', CategoryStatus::ACTIVE->value)
            ->orderBy('name')
            ->get();

        return view('admin.products.edit', [
            'categories' => $categories,
            'product' => $product,
        ]);
    }
}
