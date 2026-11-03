<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Rental;

use App\Http\Controllers\Controller;
use App\Models\RentalItem;
use App\Services\Rental\RentalItemImageStorage;
use Illuminate\Http\RedirectResponse;

final class AdminRentalItemDestroyController extends Controller
{
    public function __invoke(RentalItem $rentalItem, RentalItemImageStorage $images): RedirectResponse
    {
        $images->delete($rentalItem);
        $rentalItem->delete();

        return redirect()
            ->route('admin.rental.index')
            ->with('success', 'Usunięto „'.$rentalItem->name.'” z wypożyczalni.');
    }
}
