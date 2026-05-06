<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class PaymentInitializationResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $provider,
        public string $providerReference,
        public string $redirectUrl,
        public array $payload = [],
    ) {}
}
