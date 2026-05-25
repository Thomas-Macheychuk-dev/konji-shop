<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\DeliveryCarrier;

class Shipment extends Model
{
    protected $fillable = [
        'order_id',
        'provider',
        'provider_reference',
        'status',
        'tracking_number',
        'tracking_url',
        'service',
        'locker_code',
        'payload',
        'shipped_at',
        'delivered_at',
        'label_disk',
        'label_path',
        'label_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => DeliveryProvider::class,
            'status' => ShipmentStatus::class,
            'payload' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'label_downloaded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function carrier(): ?DeliveryCarrier
    {
        return $this->order?->delivery_carrier;
    }

    public function markAsCreated(?string $providerReference = null, array $payload = []): void
    {
        $this->update([
            'status' => ShipmentStatus::CREATED,
            'provider_reference' => $providerReference ?? $this->provider_reference,
            'payload' => $payload === [] ? $this->payload : $payload,
        ]);

        $this->order->events()->create([
            'type' => 'shipment_created',
            'description' => 'Shipment created.',
            'meta' => [
                'provider' => $this->provider->value,
                'provider_reference' => $this->provider_reference,
            ],
        ]);
    }

    public function markAsDispatched(?string $trackingNumber = null, ?string $trackingUrl = null): void
    {
        $this->update([
            'status' => ShipmentStatus::DISPATCHED,
            'tracking_number' => $trackingNumber ?? $this->tracking_number,
            'tracking_url' => $trackingUrl ?? $this->tracking_url,
            'shipped_at' => now(),
        ]);

        $this->order->events()->create([
            'type' => 'shipment_dispatched',
            'description' => 'Shipment dispatched.',
            'meta' => [
                'tracking_number' => $this->tracking_number,
                'tracking_url' => $this->tracking_url,
            ],
        ]);
    }

    public function markAsDelivered(array $payload = []): void
    {
        $this->update([
            'status' => ShipmentStatus::DELIVERED,
            'payload' => $payload === [] ? $this->payload : $payload,
            'delivered_at' => now(),
        ]);

        $this->order->events()->create([
            'type' => 'shipment_delivered',
            'description' => 'Shipment delivered.',
        ]);
    }

    public function markAsFailed(array $payload = []): void
    {
        $this->update([
            'status' => ShipmentStatus::FAILED,
            'payload' => $payload === [] ? $this->payload : $payload,
        ]);

        $this->order->events()->create([
            'type' => 'shipment_failed',
            'description' => 'Shipment failed.',
            'meta' => [
                'payload' => $payload === [] ? $this->payload : $payload,
            ],
        ]);
    }

    public function markAsCancelled(array $payload = []): void
    {
        $this->update([
            'status' => ShipmentStatus::CANCELLED,
            'payload' => $payload === [] ? $this->payload : $payload,
        ]);

        $this->order->events()->create([
            'type' => 'shipment_cancelled',
            'description' => 'Shipment cancelled.',
            'meta' => [
                'provider' => $this->provider?->value,
                'provider_reference' => $this->provider_reference,
                'tracking_number' => $this->tracking_number,
            ],
        ]);
    }

    public function markAsReturnedToSender(array $payload = []): void
    {
        if (! in_array($this->status, [
            ShipmentStatus::CREATED,
            ShipmentStatus::DISPATCHED,
            ShipmentStatus::IN_TRANSIT,
        ], true)) {
            throw new DomainException('Only created or dispatched shipments can be marked as returned to sender.');
        }

        $this->update([
            'status' => ShipmentStatus::RETURNED,
            'payload' => $payload === [] ? $this->payload : $payload,
        ]);

        $this->order->events()->create([
            'type' => 'shipment_returned_to_sender',
            'description' => 'Shipment returned to sender.',
            'meta' => [
                'provider' => $this->provider?->value,
                'provider_reference' => $this->provider_reference,
                'tracking_number' => $this->tracking_number,
            ],
        ]);
    }

    public function markAsInTransit(array $payload = []): void
    {
        $this->update([
            'status' => ShipmentStatus::IN_TRANSIT,
            'payload' => $payload === [] ? $this->payload : $payload,
        ]);

        $this->order->events()->create([
            'type' => 'shipment_in_transit',
            'description' => 'Shipment is in transit.',
            'meta' => [
                'provider' => $this->provider?->value,
                'provider_reference' => $this->provider_reference,
                'tracking_number' => $this->tracking_number,
            ],
        ]);
    }

    public function syncProviderPayload(array $payload): void
    {
        $this->update([
            'payload' => $payload,
        ]);

        $this->order->events()->create([
            'type' => 'shipment_status_synced',
            'description' => 'Shipment status synced.',
            'meta' => [
                'provider' => $this->provider?->value,
                'provider_reference' => $this->provider_reference,
            ],
        ]);
    }

    public function hasStoredLabel(): bool
    {
        return filled($this->label_disk) && filled($this->label_path);
    }

    public function markLabelStored(string $disk, string $path): void
    {
        $this->update([
            'label_disk' => $disk,
            'label_path' => $path,
            'label_downloaded_at' => now(),
        ]);

        $this->order->events()->create([
            'type' => 'shipment_label_stored',
            'description' => 'Shipment label stored.',
            'meta' => [
                'provider' => $this->provider?->value,
                'provider_reference' => $this->provider_reference,
                'disk' => $disk,
                'path' => $path,
            ],
        ]);
    }
}
