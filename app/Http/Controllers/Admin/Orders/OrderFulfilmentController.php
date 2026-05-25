<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Contracts\Delivery\CreatesShipments;
use App\Enums\FulfilmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class OrderFulfilmentController extends Controller
{
    public function __construct(
        private readonly CreatesShipments $createShipmentService,
    ) {}

    public function __invoke(Request $request, Order $order, string $action): RedirectResponse
    {
        try {
            match ($action) {
                'processing' => $order->markFulfilmentAsProcessing(),

                'shipped' => $this->shipOrder($request, $order),

                'delivered' => $this->deliverOrder($order),

                'returned' => $this->returnOrderToSender($order),

                'completed' => $order->complete(),

                default => throw new DomainException(
                    'Unsupported fulfilment action.'
                ),
            };
        } catch (DomainException|RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order fulfilment status updated.');
    }

    private function shipOrder(Request $request, Order $order): void
    {
        if ($order->delivery_service === 'local_pickup') {
            $order->markAsReadyForPickup();

            return;
        }

        if ($order->shipments()
            ->whereNotIn('status', [
                ShipmentStatus::FAILED,
                ShipmentStatus::CANCELLED,
            ])
            ->exists()
        ) {
            throw new DomainException(
                'Shipment already exists for this order.'
            );
        }

        $pickup = $this->polkurierPickupData($request);

        $shipment = $this->createShipmentService->create(
            order: $order,
            provider: $order->delivery_provider->value,
            service: $order->delivery_service,
            lockerCode: $order->delivery_locker_code,
            pickup: $pickup,
        );

        $shipment->markAsDispatched();

        $order->markAsShipped();
    }

    private function deliverOrder(Order $order): void
    {
        if (
            $order->delivery_service === 'local_pickup'
            && $order->fulfilment_status === FulfilmentStatus::READY_FOR_PICKUP
        ) {
            $order->markAsPickedUp();
            $order->complete();

            return;
        }

        $order->markAsDelivered();
    }

    private function returnOrderToSender(Order $order): void
    {
        if (! $order->fulfilment_status->isShipped()) {
            throw new DomainException('Only shipped orders can be marked as returned to sender.');
        }

        $shipment = $order->shipments()
            ->latest()
            ->first();

        if ($shipment === null) {
            throw new DomainException('Cannot return an order without a shipment.');
        }

        $shipment->markAsReturnedToSender();

        $order->markAsReturnedToSender();
    }

    private function polkurierPickupData(Request $request): array
    {
        $noCourierOrder = ! $request->has('polkurier_no_courier_order')
            || $request->boolean('polkurier_no_courier_order');

        if ($noCourierOrder) {
            return [
                'nocourierorder' => true,
            ];
        }

        $validated = $request->validate([
            'polkurier_pickup_date' => ['required', 'date', 'after_or_equal:today'],
            'polkurier_pickup_time_from' => ['required', 'date_format:H:i'],
            'polkurier_pickup_time_to' => ['required', 'date_format:H:i', 'after:polkurier_pickup_time_from'],
        ]);

        return [
            'pickupdate' => $validated['polkurier_pickup_date'],
            'pickuptimefrom' => $validated['polkurier_pickup_time_from'],
            'pickuptimeto' => $validated['polkurier_pickup_time_to'],
            'nocourierorder' => false,
        ];
    }
}
