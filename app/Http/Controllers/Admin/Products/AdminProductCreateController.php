<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\CategoryStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Contracts\View\View;

final class AdminProductCreateController extends Controller
{
    public function __invoke(): View
    {
        $categories = Category::query()
            ->where('status', CategoryStatus::ACTIVE->value)
            ->orderBy('name')
            ->get();

        return view('admin.products.create', [
            'categories' => $categories,
        ]);
    }
}
