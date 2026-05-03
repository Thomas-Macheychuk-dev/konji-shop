<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class PaymentNotificationData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $providerReference,
        public bool $isSuccessful,
        public ?string $externalStatus = null,
        public array $payload = [],
    ) {}
}
