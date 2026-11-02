<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreRentalEnquiryRequest;
use App\Mail\RentalEnquiryMail;
use App\Models\RentalItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class RentalEnquiryStoreController extends Controller
{
    public function __invoke(StoreRentalEnquiryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $items = RentalItem::query()
            ->whereIn('slug', $data['items'] ?? [])
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

        Mail::to(config('rental.enquiry_email'))->queue(new RentalEnquiryMail(
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            items: $items,
            period: $data['period'] ?? null,
            city: $data['city'] ?? null,
            body: $data['message'],
        ));

        return redirect()
            ->to(route('rental.index').'#zapytanie')
            ->with('rental_enquiry_sent', true);
    }
}
