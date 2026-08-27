<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\PaymentRefundStatus;

final readonly class PaynowRefundResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerRefundId,
        public PaymentRefundStatus $status,
        public array $payload = [],
        public ?string $failureReason = null,
    ) {}
}
