<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Contracts\Delivery\CreatesShipments;
use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateShipmentService implements CreatesShipments
{
    public function __construct(
        private readonly DeliveryGatewayRegistry $registry,
    ) {}

    public function create(
        Order $order,
        string $provider,
        ?string $service = null,
        ?string $lockerCode = null,
        ?array $pickup = null,
    ): Shipment {
        return DB::transaction(function () use ($order, $provider, $service, $lockerCode, $pickup): Shipment {
            if (! $order->status->isConfirmed()) {
                throw new RuntimeException('Only confirmed orders can have shipments created.');
            }

            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'provider' => DeliveryProvider::from($provider),
                'status' => ShipmentStatus::PENDING,
                'service' => $service,
                'locker_code' => $lockerCode,
            ]);

            $gateway = $this->registry->for($provider);

            $result = $gateway->createShipment($order, $shipment, [
                'pickup' => $pickup ?? [
                    'nocourierorder' => true,
                ],
            ]);

            $shipment->update([
                'provider_reference' => $result->providerReference,
                'tracking_number' => $result->trackingNumber,
                'tracking_url' => $result->trackingUrl,
                'payload' => $result->payload,
            ]);

            $shipment->markAsCreated($result->providerReference, $result->payload);

            return $shipment->refresh();
        });
    }
}
