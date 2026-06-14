<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Categories;

use App\Enums\CategoryStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;

final class AdminCategoryArchiveController extends Controller
{
    public function __invoke(Category $category): RedirectResponse
    {
        $category->update([
            'status' => CategoryStatus::ARCHIVED,
        ]);

        return back()->with('success', 'Kategoria została zarchiwizowana.');
    }
}
