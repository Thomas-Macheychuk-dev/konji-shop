<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Rental;

use App\Http\Controllers\Controller;
use App\Models\RentalItem;
use Illuminate\Contracts\View\View;

final class AdminRentalItemCreateController extends Controller
{
    public function __invoke(): View
    {
        $group = request()->string('group')->toString();

        return view('admin.rental.create', [
            'item' => new RentalItem([
                'group' => array_key_exists($group, RentalItem::GROUPS) ? $group : array_key_first(RentalItem::GROUPS),
                'is_active' => true,
            ]),
        ]);
    }
}
