<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $products = Product::query()
            ->where('status', ProductStatus::ACTIVE)
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

        $productUrls = $products->map(fn (Product $product): array => [
            'loc' => route('products.show', $product->slug),
            'lastmod' => $product->updated_at,
            'changefreq' => 'weekly',
            'priority' => '0.8',
        ]);

        return response()
            ->view('sitemap', [
                'urls' => $staticUrls->merge($productUrls)->values(),
            ])
            ->header('Content-Type', 'application/xml');
    }
}
