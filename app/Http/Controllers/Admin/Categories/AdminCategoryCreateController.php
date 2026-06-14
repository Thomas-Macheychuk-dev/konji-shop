<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Categories;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Contracts\View\View;

final class AdminCategoryCreateController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.categories.create', [
            'parentCategories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']),
        ]);
    }
}
