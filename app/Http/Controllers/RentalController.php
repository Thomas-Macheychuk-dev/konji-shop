<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EditablePage;
use App\Models\RentalItem;
use App\Support\Pages\PageContent;
use App\Support\Rental\RentalStructuredData;
use Illuminate\View\View;

class RentalController extends Controller
{
    public function __invoke(RentalStructuredData $structuredData): View
    {
        $items = RentalItem::query()->active()->orderBy('sort_order')->orderBy('id')->get();
        $groups = collect(RentalItem::GROUPS)
            ->map(fn (string $heading, string $key) => ['key' => $key, 'heading' => $heading, 'items' => $items->where('group', $key)->values()])
            ->filter(fn (array $group) => $group['items']->isNotEmpty())
            ->values();

        $page = PageContent::for(EditablePage::RENTAL);

        return view('pages.rental', [
            'page' => $page,
            'groups' => $groups,
            'items' => $items,
            // "Zapytaj o ten sprzęt" links preselect the item in the enquiry form.
            'selectedItems' => old('items', array_filter([request()->string('sprzet')->toString()])),
            'phones' => array_values(array_filter(config('rental.phones', []))),
            'address' => config('rental.address'),
            'seoTitle' => $page->seoTitle,
            'seoDescription' => $page->seoDescription,
            // The ?sprzet= enquiry variants all point back to the one page.
            'canonicalUrl' => route('rental.index'),
            'openGraphImage' => $items->first(fn (RentalItem $item): bool => $item->imageUrl() !== null)?->imageUrl(),
            'structuredData' => [
                $structuredData->business($items, $page->title, (string) ($page->seoDescription ?? $page->title)),
                $structuredData->breadcrumbs([
                    ['name' => 'Strona główna', 'url' => route('home')],
                    ['name' => $page->title, 'url' => route('rental.index')],
                ]),
            ],
        ]);
    }
}
