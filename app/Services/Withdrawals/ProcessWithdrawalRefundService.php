<?php

declare(strict_types=1);

namespace App\Services\Withdrawals;

use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Events\WithdrawalRequestRefunded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\WithdrawalRequest;
use App\Services\Payments\Paynow\PaynowRefundService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProcessWithdrawalRefundService
{
    public function __construct(
        private readonly PaynowRefundService $paynowRefundService,
    ) {}

    public function process(Order $order): PaymentRefund
    {
        $refund = DB::transaction(fn (): PaymentRefund => $this->prepareRefund($order));

        return $this->advance($refund);
    }

    public function reconcile(PaymentRefund $refund): PaymentRefund
    {
        return $this->advance($refund->fresh(['order', 'payment']) ?? $refund);
    }

    private function prepareRefund(Order $order): PaymentRefund
    {
        /** @var Order $lockedOrder */
        $lockedOrder = Order::query()
            ->with([
                'payments',
                'paymentRefunds',
                'withdrawalRequests.items',
            ])
            ->lockForUpdate()
            ->findOrFail($order->id);

        $activeRefund = $lockedOrder->paymentRefunds
            ->filter(fn (PaymentRefund $refund): bool => $refund->provider === 'paynow' && $refund->requiresReconciliation())
            ->sortBy('id')
            ->first();

        if ($activeRefund instanceof PaymentRefund) {
            return $activeRefund;
        }

        if (! in_array($lockedOrder->payment_status, [
            PaymentStatus::PAID,
            PaymentStatus::PARTIALLY_REFUNDED,
        ], true)) {
            throw new DomainException('Zwrot można wykonać tylko dla opłaconych zamówień.');
        }

        $withdrawalRequests = $lockedOrder->withdrawalRequests
            ->filter(fn (WithdrawalRequest $withdrawalRequest): bool => $withdrawalRequest->isRefundable())
            ->values();

        if ($withdrawalRequests->isEmpty()) {
            throw new DomainException('Brak zgłoszeń odstąpienia kwalifikujących się do zwrotu dla tego zamówienia.');
        }

        $refundAmount = (int) $withdrawalRequests
            ->sum(fn (WithdrawalRequest $withdrawalRequest): int => $withdrawalRequest->refundAmount());

        if ($refundAmount <= 0) {
            throw new DomainException('Nie można wykonać zwrotu dla odstąpienia o zerowej kwocie.');
        }

        $payment = $lockedOrder->payments
            ->filter(fn (Payment $payment): bool => $payment->provider === 'paynow')
            ->filter(fn (Payment $payment): bool => in_array($payment->status, [
                PaymentStatus::PAID,
                PaymentStatus::PARTIALLY_REFUNDED,
            ], true))
            ->filter(fn (Payment $payment): bool => strtoupper(trim((string) $payment->external_status)) === 'CONFIRMED')
            ->filter(fn (Payment $payment): bool => trim((string) $payment->provider_reference) !== '')
            ->sortByDesc('id')
            ->first();

        if (! $payment instanceof Payment) {
            throw new DomainException('Brak potwierdzonej płatności Paynow kwalifikującej się do zwrotu.');
        }

        $alreadyRefunded = (int) PaymentRefund::query()
            ->where('payment_id', $payment->id)
            ->where('status', PaymentRefundStatus::SUCCESSFUL->value)
            ->sum('amount');

        if (($alreadyRefunded + $refundAmount) > (int) $payment->amount) {
            throw new DomainException('Łączna kwota zwrotów przekroczyłaby wartość płatności Paynow.');
        }

        $withdrawalIds = $withdrawalRequests
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $withdrawalStatuses = $withdrawalRequests
            ->mapWithKeys(fn (WithdrawalRequest $request): array => [
                (string) $request->id => $request->status->value,
            ])
            ->all();

        $refund = PaymentRefund::query()->create([
            'order_id' => $lockedOrder->id,
            'payment_id' => $payment->id,
            'provider' => 'paynow',
            'status' => PaymentRefundStatus::REQUESTED,
            'amount' => $refundAmount,
            'currency' => $payment->currency,
            'reason' => $this->paynowReason($lockedOrder, $withdrawalRequests),
            'idempotency_key' => (string) Str::uuid(),
            'withdrawal_request_ids' => $withdrawalIds,
            'withdrawal_statuses' => $withdrawalStatuses,
        ]);

        $withdrawalRequests->each(
            fn (WithdrawalRequest $withdrawalRequest) => $withdrawalRequest->markRefundPending($refund)
        );

        $lockedOrder->events()->create([
            'type' => 'withdrawal_refund_requested',
            'description' => 'Administrator zlecił zwrot środków przez Paynow.',
            'meta' => [
                'payment_refund_id' => $refund->id,
                'payment_id' => $payment->id,
                'refund_amount' => $refundAmount,
                'withdrawal_request_ids' => $withdrawalIds,
            ],
        ]);

        return $refund->load(['order', 'payment']);
    }

    private function advance(PaymentRefund $refund): PaymentRefund
    {
        if ($refund->isCompleted()) {
            return $refund;
        }

        if ($refund->status->isSuccessful()) {
            return $this->finalizeSuccessfulRefund($refund);
        }

        if ($refund->status->isTerminalFailure()) {
            throw new DomainException('Zwrot Paynow zakończył się niepowodzeniem. Można zlecić nową próbę zwrotu.');
        }

        $payment = $refund->payment;

        if (! $payment instanceof Payment) {
            throw new RuntimeException('Nie znaleziono płatności dla zwrotu Paynow.');
        }

        $result = $refund->status === PaymentRefundStatus::REQUESTED
            ? $this->paynowRefundService->create($payment, $refund)
            : $this->paynowRefundService->status($refund);

        $previousStatus = $refund->status;
        $previousProviderRefundId = $refund->provider_refund_id;

        $refund->update([
            'provider_refund_id' => $result->providerRefundId,
            'status' => $result->status,
            'failure_reason' => $result->failureReason,
            'payload' => $result->payload,
            'last_checked_at' => now(),
        ]);

        $refund->refresh();

        if (
            $previousStatus !== $refund->status
            || $previousProviderRefundId !== $refund->provider_refund_id
        ) {
            $refund->order->events()->create([
                'type' => 'withdrawal_refund_provider_status',
                'description' => 'Zaktualizowano status zwrotu Paynow.',
                'meta' => [
                    'payment_refund_id' => $refund->id,
                    'provider_refund_id' => $refund->provider_refund_id,
                    'status' => $refund->status->value,
                ],
            ]);
        }

        if ($refund->status->isSuccessful()) {
            return $this->finalizeSuccessfulRefund($refund);
        }

        if ($refund->status->isTerminalFailure()) {
            $this->restoreWithdrawalsAfterFailure($refund);

            throw new DomainException(
                $refund->status === PaymentRefundStatus::CANCELLED
                    ? 'Zwrot Paynow został anulowany przed przekazaniem środków klientowi.'
                    : 'Zwrot Paynow nie powiódł się. Środki nie zostały oznaczone jako zwrócone.',
            );
        }

        return $refund;
    }

    private function finalizeSuccessfulRefund(PaymentRefund $refund): PaymentRefund
    {
        /** @var Collection<int, WithdrawalRequest> $refundedWithdrawalRequests */
        $refundedWithdrawalRequests = DB::transaction(function () use ($refund): Collection {
            /** @var PaymentRefund $lockedRefund */
            $lockedRefund = PaymentRefund::query()
                ->with(['order', 'payment'])
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if ($lockedRefund->completed_at !== null) {
                return collect();
            }

            if (! $lockedRefund->status->isSuccessful()) {
                throw new DomainException('Zwrot nie został jeszcze potwierdzony jako wykonany przez Paynow.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($lockedRefund->order_id);

            /** @var Payment $payment */
            $payment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($lockedRefund->payment_id);

            $withdrawalIds = collect($lockedRefund->withdrawal_request_ids ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->values();

            /** @var Collection<int, WithdrawalRequest> $withdrawalRequests */
            $withdrawalRequests = WithdrawalRequest::query()
                ->with(['order', 'items'])
                ->where('order_id', $lockedOrder->id)
                ->whereKey($withdrawalIds->all())
                ->lockForUpdate()
                ->get();

            if ($withdrawalRequests->count() !== $withdrawalIds->count()) {
                throw new DomainException('Nie udało się odtworzyć pełnego zakresu zwrotu z odstąpienia.');
            }

            $withdrawalRequests->each(
                fn (WithdrawalRequest $withdrawalRequest) => $withdrawalRequest->markAsRefunded($lockedRefund)
            );

            $totalRefundedAmount = (int) PaymentRefund::query()
                ->where('payment_id', $payment->id)
                ->where('status', PaymentRefundStatus::SUCCESSFUL->value)
                ->sum('amount');

            $fullyRefunded = $totalRefundedAmount >= (int) $payment->amount;

            $payment->setRelation('order', $lockedOrder);
            $payment->markAsRefunded((int) $lockedRefund->amount, $fullyRefunded);
            $lockedOrder->markPaymentAsRefunded((int) $lockedRefund->amount, $fullyRefunded);

            $lockedRefund->update([
                'completed_at' => now(),
                'last_checked_at' => now(),
            ]);

            $lockedOrder->events()->create([
                'type' => 'withdrawal_refund_processed',
                'description' => 'Paynow potwierdził wykonanie zwrotu z odstąpienia.',
                'meta' => [
                    'payment_refund_id' => $lockedRefund->id,
                    'provider_refund_id' => $lockedRefund->provider_refund_id,
                    'refund_amount' => (int) $lockedRefund->amount,
                    'fully_refunded' => $fullyRefunded,
                    'withdrawal_request_ids' => $withdrawalIds->all(),
                ],
            ]);

            return $withdrawalRequests;
        });

        $refundedWithdrawalRequests->each(
            fn (WithdrawalRequest $withdrawalRequest) => WithdrawalRequestRefunded::dispatch($withdrawalRequest)
        );

        return $refund->fresh(['order', 'payment']) ?? $refund;
    }

    private function restoreWithdrawalsAfterFailure(PaymentRefund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            /** @var PaymentRefund $lockedRefund */
            $lockedRefund = PaymentRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            $withdrawalStatuses = is_array($lockedRefund->withdrawal_statuses)
                ? $lockedRefund->withdrawal_statuses
                : [];

            $withdrawalIds = collect($lockedRefund->withdrawal_request_ids ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->values();

            WithdrawalRequest::query()
                ->where('order_id', $lockedRefund->order_id)
                ->whereKey($withdrawalIds->all())
                ->lockForUpdate()
                ->get()
                ->each(function (WithdrawalRequest $request) use ($withdrawalStatuses, $lockedRefund): void {
                    $previousStatus = WithdrawalStatus::tryFrom(
                        (string) ($withdrawalStatuses[(string) $request->id] ?? '')
                    ) ?? WithdrawalStatus::ACKNOWLEDGED;

                    $request->restoreStatusAfterFailedRefund($previousStatus, $lockedRefund);
                });

            $lockedRefund->order->events()->create([
                'type' => 'withdrawal_refund_failed',
                'description' => 'Paynow nie przekazał środków klientowi; odstąpienie wróciło do obsługi.',
                'meta' => [
                    'payment_refund_id' => $lockedRefund->id,
                    'provider_refund_id' => $lockedRefund->provider_refund_id,
                    'status' => $lockedRefund->status->value,
                    'failure_reason' => $lockedRefund->failure_reason,
                ],
            ]);
        });
    }

    /**
     * @param  Collection<int, WithdrawalRequest>  $withdrawalRequests
     */
    private function paynowReason(Order $order, Collection $withdrawalRequests): string
    {
        if ($order->placed_at === null) {
            return 'OTHER';
        }

        $deadline = $order->placed_at->copy()->addDays(14);
        $allSubmittedWithinFourteenDays = $withdrawalRequests->every(
            fn (WithdrawalRequest $request): bool => $request->submitted_at !== null
                && $request->submitted_at->lessThanOrEqualTo($deadline)
        );

        return $allSubmittedWithinFourteenDays
            ? 'REFUND_BEFORE_14'
            : 'REFUND_AFTER_14';
    }
}
