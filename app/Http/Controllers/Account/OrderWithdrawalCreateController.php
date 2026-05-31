<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class OrderWithdrawalCreateController extends Controller
{
    public function __invoke(Request $request, int $orderId): View
    {
        $order = $request->user()
            ->orders()
            ->whereNotNull('placed_at')
            ->with([
                'items.withdrawalRequestItems.withdrawalRequest',
                'shippingAddress',
            ])
            ->findOrFail($orderId);

        return view('pages.withdrawals.create', [
            'order' => $order,
            'mode' => 'account',
            'customerName' => trim((string) optional($order->shippingAddress)->first_name.' '.optional($order->shippingAddress)->last_name),
            'customerEmail' => (string) $request->user()->email,
            'backUrl' => route('account.orders.show', $order->id),
            'storeUrl' => route('account.orders.withdrawals.store', $order->id),
        ]);
    }
}
