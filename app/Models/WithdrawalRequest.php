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

    public function acknowledge(): void
    {
        $this->update([
            'status' => WithdrawalStatus::ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);
    }
}
