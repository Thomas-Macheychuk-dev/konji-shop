<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Categories;

use App\Enums\CategoryStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AdminCategoryIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount(['children', 'products'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('seo_title', 'like', "%{$search}%")
                        ->orWhere('seo_description', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, CategoryStatus::options(), true), function ($query) use ($status): void {
                $query->where('status', $status);
            })
            ->orderBy('parent_id')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.categories.index', [
            'categories' => $categories,
            'search' => $search,
            'selectedStatus' => $status,
        ]);
    }
}
