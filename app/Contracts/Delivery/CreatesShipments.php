<?php

declare(strict_types=1);

namespace App\Contracts\Delivery;

use App\Models\Order;
use App\Models\Shipment;

interface CreatesShipments
{
    /**
     * @param array<string, mixed>|null $pickup
     * @param array<string, string> $additionalFields
     */
    public function create(
        Order $order,
        string $provider,
        ?string $service = null,
        ?string $lockerCode = null,
        ?array $pickup = null,
        array $additionalFields = [],
    ): Shipment;
}
