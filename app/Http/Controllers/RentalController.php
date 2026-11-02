<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RentalItem;
use Illuminate\View\View;

class RentalController extends Controller
{
    public function __invoke(): View
    {
        $items = RentalItem::query()->active()->orderBy('sort_order')->orderBy('id')->get();
        $groups = collect(RentalItem::GROUPS)
            ->map(fn (string $heading, string $key) => ['key' => $key, 'heading' => $heading, 'items' => $items->where('group', $key)->values()])
            ->filter(fn (array $group) => $group['items']->isNotEmpty())
            ->values();

        return view('pages.rental', [
            'groups' => $groups,
            'phones' => array_values(array_filter(config('rental.phones', []))),
            'address' => config('rental.address'),
            'seoTitle' => 'Wypożyczalnia sprzętu medycznego Poznań',
            'seoDescription' => 'Wypożycz łóżko rehabilitacyjne, koncentrator tlenu, wózek inwalidzki, podnośnik lub schodołaz w Poznaniu. Dostawa, wniesienie i przygotowanie do użycia.',
        ]);
    }
}
