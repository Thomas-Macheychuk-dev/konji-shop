<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'provider_reference',
        'status',
        'amount',
        'currency',
        'paid_at',
        'payload',
        'external_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }

    public function isUnpaid(): bool
    {
        return $this->status === PaymentStatus::UNPAID;
    }

    public function markAsPaid(): void
    {
        $this->update([
            'status' => PaymentStatus::PAID,
            'paid_at' => now(),
        ]);

        $this->order->events()->create([
            'type' => 'payment_paid',
            'description' => 'Payment marked as paid.',
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => PaymentStatus::FAILED,
        ]);

        $this->order->events()->create([
            'type' => 'payment_failed',
            'description' => 'Payment failed.',
        ]);
    }


    public function markAsRefunded(int $refundAmount, bool $fullyRefunded): void
    {
        $payload = $this->payload ?? [];
        $payload['refunds'][] = [
            'amount' => $refundAmount,
            'fully_refunded' => $fullyRefunded,
            'processed_at' => now()->toISOString(),
            'source' => 'admin_withdrawal_refund',
        ];

        $this->update([
            'status' => $fullyRefunded
                ? PaymentStatus::REFUNDED
                : PaymentStatus::PARTIALLY_REFUNDED,
            'payload' => $payload,
        ]);

        $this->order->events()->create([
            'type' => $fullyRefunded ? 'payment_refunded' : 'payment_partially_refunded',
            'description' => $fullyRefunded
                ? 'Payment marked as refunded.'
                : 'Payment marked as partially refunded.',
            'meta' => [
                'payment_id' => $this->id,
                'refund_amount' => $refundAmount,
            ],
        ]);
    }

    public function amountDecimal(): string
    {
        return number_format($this->amount / 100, 2, '.', '');
    }

    public function markAsPending(): void
    {
        $this->update([
            'status' => PaymentStatus::PENDING,
        ]);

        $this->order->events()->create([
            'type' => 'payment_pending',
            'description' => 'Payment moved to pending.',
        ]);
    }

    public function recordProviderReference(string $providerReference): void
    {
        if ($providerReference === '' || $this->provider_reference === $providerReference) {
            return;
        }

        $this->update([
            'provider_reference' => $providerReference,
        ]);
    }

    public function recordNotification(?string $externalStatus, array $payload): void
    {
        $this->update([
            'external_status' => $externalStatus,
            'payload' => $payload,
        ]);

        $this->order->events()->create([
            'type' => 'payment_notification_received',
            'description' => 'Payment notification received from provider.',
            'meta' => [
                'external_status' => $externalStatus,
            ],
        ]);
    }
}
