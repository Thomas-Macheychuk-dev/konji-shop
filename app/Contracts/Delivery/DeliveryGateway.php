<?php

declare(strict_types=1);

namespace App\Contracts\Delivery;

use App\Data\Delivery\ShipmentCreationResult;
use App\Models\Order;
use App\Models\Shipment;

interface DeliveryGateway
{
    public function providerKey(): string;

    public function createShipment(
        Order $order,
        Shipment $shipment,
        array $options = [],
    ): ShipmentCreationResult;
}
