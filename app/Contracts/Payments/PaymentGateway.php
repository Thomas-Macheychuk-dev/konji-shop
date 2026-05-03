<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Models\Order;
use App\Models\Payment;

interface PaymentGateway
{
    public function providerKey(): string;

    public function initialize(Order $order, Payment $payment): PaymentInitializationResult;

    /**
     * @param array<string, mixed> $payload
     */
    public function parseNotification(array $payload): PaymentNotificationData;

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyNotification(Payment $payment, array $payload): bool;
}
