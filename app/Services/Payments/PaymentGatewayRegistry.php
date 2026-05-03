<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use InvalidArgumentException;

final class PaymentGatewayRegistry
{
    /**
     * @param iterable<PaymentGateway> $gateways
     */
    public function __construct(
        private readonly iterable $gateways,
    ) {}

    public function for(string $provider): PaymentGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->providerKey() === $provider) {
                return $gateway;
            }
        }

        throw new InvalidArgumentException("Unsupported payment provider [{$provider}].");
    }
}
