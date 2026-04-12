<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutSuccessController extends Controller
{
    public function __invoke(Request $request, Order $order): View|RedirectResponse
    {
        $lastOrderId = $request->session()->get('checkout.last_order_id');

        if ((int) $lastOrderId !== $order->id) {
            return redirect()
                ->route('cart.show')
                ->with('error', 'You are not allowed to view that order confirmation page.');
        }

        if ($request->user() && $order->user_id !== $request->user()->id) {
            return redirect()
                ->route('cart.show')
                ->with('error', 'You are not allowed to view that order confirmation page.');
        }

        $order->load([
            'items.product',
            'items.variant.attributeValues.attribute',
            'items.variant.attributeValues.productImage',
            'shippingAddress',
            'billingAddress',
            'payments',
        ]);

        return view('pages.checkout.success', [
            'order' => $order,
        ]);
    }
}
