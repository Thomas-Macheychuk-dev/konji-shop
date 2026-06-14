<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Categories;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Contracts\View\View;

final class AdminCategoryEditController extends Controller
{
    public function __invoke(Category $category): View
    {
        return view('admin.categories.edit', [
            'category' => $category->loadCount(['children', 'products']),
            'parentCategories' => Category::query()
                ->whereKeyNot($category->id)
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']),
        ]);
    }
}
