<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Rental;

use App\Http\Controllers\Controller;
use App\Models\RentalItem;
use Illuminate\Contracts\View\View;

final class AdminRentalItemEditController extends Controller
{
    public function __invoke(RentalItem $rentalItem): View
    {
        return view('admin.rental.edit', [
            'item' => $rentalItem,
        ]);
    }
}
