<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'number',
        'guest_email',
        'status',
        'currency',
        'subtotal_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
        'payment_status',
        'fulfilment_status',
        'notes',
        'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'placed_at' => 'datetime',
            'subtotal_amount' => 'integer',
            'shipping_amount' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'shipping');
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'billing');
    }

    public function isDraft(): bool
    {
        return $this->status === OrderStatus::DRAFT;
    }

    public function isPlaced(): bool
    {
        return $this->placed_at !== null;
    }

    public function canBeCancelled(): bool
    {
        return $this->isPlaced()
            && ! $this->status->isCancelled()
            && $this->status->isPendingPayment()
            && $this->payment_status->isUnpaid()
            && $this->fulfilment_status->isUnfulfilled();
    }

    public function canBeCancelledByCustomer(): bool
    {
        return $this->canBeCancelled();
    }

    public function isGuestOrder(): bool
    {
        return $this->user_id === null && $this->guest_email !== null;
    }
}
