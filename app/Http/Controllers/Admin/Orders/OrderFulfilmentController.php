<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Contracts\Delivery\CreatesShipments;
use App\Enums\FulfilmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use DomainException;
use Illuminate\Http\RedirectResponse;

final class OrderFulfilmentController extends Controller
{
    public function __construct(
        private readonly CreatesShipments $createShipmentService,
    ) {}

    public function __invoke(Order $order, string $action): RedirectResponse
    {
        try {
            match ($action) {
                'processing' => $order->markFulfilmentAsProcessing(),

                'shipped' => $this->shipOrder($order),

                'delivered' => $this->deliverOrder($order),

                'returned' => $this->returnOrderToSender($order),

                'completed' => $order->complete(),

                default => throw new DomainException(
                    'Unsupported fulfilment action.'
                ),
            };
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order fulfilment status updated.');
    }

    private function shipOrder(Order $order): void
    {
        if ($order->delivery_service === 'pickup') {
            $order->markAsReadyForPickup();

            return;
        }

        if ($order->shipments()->exists()) {
            throw new DomainException(
                'Shipment already exists for this order.'
            );
        }

        $shipment = $this->createShipmentService->create(
            order: $order,
            provider: $order->delivery_provider->value,
            service: $order->delivery_service,
            lockerCode: $order->delivery_locker_code,
        );

        $shipment->markAsDispatched();

        $order->markAsShipped();
    }

    private function deliverOrder(Order $order): void
    {
        if (
            $order->delivery_service === 'pickup'
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
}
