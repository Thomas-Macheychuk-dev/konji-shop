<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use DomainException;
use Illuminate\Http\RedirectResponse;

final class OrderFulfilmentController extends Controller
{
    public function __invoke(Order $order, string $action): RedirectResponse
    {
        try {
            match ($action) {
                'processing' => $order->markFulfilmentAsProcessing(),
                'shipped' => $order->markAsShipped(),
                'delivered' => $order->markAsDelivered(),
                'completed' => $order->complete(),
                default => throw new DomainException('Unsupported fulfilment action.'),
            };
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order fulfilment status updated.');
    }
}
