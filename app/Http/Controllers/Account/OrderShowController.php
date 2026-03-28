<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderShowController extends Controller
{
    public function __invoke(Request $request, int $orderId): View
    {
        $order = $request->user()
            ->orders()
            ->whereNotNull('placed_at')
            ->with([
                'items.product',
                'items.variant.attributeValues.attribute',
                'shippingAddress',
                'billingAddress',
                'payments',
            ])
            ->findOrFail($orderId);

        return view('pages.account.orders.show', [
            'order' => $order,
        ]);
    }
}
