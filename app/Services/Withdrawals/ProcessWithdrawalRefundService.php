<?php

declare(strict_types=1);

namespace App\Services\Withdrawals;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Events\WithdrawalRequestRefunded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WithdrawalRequest;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProcessWithdrawalRefundService
{
    /**
     * @return Collection<int, WithdrawalRequest>
     */
    public function process(Order $order): Collection
    {
        /** @var Collection<int, WithdrawalRequest> $refundedWithdrawalRequests */
        $refundedWithdrawalRequests = DB::transaction(function () use ($order): Collection {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->with([
                    'payments',
                    'withdrawalRequests.items',
                ])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! in_array($lockedOrder->payment_status, [
                PaymentStatus::PAID,
                PaymentStatus::PARTIALLY_REFUNDED,
            ], true)) {
                throw new DomainException('Only paid orders can be refunded.');
            }

            $withdrawalRequests = $lockedOrder->withdrawalRequests
                ->filter(fn (WithdrawalRequest $withdrawalRequest): bool => $withdrawalRequest->isRefundable())
                ->values();

            if ($withdrawalRequests->isEmpty()) {
                throw new DomainException('There are no refundable withdrawal requests for this order.');
            }

            $refundAmount = (int) $withdrawalRequests
                ->sum(fn (WithdrawalRequest $withdrawalRequest): int => $withdrawalRequest->refundAmount());

            if ($refundAmount <= 0) {
                throw new DomainException('Cannot refund a withdrawal request with zero amount.');
            }

            $withdrawalRequests->each(
                fn (WithdrawalRequest $withdrawalRequest) => $withdrawalRequest->markAsRefunded()
            );

            $lockedOrder->load('withdrawalRequests.items');

            $totalRefundedAmount = (int) $lockedOrder->withdrawalRequests
                ->filter(fn (WithdrawalRequest $withdrawalRequest): bool => $withdrawalRequest->status === WithdrawalStatus::REFUNDED)
                ->sum(fn (WithdrawalRequest $withdrawalRequest): int => $withdrawalRequest->refundAmount());

            $fullyRefunded = $totalRefundedAmount >= (int) $lockedOrder->total_amount;

            $payment = $lockedOrder->payments
                ->filter(fn (Payment $payment): bool => in_array($payment->status, [
                    PaymentStatus::PAID,
                    PaymentStatus::PARTIALLY_REFUNDED,
                ], true))
                ->sortByDesc('id')
                ->first();

            if ($payment instanceof Payment) {
                $payment->markAsRefunded($refundAmount, $fullyRefunded);
            }

            $lockedOrder->markPaymentAsRefunded($refundAmount, $fullyRefunded);

            $lockedOrder->events()->create([
                'type' => 'withdrawal_refund_processed',
                'description' => 'Admin processed withdrawal refund.',
                'meta' => [
                    'refund_amount' => $refundAmount,
                    'fully_refunded' => $fullyRefunded,
                    'withdrawal_request_ids' => $withdrawalRequests->pluck('id')->all(),
                ],
            ]);

            return WithdrawalRequest::query()
                ->with(['order', 'items'])
                ->whereKey($withdrawalRequests->pluck('id'))
                ->get();
        });

        $refundedWithdrawalRequests->each(
            fn (WithdrawalRequest $withdrawalRequest) => WithdrawalRequestRefunded::dispatch($withdrawalRequest)
        );

        return $refundedWithdrawalRequests;
    }
}
