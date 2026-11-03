<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Rental;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRentalItemRequest;
use App\Models\RentalItem;
use App\Services\Rental\RentalItemImageStorage;
use Illuminate\Http\RedirectResponse;

final class AdminRentalItemUpdateController extends Controller
{
    public function __invoke(
        SaveRentalItemRequest $request,
        RentalItem $rentalItem,
        RentalItemImageStorage $images,
    ): RedirectResponse {
        $attributes = $request->attributesForModel();
        $attributes['sort_order'] ??= $rentalItem->sort_order;

        if ($request->hasFile('image')) {
            $images->delete($rentalItem);
            $attributes['image_path'] = $images->store($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $images->delete($rentalItem);
            $attributes['image_path'] = null;
        }

        $rentalItem->update($attributes);

        return back()->with('success', 'Zapisano zmiany sprzętu.');
    }
}
