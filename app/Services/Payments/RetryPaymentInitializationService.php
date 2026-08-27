<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\PaymentInitializationResult;
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

            /** @var Payment|null $payment */
            $payment = $lockedOrder->payments()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $payment || ! $payment->isUnpaid()) {
                throw new RuntimeException('No unpaid payment attempt is available for retry.');
            }

            if ($payment->provider_reference !== null || $payment->paid_at !== null) {
                throw new RuntimeException('The payment attempt has already been initialized.');
            }

            if ($payment->amount !== $lockedOrder->total_amount || $payment->currency !== $lockedOrder->currency) {
                throw new RuntimeException('The payment attempt no longer matches the order total.');
            }

            $lockedOrder->events()->create([
                'type' => 'payment_initialization_retry_requested',
                'description' => 'Ponowiono próbę rozpoczęcia płatności.',
                'meta' => [
                    'payment_id' => $payment->id,
                ],
            ]);

            return [$lockedOrder, $payment];
        });

        return $this->startPaymentService->start($lockedOrder, $payment, $provider);
    }
}
