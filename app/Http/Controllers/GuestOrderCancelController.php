<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GuestOrderCancelController extends Controller
{
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $guestAccess = $request->session()->get('guest_order_access');

        abort_unless(
            is_array($guestAccess)
            && ($guestAccess['order_id'] ?? null) === $order->id,
            403
        );

        abort_if(! $order->isGuestOrder(), 404);
        abort_if(! $order->isPlaced(), 404);

        if (! $order->canBeCancelled()) {
            return redirect()
                ->route('guest.orders.show', $order)
                ->with('error', 'Tego zamówienia nie można już anulować.');
        }

        $order->cancel(
            'Cancelled by guest customer on '.now()->format('Y-m-d H:i:s')
        );

        return redirect()
            ->route('guest.orders.show', $order)
            ->with('success', 'Zamówienie zostało anulowane.');
    }
}
