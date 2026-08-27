<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WithdrawalRequest extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'number',
        'status',
        'customer_name',
        'customer_email',
        'order_number_snapshot',
        'reason',
        'customer_note',
        'refund_note',
        'submitted_at',
        'acknowledged_at',
        'refunded_at',
        'submission_ip',
        'submission_user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'refunded_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WithdrawalRequestItem::class);
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isRefundable(): bool
    {
        return ! $this->status->isFinal();
    }

    public function refundAmount(): int
    {
        return (int) $this->items->sum('line_gross_amount');
    }

    public function refundAmountDecimal(): string
    {
        return number_format($this->refundAmount() / 100, 2, '.', '');
    }

    public function acknowledge(): void
    {
        $this->update([
            'status' => WithdrawalStatus::ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);
    }

    public function markRefundPending(PaymentRefund $refund): void
    {
        $meta = $this->meta ?? [];
        $meta['refund_pending'] = [
            'payment_refund_id' => $refund->id,
            'amount' => $this->refundAmount(),
            'requested_at' => now()->toISOString(),
        ];

        $this->update([
            'status' => WithdrawalStatus::REFUND_PENDING,
            'meta' => $meta,
        ]);
    }

    public function restoreStatusAfterFailedRefund(WithdrawalStatus $status, PaymentRefund $refund): void
    {
        $meta = $this->meta ?? [];
        $meta['refund_failure'] = [
            'payment_refund_id' => $refund->id,
            'provider_refund_id' => $refund->provider_refund_id,
            'status' => $refund->status->value,
            'failure_reason' => $refund->failure_reason,
            'recorded_at' => now()->toISOString(),
        ];
        unset($meta['refund_pending']);

        $this->update([
            'status' => $status,
            'meta' => $meta,
        ]);
    }

    public function markAsRefunded(?PaymentRefund $refund = null): void
    {
        $meta = $this->meta ?? [];

        $meta['refund'] = [
            'amount' => $this->refundAmount(),
            'processed_at' => now()->toISOString(),
            'payment_refund_id' => $refund?->id,
            'provider_refund_id' => $refund?->provider_refund_id,
        ];
        unset($meta['refund_pending']);

        $this->update([
            'status' => WithdrawalStatus::REFUNDED,
            'refunded_at' => now(),
            'meta' => $meta,
        ]);
    }
}
