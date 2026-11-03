<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Rental;

use App\Http\Controllers\Controller;
use App\Models\RentalItem;
use Illuminate\Contracts\View\View;

final class AdminRentalItemIndexController extends Controller
{
    public function __invoke(): View
    {
        $items = RentalItem::query()->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.rental.index', [
            'groups' => collect(RentalItem::GROUPS)->map(fn (string $heading, string $key): array => [
                'heading' => $heading,
                'items' => $items->where('group', $key)->values(),
            ]),
            'itemCount' => $items->count(),
        ]);
    }
}
