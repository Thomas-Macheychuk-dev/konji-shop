<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AdminOrderIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $orders = Order::query()
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('guest_email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->toString() !== '', function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->when($request->string('payment_status')->toString() !== '', function ($query) use ($request): void {
                $query->where('payment_status', $request->string('payment_status')->toString());
            })
            ->when($request->string('fulfilment_status')->toString() !== '', function ($query) use ($request): void {
                $query->where('fulfilment_status', $request->string('fulfilment_status')->toString());
            })
            ->latest('placed_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => OrderStatus::cases(),
            'paymentStatuses' => PaymentStatus::cases(),
            'fulfilmentStatuses' => FulfilmentStatus::cases(),
        ]);
    }
}
