<?php

declare(strict_types=1);

namespace App\Services\Delivery\InPost;

use App\Contracts\Delivery\DeliveryGateway;
use App\Data\Delivery\ShipmentCreationResult;
use App\Enums\DeliveryProvider;
use App\Models\Order;
use App\Models\Shipment;

final class InPostDeliveryGateway implements DeliveryGateway
{
    public function providerKey(): string
    {
        return DeliveryProvider::INPOST->value;
    }

    public function createShipment(Order $order, Shipment $shipment): ShipmentCreationResult
    {
        return new ShipmentCreationResult(
            provider: DeliveryProvider::INPOST->value,
            providerReference: 'local-inpost-'.$shipment->id,
            trackingNumber: null,
            trackingUrl: null,
            payload: [
                'mode' => 'placeholder',
            ],
        );
    }
}
