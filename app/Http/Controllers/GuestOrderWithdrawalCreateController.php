<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class GuestOrderWithdrawalCreateController extends Controller
{
    public function __invoke(Request $request, Order $order): View
    {
        $guestAccess = $request->session()->get('guest_order_access');

        abort_unless(
            is_array($guestAccess)
            && ($guestAccess['order_id'] ?? null) === $order->id,
            403
        );

        abort_if($order->user_id !== null, 404);
        abort_if($order->placed_at === null, 404);

        $order->load([
            'items.withdrawalRequestItems.withdrawalRequest',
            'shippingAddress',
        ]);

        return view('pages.withdrawals.create', [
            'order' => $order,
            'mode' => 'guest',
            'customerName' => trim((string) optional($order->shippingAddress)->first_name.' '.optional($order->shippingAddress)->last_name),
            'customerEmail' => (string) ($order->guest_email ?: optional($order->shippingAddress)->email),
            'backUrl' => route('guest.orders.show', $order),
            'storeUrl' => route('guest.orders.withdrawals.store', $order),
        ]);
    }
}
