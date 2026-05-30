<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use App\Services\Delivery\Polkurier\PolkurierCarrierAvailabilityGuard;

final class AdminOrderShowController extends Controller
{
    public function __construct(
        private readonly PolkurierCarrierAvailabilityGuard $carrierAvailabilityGuard,
    ) {}

    public function __invoke(Order $order): View
    {
        $order->load([
            // keep your existing relations here
        ]);

        return view('admin.orders.show', [
            'order' => $order,
            'polkurierCarrierAvailabilityCheck' => $this->carrierAvailabilityGuard->check($order),
        ]);
    }
}
