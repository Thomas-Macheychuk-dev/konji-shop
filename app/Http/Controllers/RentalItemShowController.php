<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RentalItem;
use App\Support\Rental\RentalStructuredData;
use Illuminate\View\View;

class RentalItemShowController extends Controller
{
    public function __invoke(RentalItem $rentalItem, RentalStructuredData $structuredData): View
    {
        abort_unless($rentalItem->is_active, 404);

        $related = RentalItem::query()
            ->active()
            ->where('group', $rentalItem->group)
            ->whereKeyNot($rentalItem->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(4)
            ->get();

        $breadcrumbs = [
            ['name' => 'Strona główna', 'url' => route('home')],
            ['name' => 'Wypożyczalnia sprzętu medycznego', 'url' => route('rental.index')],
            ['name' => $rentalItem->name, 'url' => $rentalItem->url()],
        ];

        return view('pages.rental-item', [
            'item' => $rentalItem,
            'related' => $related,
            'phones' => array_values(array_filter(config('rental.phones', []))),
            'address' => config('rental.address'),
            'seoTitle' => $rentalItem->seoTitle(),
            'seoDescription' => $rentalItem->seoDescription(),
            'canonicalUrl' => $rentalItem->url(),
            'robots' => $rentalItem->shouldBeIndexed() ? null : 'noindex, follow',
            'openGraphImage' => $rentalItem->imageUrl(),
            'openGraphType' => 'product',
            'structuredData' => [
                $structuredData->item($rentalItem),
                $structuredData->breadcrumbs($breadcrumbs),
            ],
        ]);
    }
}
