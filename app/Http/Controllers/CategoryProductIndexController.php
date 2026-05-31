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

        $categoryIds = $category->children
            ->pluck('id')
            ->prepend($category->id)
            ->all();

        $products = Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->whereHas('categories', function ($query) use ($categoryIds): void {
                $query->whereIn('categories.id', $categoryIds);
            })
            ->with([
                'mainImage',
                'images',
                'attributeValueImages',
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
