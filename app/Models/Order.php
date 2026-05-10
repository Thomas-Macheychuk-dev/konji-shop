<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use DomainException;
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
        'description',
        'meta',
        'type',
        'delivery_provider',
        'delivery_service',
        'delivery_locker_code',
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
            'delivery_provider' => DeliveryProvider::class,
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

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->latest();
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'shipping');
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'billing');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
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

    public function markAsPaid(): void
    {
        $this->update([
            'payment_status' => PaymentStatus::PAID,
        ]);

        $this->confirm();
    }

    public function confirm(): void
    {
        if (! $this->payment_status->isPaid()) {
            throw new DomainException('Cannot confirm an order that has not been paid.');
        }

        if ($this->status->isCancelled()) {
            throw new DomainException('Cannot confirm a cancelled order.');
        }

        $this->update([
            'status' => OrderStatus::CONFIRMED,
        ]);

        $this->recordEvent(
            'order_confirmed',
            'Order confirmed after successful payment.'
        );
    }

    public function complete(): void
    {
        if (! $this->status->isConfirmed()) {
            throw new DomainException('Only confirmed orders can be completed.');
        }

        if (! $this->fulfilment_status->isDelivered()) {
            throw new DomainException('Only delivered orders can be completed.');
        }

        $this->update([
            'status' => OrderStatus::COMPLETED,
        ]);

        $this->recordEvent(
            'order_completed',
            'Order marked as completed.'
        );
    }

    public function cancel(?string $note = null): void
    {
        if (! $this->canBeCancelled()) {
            throw new DomainException('This order can no longer be cancelled.');
        }

        $existingNotes = trim((string) $this->notes);
        $trimmedNote = $note !== null ? trim($note) : '';

        $this->update([
            'status' => OrderStatus::CANCELLED,
            'notes' => $trimmedNote !== ''
                ? ($existingNotes !== '' ? $existingNotes.PHP_EOL.$trimmedNote : $trimmedNote)
                : $this->notes,
        ]);

        $this->recordEvent(
            'order_cancelled',
            $trimmedNote !== '' ? $trimmedNote : 'Order cancelled by customer.'
        );
    }

    public function markFulfilmentAsProcessing(): void
    {
        if (! $this->status->isConfirmed()) {
            throw new DomainException('Only confirmed orders can enter fulfilment processing.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::PROCESSING,
        ]);

        $this->recordEvent(
            'fulfilment_processing_started',
            'Order fulfilment processing started.'
        );
    }

    public function markAsShipped(): void
    {
        if (! $this->payment_status->isPaid()) {
            throw new DomainException('Cannot ship an unpaid order.');
        }

        if (! $this->status->isConfirmed()) {
            throw new DomainException('Only confirmed orders can be shipped.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::SHIPPED,
        ]);

        $this->recordEvent(
            'order_shipped',
            'Order shipped to customer.'
        );
    }

    public function markAsDelivered(): void
    {
        if (! $this->fulfilment_status->isShipped()) {
            throw new DomainException('Only shipped orders can be marked as delivered.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::DELIVERED,
        ]);

        $this->recordEvent(
            'order_delivered',
            'Order delivered to customer.'
        );
    }

    public function markAsReturned(): void
    {
        if (! $this->fulfilment_status->isDelivered()) {
            throw new DomainException('Only delivered orders can be marked as returned.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::RETURNED,
        ]);

        $this->recordEvent(
            'order_returned',
            'Order returned by customer.'
        );
    }

    public function markPaymentAsPending(): void
    {
        if (! $this->payment_status->isUnpaid()) {
            throw new DomainException('Only unpaid orders can move to pending payment.');
        }

        $this->update([
            'payment_status' => PaymentStatus::PENDING,
        ]);
    }

    private function recordEvent(string $type, string $description, array $meta = []): void
    {
        $this->events()->create([
            'type' => $type,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    public function appendNote(string $note): void
    {
        $existingNotes = trim((string) $this->notes);
        $formattedNote = '['.now()->format('Y-m-d H:i:s').'] '.$note;

        $this->update([
            'notes' => $existingNotes !== ''
                ? $existingNotes.PHP_EOL.PHP_EOL.$formattedNote
                : $formattedNote,
        ]);

        $this->recordEvent(
            'note_added',
            'Internal note added.'
        );
    }

    public function cancelByAdmin(string $note): void
    {
        if ($this->status->isCancelled()) {
            throw new DomainException('This order is already cancelled.');
        }

        $this->update([
            'status' => OrderStatus::CANCELLED,
        ]);

        $this->appendNote($note);

        $this->recordEvent(
            'order_cancelled_by_admin',
            'Order cancelled by admin.'
        );
    }

    public function chooseDelivery(
        DeliveryProvider $provider,
        string $service,
        ?string $lockerCode = null,
    ): void {
        $this->update([
            'delivery_provider' => $provider,
            'delivery_service' => $service,
            'delivery_locker_code' => $lockerCode,
        ]);

        $this->recordEvent(
            'delivery_choice_selected',
            'Delivery method selected.',
            [
                'provider' => $provider->value,
                'service' => $service,
                'locker_code' => $lockerCode,
            ],
        );
    }
}
