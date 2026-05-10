<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class HandlePaymentNotificationService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(string $providerKey, array $payload, string $rawBody): void
    {
        DB::transaction(function () use ($providerKey, $payload, $rawBody): void {
            $gateway = $this->registry->for($providerKey);

            $payment = Payment::query()
                ->where('provider_reference', $payload['paymentId'] ?? '')
                ->orWhere('order_id', $payload['externalId'] ?? null)
                ->firstOrFail();

            if (! $gateway->verifyNotification($payment, $payload, $rawBody)) {
                throw new RuntimeException('Invalid Paynow signature');
            }

            $notificationData = $gateway->parseNotification($payload);

            $payment->recordProviderReference($notificationData->providerReference);

            if ($notificationData->externalStatus === 'CONFIRMED') {
                $payment->markAsPaid();
                $payment->order->markAsPaid();
            } elseif (in_array($notificationData->externalStatus, ['REJECTED', 'ERROR'], true)) {
                $payment->markAsFailed();
            }

            $payment->recordNotification(
                $notificationData->externalStatus,
                $notificationData->payload,
            );
        });
    }
}
