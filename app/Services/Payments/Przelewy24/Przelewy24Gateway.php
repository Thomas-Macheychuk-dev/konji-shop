<?php

declare(strict_types=1);

namespace App\Services\Payments\Przelewy24;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Models\Payment;
use RuntimeException;

final class Przelewy24Gateway implements PaymentGateway
{
    public function providerKey(): string
    {
        return PaymentProvider::PRZELEWY24->value;
    }

    public function initialize(Order $order, Payment $payment): PaymentInitializationResult
    {
        return new PaymentInitializationResult(
            provider: PaymentProvider::PRZELEWY24->value,
            providerReference: 'fake-p24-'.$payment->id,
            redirectUrl: route('checkout.success', $order),
            payload: [
                'mode' => 'stub',
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ],
        );
    }

    public function parseNotification(array $payload): PaymentNotificationData
    {
        throw new RuntimeException('Przelewy24 notification parsing is not implemented yet.');
    }

    public function verifyNotification(Payment $payment, array $payload): bool
    {
        throw new RuntimeException('Przelewy24 notification verification is not implemented yet.');
    }
}
