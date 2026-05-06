<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\PaymentInitializationResult;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StartPaymentService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {}

    public function start(Order $order, Payment $payment, string $provider): PaymentInitializationResult
    {
        if ($payment->order_id !== $order->id) {
            throw new RuntimeException('The payment does not belong to the given order.');
        }

        return DB::transaction(function () use ($order, $payment, $provider): PaymentInitializationResult {
            $gateway = $this->gatewayRegistry->for($provider);

            $result = $gateway->initialize($order, $payment);

            $payment->update([
                'provider' => $result->provider,
                'provider_reference' => $result->providerReference,
                'status' => PaymentStatus::PENDING,
                'payload' => $result->payload,
            ]);

            $order->update([
                'payment_status' => PaymentStatus::PENDING,
            ]);

            return $result;
        });
    }
}
