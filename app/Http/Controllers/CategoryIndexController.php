<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Storefront\RootCategories;
use Illuminate\Contracts\View\View;

/**
 * All top-level categories: the target of "Zobacz wszystkie działy" and of anyone trimming a category URL.
 */
final class CategoryIndexController extends Controller
{
    public function __invoke(RootCategories $rootCategories): View
    {
        $seoTitle = 'Wszystkie kategorie produktów';
        $seoDescription = 'Ortezy, stabilizatory, kompresja, odzież medyczna, sprzęt rehabilitacyjny i opieka nad pacjentem — wszystkie działy sklepu w jednym miejscu.';

        return view('pages.categories.index', [
            'categories' => $rootCategories->withProductCounts(),
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => route('categories.index'),
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'website',
        ]);
    }
}
