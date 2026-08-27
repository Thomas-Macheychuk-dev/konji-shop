<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\PaymentInitializationResult;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RetryPaymentInitializationService
{
    public function __construct(
        private readonly StartPaymentService $startPaymentService,
    ) {}

    public function retry(Order $order, string $provider): PaymentInitializationResult
    {
        [$lockedOrder, $payment] = DB::transaction(function () use ($order): array {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                throw new RuntimeException('Order not found.');
            }

            if (! $lockedOrder->canRetryPaymentInitialization()) {
                throw new RuntimeException('This order is not eligible for payment retry.');
            }

            /** @var Payment|null $latestPayment */
            $latestPayment = $lockedOrder->payments()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $latestPayment) {
                throw new RuntimeException('No payment attempt is available for retry.');
            }

            if ($latestPayment->amount !== $lockedOrder->total_amount || $latestPayment->currency !== $lockedOrder->currency) {
                throw new RuntimeException('The payment attempt no longer matches the order total.');
            }

            $payment = $this->paymentForRetry($lockedOrder, $latestPayment);

            $lockedOrder->events()->create([
                'type' => 'payment_initialization_retry_requested',
                'description' => 'Ponowiono próbę rozpoczęcia płatności.',
                'meta' => [
                    'payment_id' => $payment->id,
                    'previous_payment_id' => $latestPayment->id !== $payment->id ? $latestPayment->id : null,
                ],
            ]);

            return [$lockedOrder, $payment];
        });

        return $this->startPaymentService->start($lockedOrder, $payment, $provider);
    }

    private function paymentForRetry(Order $order, Payment $latestPayment): Payment
    {
        if (
            $latestPayment->isUnpaid()
            && $latestPayment->provider_reference === null
            && $latestPayment->paid_at === null
        ) {
            return $latestPayment;
        }

        if (! $latestPayment->status->isFailed()) {
            throw new RuntimeException('No retryable payment attempt is available.');
        }

        return $order->payments()->create([
            'provider' => null,
            'provider_reference' => null,
            'status' => PaymentStatus::UNPAID,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'paid_at' => null,
            'payload' => null,
            'external_status' => null,
        ]);
    }
}
