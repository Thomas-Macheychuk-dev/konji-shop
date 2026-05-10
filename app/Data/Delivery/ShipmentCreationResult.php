<?php

declare(strict_types=1);

namespace App\Data\Delivery;

final readonly class ShipmentCreationResult
{
    public function __construct(
        public string $provider,
        public string $providerReference,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public array $payload = [],
    ) {}
}
