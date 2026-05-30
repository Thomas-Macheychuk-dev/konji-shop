<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentDispatched
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Shipment $shipment,
    ) {}
}
