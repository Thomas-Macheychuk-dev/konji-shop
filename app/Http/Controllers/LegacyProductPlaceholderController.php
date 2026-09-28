<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

final class LegacyProductPlaceholderController extends Controller
{
    public function __invoke(string $slug): View
    {
        $placeholder = config('legacy-product-placeholders.'.$slug);

        abort_unless(is_array($placeholder), 404);

        $canonicalUrl = route('legacy-products.placeholder', ['slug' => $slug]);
        $name = (string) ($placeholder['name'] ?? '');
        $seoDescription = (string) ($placeholder['seo_description'] ?? '');

        abort_if($name === '' || $seoDescription === '', 404);

        return view('pages.products.legacy-placeholder', [
            'placeholder' => $placeholder,
            'seoTitle' => $name,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            'openGraphTitle' => $name,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'product',
            'robots' => 'index, follow',
            'structuredData' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $name,
                    'description' => $seoDescription,
                    'url' => $canonicalUrl,
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'name' => 'Strona główna',
                            'item' => route('home'),
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'name' => $name,
                            'item' => $canonicalUrl,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
