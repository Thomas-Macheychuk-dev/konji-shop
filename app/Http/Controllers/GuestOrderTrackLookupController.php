<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LookupGuestOrderRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

class GuestOrderTrackLookupController extends Controller
{
    public function __invoke(LookupGuestOrderRequest $request): RedirectResponse
    {
        $number = $request->string('number')->toString();
        $email = $request->string('email')->toString();

        if ($request->user() && mb_strtolower((string) $request->user()->email) === $email) {
            $registeredOrder = $request->user()
                ->orders()
                ->where('number', $number)
                ->whereNotNull('placed_at')
                ->first();

            if ($registeredOrder) {
                return redirect()->route('account.orders.show', $registeredOrder->id);
            }
        }

        $guestOrder = Order::query()
            ->where('number', $number)
            ->whereRaw('LOWER(guest_email) = ?', [$email])
            ->whereNull('user_id')
            ->whereNotNull('placed_at')
            ->first();

        if (! $guestOrder) {
            return back()
                ->withInput()
                ->withErrors([
                    'number' => 'We could not find an order matching those details.',
                ]);
        }

        session([
            'guest_order_access' => [
                'order_id' => $guestOrder->id,
                'email' => $email,
            ],
        ]);

        return redirect()->route('guest.orders.show', $guestOrder);
    }
}
