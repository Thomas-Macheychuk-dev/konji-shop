<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;

final class AdminOrderShowController extends Controller
{
    public function __invoke(Order $order): View
    {
        $order->load([
            'user',
            'items.product',
            'addresses',
            'payments',
            'events',
        ]);

        return view('admin.orders.show', [
            'order' => $order,
        ]);
    }
}
