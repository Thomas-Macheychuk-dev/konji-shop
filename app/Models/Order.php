<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryCarrier;
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
        'items_net_amount',
        'items_tax_amount',
        'items_gross_amount',

        'shipping_amount',
        'shipping_net_amount',
        'shipping_tax_amount',
        'shipping_gross_amount',

        'discount_amount',
        'tax_amount',
        'total_amount',

        'payment_status',
        'fulfilment_status',
        'notes',
        'placed_at',

        'terms_accepted_at',
        'terms_version',
        'privacy_version',
        'returns_policy_version',
        'legal_acceptance_ip',
        'legal_acceptance_user_agent',

        'description',
        'meta',
        'type',
        'delivery_provider',
        'delivery_service',
        'delivery_locker_code',
        'delivery_carrier',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'placed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',

            'subtotal_amount' => 'integer',
            'items_net_amount' => 'integer',
            'items_tax_amount' => 'integer',
            'items_gross_amount' => 'integer',

            'shipping_amount' => 'integer',
            'shipping_net_amount' => 'integer',
            'shipping_tax_amount' => 'integer',
            'shipping_gross_amount' => 'integer',

            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',

            'meta' => 'array',
            'delivery_provider' => DeliveryProvider::class,
            'delivery_carrier' => DeliveryCarrier::class,
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

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function subtotalDecimal(): string
    {
        return $this->formatAmount($this->subtotal_amount);
    }

    public function itemsNetDecimal(): string
    {
        return $this->formatAmount($this->items_net_amount);
    }

    public function itemsTaxDecimal(): string
    {
        return $this->formatAmount($this->items_tax_amount);
    }

    public function itemsGrossDecimal(): string
    {
        return $this->formatAmount($this->items_gross_amount ?: $this->subtotal_amount);
    }

    public function shippingDecimal(): string
    {
        return $this->formatAmount($this->shipping_amount);
    }

    public function shippingNetDecimal(): string
    {
        return $this->formatAmount($this->shipping_net_amount);
    }

    public function shippingTaxDecimal(): string
    {
        return $this->formatAmount($this->shipping_tax_amount);
    }

    public function shippingGrossDecimal(): string
    {
        return $this->formatAmount($this->shipping_gross_amount ?: $this->shipping_amount);
    }

    public function discountDecimal(): string
    {
        return $this->formatAmount($this->discount_amount);
    }

    public function taxDecimal(): string
    {
        return $this->formatAmount($this->tax_amount);
    }

    public function totalDecimal(): string
    {
        return $this->formatAmount($this->total_amount);
    }

    public function hasTaxBreakdown(): bool
    {
        return $this->items_net_amount > 0
            || $this->items_tax_amount > 0
            || $this->shipping_net_amount > 0
            || $this->shipping_tax_amount > 0
            || $this->tax_amount > 0;
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
            throw new DomainException('Nie można potwierdzić nieopłaconego zamówienia.');
        }

        if ($this->status->isCancelled()) {
            throw new DomainException('Nie można potwierdzić anulowanego zamówienia.');
        }

        $this->update([
            'status' => OrderStatus::CONFIRMED,
        ]);

        $this->recordEvent(
            'order_confirmed',
            'Zamówienie potwierdzone po pomyślnej płatności.'
        );
    }

    public function complete(): void
    {
        if (! $this->status->isConfirmed()) {
            throw new DomainException('Tylko potwierdzone zamówienia mogą zostać zakończone.');
        }

        if (! $this->fulfilment_status->isDelivered()) {
            throw new DomainException('Tylko dostarczone zamówienia mogą zostać zakończone.');
        }

        $this->update([
            'status' => OrderStatus::COMPLETED,
        ]);

        $this->recordEvent(
            'order_completed',
            'Zamówienie oznaczono jako zakończone.'
        );
    }

    public function cancel(?string $note = null): void
    {
        if (! $this->canBeCancelled()) {
            throw new DomainException('Tego zamówienia nie można już anulować.');
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
            throw new DomainException('Tylko potwierdzone zamówienia mogą przejść do realizacji.');
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
            throw new DomainException('Nie można wysłać nieopłaconego zamówienia.');
        }

        if (! $this->status->isConfirmed()) {
            throw new DomainException('Tylko potwierdzone zamówienia mogą zostać wysłane.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::SHIPPED,
        ]);

        $this->recordEvent(
            'order_shipped',
            'Zamówienie wysłane do klienta.'
        );
    }

    public function markAsDelivered(): void
    {
        if (! $this->fulfilment_status->isShipped()) {
            throw new DomainException('Tylko wysłane zamówienia można oznaczyć jako dostarczone.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::DELIVERED,
        ]);

        $this->recordEvent(
            'order_delivered',
            'Zamówienie dostarczone do klienta.'
        );
    }

    public function markAsReturned(): void
    {
        if (! $this->fulfilment_status->isDelivered()) {
            throw new DomainException('Tylko dostarczone zamówienia można oznaczyć jako zwrócone.');
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
            throw new DomainException('Tylko nieopłacone zamówienia mogą oczekiwać na płatność.');
        }

        $this->update([
            'payment_status' => PaymentStatus::PENDING,
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
        if (! $this->canBeCancelledByAdmin()) {
            throw new DomainException('Tego zamówienia nie można już anulować.');
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
        DeliveryCarrier $carrier,
        string $service,
        ?string $lockerCode = null,
    ): void {
        $this->update([
            'delivery_provider' => $provider,
            'delivery_carrier' => $carrier,
            'delivery_service' => $service,
            'delivery_locker_code' => $lockerCode,
        ]);

        $this->recordEvent(
            'delivery_choice_selected',
            'Wybrano metodę dostawy.',
            [
                'provider' => $provider->value,
                'carrier' => $carrier->value,
                'service' => $service,
                'locker_code' => $lockerCode,
            ],
        );
    }

    public function markAsReadyForPickup(): void
    {
        if (! $this->payment_status->isPaid()) {
            throw new DomainException('Tylko opłacone zamówienia można oznaczyć jako gotowe do odbioru.');
        }

        if (! $this->status->isConfirmed()) {
            throw new DomainException('Tylko potwierdzone zamówienia można oznaczyć jako gotowe do odbioru.');
        }

        if ($this->fulfilment_status === FulfilmentStatus::READY_FOR_PICKUP) {
            return;
        }

        if (! in_array($this->fulfilment_status, [
            FulfilmentStatus::UNFULFILLED,
            FulfilmentStatus::PROCESSING,
        ], true)) {
            throw new DomainException('Tylko niezrealizowane lub realizowane zamówienia można oznaczyć jako gotowe do odbioru.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::READY_FOR_PICKUP,
        ]);

        $this->recordEvent(
            'order_ready_for_pickup',
            'Order marked as ready for pickup.'
        );
    }

    public function canBeCancelledByAdmin(): bool
    {
        if ($this->status === OrderStatus::CANCELLED) {
            return false;
        }

        if ($this->status === OrderStatus::COMPLETED) {
            return false;
        }

        if (in_array($this->fulfilment_status, [
            FulfilmentStatus::SHIPPED,
            FulfilmentStatus::DELIVERED,
            FulfilmentStatus::RETURNED,
        ], true)) {
            return false;
        }

        return true;
    }

    public function markAsPickedUp(): void
    {
        if (! $this->payment_status->isPaid()) {
            throw new DomainException('Tylko opłacone zamówienia można oznaczyć jako odebrane.');
        }

        if (! $this->status->isConfirmed()) {
            throw new DomainException('Tylko potwierdzone zamówienia można oznaczyć jako odebrane.');
        }

        if ($this->delivery_service !== 'local_pickup') {
            throw new DomainException('Tylko zamówienia z odbiorem osobistym można oznaczyć jako odebrane.');
        }

        if ($this->fulfilment_status !== FulfilmentStatus::READY_FOR_PICKUP) {
            throw new DomainException('Tylko zamówienia gotowe do odbioru można oznaczyć jako odebrane.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::DELIVERED,
        ]);

        $this->recordEvent(
            'order_picked_up',
            'Order picked up by customer.'
        );
    }

    public function markAsReturnedToSender(?string $note = null): void
    {
        if (! $this->status->isConfirmed()) {
            throw new DomainException('Tylko potwierdzone zamówienia można oznaczyć jako zwrócone do nadawcy.');
        }

        if (! $this->fulfilment_status->isShipped()) {
            throw new DomainException('Tylko wysłane zamówienia można oznaczyć jako zwrócone do nadawcy.');
        }

        $this->update([
            'fulfilment_status' => FulfilmentStatus::RETURNED,
        ]);

        $this->recordEvent(
            'order_returned_to_sender',
            'Order returned to sender.',
            $note !== null && trim($note) !== ''
                ? ['note' => trim($note)]
                : []
        );
    }


    public function markPaymentAsRefunded(int $refundAmount, bool $fullyRefunded): void
    {
        if (! in_array($this->payment_status, [
            PaymentStatus::PAID,
            PaymentStatus::PARTIALLY_REFUNDED,
        ], true)) {
            throw new DomainException('Zwrot można wykonać tylko dla opłaconych zamówień.');
        }

        $this->update([
            'payment_status' => $fullyRefunded
                ? PaymentStatus::REFUNDED
                : PaymentStatus::PARTIALLY_REFUNDED,
        ]);

        $this->recordEvent(
            $fullyRefunded ? 'order_refunded' : 'order_partially_refunded',
            $fullyRefunded
                ? 'Order marked as refunded after withdrawal.'
                : 'Order marked as partially refunded after withdrawal.',
            [
                'refund_amount' => $refundAmount,
            ],
        );
    }

    private function recordEvent(string $type, string $description, array $meta = []): void
    {
        $this->events()->create([
            'type' => $type,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function formatAmount(?int $amount): string
    {
        return number_format(((int) $amount) / 100, 2, '.', '');
    }
}
