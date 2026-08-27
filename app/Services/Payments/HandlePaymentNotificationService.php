<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\PaymentNotificationData;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class HandlePaymentNotificationService
{
    /** @var list<string> */
    private const PENDING_STATUSES = ['NEW', 'PENDING'];

    /** @var list<string> */
    private const FAILED_STATUSES = ['REJECTED', 'ERROR', 'EXPIRED', 'ABANDONED'];

    public function __construct(
        private readonly PaymentGatewayRegistry $registry
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws Throwable
     */
    public function handle(string $providerKey, array $payload, string $rawBody): void
    {
        $gateway = $this->registry->for($providerKey);
        $notificationData = $gateway->parseNotification($payload);

        DB::transaction(function () use ($gateway, $providerKey, $payload, $rawBody, $notificationData): void {
            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->where('provider', $providerKey)
                ->where('provider_reference', $notificationData->providerReference)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw (new ModelNotFoundException)->setModel(Payment::class);
            }

            /** @var Order $order */
            $order = Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment->setRelation('order', $order);

            if (! $gateway->verifyNotification($payment, $payload, $rawBody)) {
                throw new RuntimeException('Invalid payment notification signature.');
            }

            if ((string) $order->id !== $notificationData->externalId) {
                throw new RuntimeException('Payment notification external ID does not match the payment order.');
            }

            $incomingModifiedAt = $this->parseModifiedAt($notificationData);

            if ($this->isDuplicateOrStale($payment, $notificationData, $incomingModifiedAt)) {
                return;
            }

            if ($this->wouldRegressLocalState($payment, $notificationData)) {
                Log::warning('Ignored Paynow notification that would regress local payment state', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'local_status' => $payment->status->value,
                    'external_status' => $notificationData->externalStatus,
                ]);

                return;
            }

            $this->applyStatus($payment, $order, $notificationData);

            $payment->recordNotification(
                $notificationData->externalStatus,
                $notificationData->payload,
            );
        });
    }

    private function applyStatus(
        Payment $payment,
        Order $order,
        PaymentNotificationData $notificationData,
    ): void {
        $status = $notificationData->externalStatus;

        if ($status === 'CONFIRMED') {
            $payment->markAsPaid();

            if (! $order->payment_status->isPaid()) {
                $order->markAsPaid();
            }

            return;
        }

        if (in_array($status, self::PENDING_STATUSES, true)) {
            $payment->markAsPending();

            if ($this->isLatestPaymentAttempt($payment, $order)) {
                $order->markPaymentAsPending();
            }

            return;
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $payment->markAsFailed();

            if ($this->isLatestPaymentAttempt($payment, $order)) {
                $order->markPaymentAsUnpaid();
            }

            return;
        }

        throw new RuntimeException('Unsupported payment notification status.');
    }

    private function isDuplicateOrStale(
        Payment $payment,
        PaymentNotificationData $notificationData,
        CarbonImmutable $incomingModifiedAt,
    ): bool {
        $storedPayload = $payment->payload;

        if (! is_array($storedPayload)) {
            return false;
        }

        $storedModifiedAtValue = $storedPayload['modifiedAt'] ?? null;

        if (! is_string($storedModifiedAtValue) || trim($storedModifiedAtValue) === '') {
            return false;
        }

        try {
            $storedModifiedAt = CarbonImmutable::parse($storedModifiedAtValue);
        } catch (Throwable) {
            return false;
        }

        if ($incomingModifiedAt->lessThan($storedModifiedAt)) {
            return true;
        }

        return $incomingModifiedAt->equalTo($storedModifiedAt)
            && $payment->external_status === $notificationData->externalStatus;
    }

    private function wouldRegressLocalState(
        Payment $payment,
        PaymentNotificationData $notificationData,
    ): bool {
        $status = $notificationData->externalStatus;

        if ($payment->status->isPaid()) {
            return $status !== 'CONFIRMED';
        }

        if (in_array($payment->status, [PaymentStatus::REFUNDED, PaymentStatus::PARTIALLY_REFUNDED], true)) {
            return true;
        }

        return $payment->status->isFailed()
            && in_array($status, self::PENDING_STATUSES, true);
    }

    private function isLatestPaymentAttempt(Payment $payment, Order $order): bool
    {
        return (int) $order->payments()->max('id') === $payment->id;
    }

    private function parseModifiedAt(PaymentNotificationData $notificationData): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $notificationData->modifiedAt);
        } catch (Throwable $exception) {
            throw new RuntimeException('Payment notification modifiedAt is invalid.', 0, $exception);
        }
    }
}
