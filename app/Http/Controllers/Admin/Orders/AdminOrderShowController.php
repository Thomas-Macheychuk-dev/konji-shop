<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Delivery\Polkurier\PolkurierCarrierAvailabilityGuard;
use Illuminate\Contracts\View\View;

final class AdminOrderShowController extends Controller
{
    public function __construct(
        private readonly PolkurierCarrierAvailabilityGuard $carrierAvailabilityGuard,
    ) {}

    public function __invoke(Order $order): View
    {
        $order->load([
            'user',
            'addresses',
            'items.product',
            'items.variant',
            'payments',
            'shipments',
            'withdrawalRequests.items',
            'events',
        ]);

        return view('admin.orders.show', [
            'order' => $order,
            'polkurierCarrierAvailabilityCheck' => $this->carrierAvailabilityGuard->check($order),
            'polkurierAdditionalFieldDefinitions' => $this->carrierAvailabilityGuard->additionalFieldDefinitions($order),
        ]);
    }
}
