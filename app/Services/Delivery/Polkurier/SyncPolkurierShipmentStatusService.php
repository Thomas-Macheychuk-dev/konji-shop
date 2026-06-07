<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use RuntimeException;

final class SyncPolkurierShipmentStatusService
{
    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    public function sync(Shipment $shipment): Shipment
    {
        if ($shipment->provider !== DeliveryProvider::POLKURIER) {
            throw new RuntimeException('Only Polkurier shipments can be synced.');
        }

        if (! $shipment->provider_reference) {
            throw new RuntimeException('Przesyłka nie ma numeru zamówienia Polkurier.');
        }

        $statusData = $this->client->shipmentStatus($shipment->provider_reference);

        $payload = array_merge($shipment->payload ?? [], [
            'polkurier_status' => $statusData,
        ]);

        $statusCode = (string) ($statusData['status_code'] ?? '');

        match ($statusCode) {
            'O', 'P' => $this->syncCreated($shipment, $payload),
            'WP' => $this->syncInTransit($shipment, $payload),
            'D' => $this->syncDelivered($shipment, $payload),
            'A' => $shipment->markAsCancelled($payload),
            'Z' => $this->syncReturned($shipment, $payload),
            'W' => $shipment->markAsFailed($payload),
            default => $shipment->syncProviderPayload($payload),
        };

        return $shipment->refresh();
    }

    private function syncCreated(Shipment $shipment, array $payload): void
    {
        if ($shipment->status === ShipmentStatus::PENDING) {
            $shipment->markAsCreated($shipment->provider_reference, $payload);

            return;
        }

        $shipment->syncProviderPayload($payload);
    }

    private function syncInTransit(Shipment $shipment, array $payload): void
    {
        if ($shipment->status !== ShipmentStatus::IN_TRANSIT) {
            $shipment->markAsInTransit($payload);
        } else {
            $shipment->syncProviderPayload($payload);
        }

        $order = $shipment->order;

        if ($order->fulfilment_status === FulfilmentStatus::PROCESSING) {
            $order->markAsShipped();
        }
    }

    private function syncDelivered(Shipment $shipment, array $payload): void
    {
        if ($shipment->status !== ShipmentStatus::DELIVERED) {
            $shipment->markAsDelivered($payload);
        } else {
            $shipment->syncProviderPayload($payload);
        }

        $order = $shipment->order;

        if ($order->fulfilment_status === FulfilmentStatus::PROCESSING) {
            $order->markAsShipped();
        }

        if ($order->fulfilment_status === FulfilmentStatus::SHIPPED) {
            $order->markAsDelivered();
        }
    }

    private function syncReturned(Shipment $shipment, array $payload): void
    {
        if ($shipment->status !== ShipmentStatus::RETURNED) {
            $shipment->markAsReturnedToSender($payload);
        }

        $order = $shipment->order;

        if ($order->fulfilment_status->isShipped()) {
            $order->markAsReturnedToSender();
        }
    }
}
