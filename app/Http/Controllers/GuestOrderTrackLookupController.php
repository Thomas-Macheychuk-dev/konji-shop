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
        $order = Order::query()
            ->where('number', $request->string('number')->toString())
            ->whereRaw('LOWER(guest_email) = ?', [$request->string('email')->toString()])
            ->whereNull('user_id')
            ->whereNotNull('placed_at')
            ->first();

        if (! $order) {
            return back()
                ->withInput()
                ->withErrors([
                    'number' => 'We could not find an order matching those details.',
                ]);
        }

        session([
            'guest_order_access' => [
                'order_id' => $order->id,
                'email' => $request->string('email')->toString(),
            ],
        ]);

        return redirect()->route('guest.orders.show', $order);
    }
}
