<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Categories;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;

final class AdminCategoryDestroyController extends Controller
{
    public function __invoke(Category $category): RedirectResponse
    {
        $category->loadCount(['children', 'products']);

        if ($category->products_count > 0 || $category->children_count > 0) {
            return back()->with(
                'error',
                'Nie można usunąć kategorii, która ma produkty albo podkategorie. Najpierw przenieś produkty i podkategorie albo użyj archiwizacji.'
            );
        }

        $category->delete();

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Kategoria została usunięta.');
    }
}
