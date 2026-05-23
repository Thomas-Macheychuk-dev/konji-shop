<?php

declare(strict_types=1);

namespace App\Contracts\Delivery;

use App\Models\Order;
use App\Models\Shipment;

interface CreatesShipments
{
    public function create(
        Order $order,
        string $provider,
        ?string $service = null,
        ?string $lockerCode = null,
    ): Shipment;
}
