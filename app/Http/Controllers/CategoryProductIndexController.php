<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

final class CategoryProductIndexController extends Controller
{
    public function __invoke(Category $category): View
    {
        abort_unless($category->status->isActive(), 404);

        $category->load([
            'children' => function ($query): void {
                $query
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->orderBy('name');
            },
        ]);

        $categoryIds = $this->categoryAndActiveDescendantIds($category);

        $products = Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->whereHas('categories', function ($query) use ($categoryIds): void {
                $query->whereIn('categories.id', $categoryIds);
            })
            ->with([
                'mainImage',
                'images',
                'attributeValueImages',
                'categories:id,name,slug',
                'variants' => function ($query): void {
                    $query
                        ->where('status', ProductVariantStatus::ACTIVE->value)
                        ->orderByDesc('is_default')
                        ->orderBy('id');
                },
            ])
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        $seoTitle = $this->limitText($category->seo_title ?: $category->name, 70);
        $seoDescription = $this->seoDescription($category);
        $canonicalUrl = route('categories.show', $category->slug);

        return view('pages.categories.show', [
            'category' => $category,
            'products' => $products,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'website',
        ]);
    }

    /**
     * @return list<int>
     */
    private function categoryAndActiveDescendantIds(Category $category): array
    {
        $categoryIds = [(int) $category->id];
        $pendingParentIds = $categoryIds;

        while ($pendingParentIds !== []) {
            $childIds = Category::query()
                ->whereIn('parent_id', $pendingParentIds)
                ->where('status', CategoryStatus::ACTIVE->value)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => in_array($id, $categoryIds, true))
                ->values()
                ->all();

            if ($childIds === []) {
                break;
            }

            $categoryIds = [...$categoryIds, ...$childIds];
            $pendingParentIds = $childIds;
        }

        return $categoryIds;
    }

    private function seoDescription(Category $category): ?string
    {
        $description = $category->seo_description ?: $category->description;

        if (! filled($description)) {
            return null;
        }

        return $this->limitText((string) $description, 160);
    }

    private function limitText(string $text, int $limit): string
    {
        return Str::limit(trim(strip_tags($text)), $limit, '');
    }
}
