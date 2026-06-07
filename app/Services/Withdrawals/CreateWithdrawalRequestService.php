<?php

declare(strict_types=1);

namespace App\Services\Withdrawals;

use App\Enums\WithdrawalStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WithdrawalRequest;
use DomainException;
use Illuminate\Support\Facades\DB;
use Random\RandomException;

final class CreateWithdrawalRequestService
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws RandomException
     */
    public function create(Order $order, array $data, ?User $user = null): WithdrawalRequest
    {
        if ($order->placed_at === null) {
            throw new DomainException('Odstąpienie można złożyć tylko dla złożonych zamówień.');
        }

        if ($order->status->isCancelled()) {
            throw new DomainException('Dla anulowanych zamówień nie można złożyć odstąpienia.');
        }

        $items = $this->normaliseRequestedItems($order, $data['items'] ?? []);

        if ($items === []) {
            throw new DomainException('Wybierz co najmniej jedną pozycję zamówienia do odstąpienia.');
        }

        return DB::transaction(function () use ($order, $data, $user, $items): WithdrawalRequest {
            /** @var WithdrawalRequest $withdrawalRequest */
            $withdrawalRequest = WithdrawalRequest::query()->create([
                'order_id' => $order->id,
                'user_id' => $user?->id,
                'number' => $this->generateNumber(),
                'status' => WithdrawalStatus::SUBMITTED,
                'customer_name' => trim((string) $data['customer_name']),
                'customer_email' => trim((string) $data['customer_email']),
                'order_number_snapshot' => $order->number,
                'reason' => $this->nullableString($data['reason'] ?? null),
                'customer_note' => $this->nullableString($data['customer_note'] ?? null),
                'refund_note' => $this->nullableString($data['refund_note'] ?? null),
                'submitted_at' => now(),
                'submission_ip' => $this->nullableString($data['submission_ip'] ?? null),
                'submission_user_agent' => $this->nullableString($data['submission_user_agent'] ?? null),
                'meta' => [
                    'statement_confirmed' => (bool) ($data['statement_confirmed'] ?? false),
                    'source' => $data['source'] ?? null,
                ],
            ]);

            foreach ($items as $item) {
                /** @var OrderItem $orderItem */
                $orderItem = $item['order_item'];
                $quantityRequested = $item['quantity_requested'];
                $unitGrossAmount = $this->unitGrossAmount($orderItem);
                $lineGrossAmount = $unitGrossAmount * $quantityRequested;

                $withdrawalRequest->items()->create([
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'product_name_snapshot' => $orderItem->product_name_snapshot,
                    'variant_name_snapshot' => $orderItem->variant_name_snapshot,
                    'sku_snapshot' => $orderItem->sku_snapshot,
                    'quantity_ordered' => $orderItem->quantity,
                    'quantity_requested' => $quantityRequested,
                    'unit_gross_amount' => $unitGrossAmount,
                    'line_gross_amount' => $lineGrossAmount,
                    'meta' => [
                        'order_item_line_gross_amount' => $orderItem->line_gross_amount ?: $orderItem->line_total_amount,
                        'order_item_vat_rate_snapshot' => $orderItem->vat_rate_snapshot,
                    ],
                ]);
            }

            $order->events()->create([
                'type' => 'withdrawal_request_submitted',
                'description' => 'Klient złożył oświadczenie o odstąpieniu od umowy.',
                'meta' => [
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'withdrawal_request_number' => $withdrawalRequest->number,
                    'customer_email' => $withdrawalRequest->customer_email,
                ],
            ]);

            return $withdrawalRequest->load(['items', 'order']);
        });
    }

    /**
     * @param mixed $requestedItems
     * @return list<array{order_item: OrderItem, quantity_requested: int}>
     */
    private function normaliseRequestedItems(Order $order, mixed $requestedItems): array
    {
        if (! is_array($requestedItems)) {
            return [];
        }

        $order->loadMissing('items.withdrawalRequestItems.withdrawalRequest');

        $normalised = [];

        foreach ($requestedItems as $orderItemId => $quantity) {
            $quantityRequested = (int) $quantity;

            if ($quantityRequested < 1) {
                continue;
            }

            /** @var OrderItem|null $orderItem */
            $orderItem = $order->items->firstWhere('id', (int) $orderItemId);

            if ($orderItem === null) {
                throw new DomainException('Wybrana pozycja nie należy do tego zamówienia.');
            }

            $remainingQuantity = $this->remainingWithdrawableQuantity($orderItem);

            if ($quantityRequested > $remainingQuantity) {
                throw new DomainException(
                    "Requested withdrawal quantity for {$orderItem->product_name_snapshot} exceeds the remaining withdrawable quantity."
                );
            }

            $normalised[] = [
                'order_item' => $orderItem,
                'quantity_requested' => $quantityRequested,
            ];
        }

        return $normalised;
    }

    private function remainingWithdrawableQuantity(OrderItem $orderItem): int
    {
        $alreadyRequested = $orderItem
            ->withdrawalRequestItems
            ->filter(fn ($withdrawalItem): bool => ! $withdrawalItem->withdrawalRequest->isFinal())
            ->sum('quantity_requested');

        return max(0, (int) $orderItem->quantity - (int) $alreadyRequested);
    }

    private function unitGrossAmount(OrderItem $orderItem): int
    {
        if ((int) $orderItem->unit_gross_amount > 0) {
            return (int) $orderItem->unit_gross_amount;
        }

        if ((int) $orderItem->unit_price_amount > 0) {
            return (int) $orderItem->unit_price_amount;
        }

        if ((int) $orderItem->quantity > 0 && (int) $orderItem->line_gross_amount > 0) {
            return (int) floor($orderItem->line_gross_amount / $orderItem->quantity);
        }

        if ((int) $orderItem->quantity > 0 && (int) $orderItem->line_total_amount > 0) {
            return (int) floor($orderItem->line_total_amount / $orderItem->quantity);
        }

        return 0;
    }

    /**
     * @throws RandomException
     */
    private function generateNumber(): string
    {
        do {
            $number = 'WD-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
        } while (WithdrawalRequest::query()->where('number', $number)->exists());

        return $number;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
