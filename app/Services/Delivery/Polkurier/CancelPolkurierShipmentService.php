<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use DomainException;
use RuntimeException;

final class CancelPolkurierShipmentService
{
    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    public function cancel(Shipment $shipment): Shipment
    {
        if ($shipment->provider !== DeliveryProvider::POLKURIER) {
            throw new DomainException('Only Polkurier shipments can be cancelled through Polkurier.');
        }

        if (! $shipment->provider_reference) {
            throw new DomainException('Shipment has no Polkurier order number.');
        }

        if (! in_array($shipment->status, [
            ShipmentStatus::PENDING,
            ShipmentStatus::CREATED,
            ShipmentStatus::DISPATCHED,
        ], true)) {
            throw new DomainException('Only pending, created or recently dispatched shipments can be cancelled.');
        }

        $payload = $this->client->cancelOrder($shipment->provider_reference);

        $shipment->markAsCancelled(array_merge($shipment->payload ?? [], [
            'polkurier_cancellation' => $payload['response'] ?? $payload,
        ]));

        return $shipment->refresh();
    }
}
