<?php

declare(strict_types=1);

namespace App\Data\Delivery;

final readonly class ShippingQuoteResult
{
    public function __construct(
        public int $amount,
        public string $currency,
        public string $provider,
        public string $carrier,
        public string $service,
        public ?string $providerServiceCode = null,
        public ?string $providerServiceName = null,
        public array $payload = [],
    ) {}
}
