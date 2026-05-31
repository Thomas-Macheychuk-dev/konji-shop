<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $categories = Category::query()
            ->where('status', CategoryStatus::ACTIVE->value)
            ->whereNotNull('slug')
            ->orderByDesc('updated_at')
            ->get(['slug', 'updated_at']);

        $products = Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->whereNotNull('slug')
            ->orderByDesc('updated_at')
            ->get(['slug', 'updated_at']);

        $staticUrls = collect([
            [
                'loc' => route('home'),
                'lastmod' => now(),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'loc' => route('legal.terms'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ],
            [
                'loc' => route('legal.privacy'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ],
            [
                'loc' => route('legal.returns'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ],
            [
                'loc' => route('legal.complaints'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ],
            [
                'loc' => route('legal.delivery-payments'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ],
            [
                'loc' => route('legal.contact'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ],
        ]);

        $categoryUrls = $categories->map(fn (Category $category): array => [
            'loc' => route('categories.show', $category->slug),
            'lastmod' => $category->updated_at,
            'changefreq' => 'weekly',
            'priority' => '0.7',
        ]);

        $productUrls = $products->map(fn (Product $product): array => [
            'loc' => route('products.show', $product->slug),
            'lastmod' => $product->updated_at,
            'changefreq' => 'weekly',
            'priority' => '0.8',
        ]);

        return response()
            ->view('sitemap', [
                'urls' => $staticUrls
                    ->merge($categoryUrls)
                    ->merge($productUrls)
                    ->values(),
            ])
            ->header('Content-Type', 'application/xml');
    }
}
