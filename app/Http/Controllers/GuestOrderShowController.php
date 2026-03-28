<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class GuestOrderShowController extends Controller
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
            'items.product',
            'items.variant.attributeValues.attribute',
            'shippingAddress',
            'billingAddress',
            'payments',
        ]);

        return view('pages.guest-orders.show', [
            'order' => $order,
        ]);
    }
}
