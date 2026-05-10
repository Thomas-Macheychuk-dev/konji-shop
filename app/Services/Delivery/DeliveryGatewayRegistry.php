<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Contracts\Delivery\DeliveryGateway;
use InvalidArgumentException;

final class DeliveryGatewayRegistry
{
    /**
     * @param iterable<DeliveryGateway> $gateways
     */
    public function __construct(
        private readonly iterable $gateways,
    ) {}

    public function for(string $provider): DeliveryGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->providerKey() === $provider) {
                return $gateway;
            }
        }

        throw new InvalidArgumentException("Unsupported delivery provider [{$provider}].");
    }
}
