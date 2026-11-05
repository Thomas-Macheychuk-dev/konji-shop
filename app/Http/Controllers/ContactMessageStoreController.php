<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\ContactMessageMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class ContactMessageStoreController extends Controller
{
    public function __invoke(StoreContactMessageRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Mail::to(config('legal.seller.email'))->queue(new ContactMessageMail(
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            orderNumber: $data['order_number'] ?? null,
            body: $data['message'],
        ));

        return redirect()
            ->to(route('legal.contact').'#formularz')
            ->with('contact_message_sent', true);
    }
}
