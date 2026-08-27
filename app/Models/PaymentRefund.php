<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = [
        'order_id',
        'payment_id',
        'provider',
        'provider_refund_id',
        'status',
        'amount',
        'currency',
        'reason',
        'idempotency_key',
        'withdrawal_request_ids',
        'withdrawal_statuses',
        'failure_reason',
        'payload',
        'last_checked_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentRefundStatus::class,
            'amount' => 'integer',
            'withdrawal_request_ids' => 'array',
            'withdrawal_statuses' => 'array',
            'payload' => 'array',
            'last_checked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requiresReconciliation(): bool
    {
        return $this->status->isOpen()
            || ($this->status->isSuccessful() && $this->completed_at === null);
    }

    public function isCompleted(): bool
    {
        return $this->status->isSuccessful() && $this->completed_at !== null;
    }
}
