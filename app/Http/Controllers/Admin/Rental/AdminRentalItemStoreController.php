<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Rental;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRentalItemRequest;
use App\Models\RentalItem;
use App\Services\Rental\RentalItemImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class AdminRentalItemStoreController extends Controller
{
    public function __invoke(SaveRentalItemRequest $request, RentalItemImageStorage $images): RedirectResponse
    {
        $attributes = $request->attributesForModel();
        $attributes['slug'] = $this->uniqueSlug((string) $attributes['name']);
        $attributes['sort_order'] ??= ((int) RentalItem::query()->max('sort_order')) + 10;

        if ($request->hasFile('image')) {
            $attributes['image_path'] = $images->store($request->file('image'), $attributes['slug']);
        }

        $item = RentalItem::query()->create($attributes);

        return redirect()
            ->route('admin.rental.edit', $item)
            ->with('success', 'Dodano sprzęt do wypożyczalni.');
    }

    /** The slug identifies the item in enquiry links and e-mails, so it is fixed once created. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'sprzet';
        $slug = $base;
        $suffix = 2;

        while (RentalItem::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
