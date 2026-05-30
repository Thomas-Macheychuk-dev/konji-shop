<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Events\ShipmentTrackingAvailable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
        'protocol_disk',
        'protocol_path',
        'protocol_downloaded_at',
        'tracking_email_sent_at',
        'provider_status_code',
        'provider_status_label',
        'provider_status_updated_at',
        'provider_delivered_at',
        'status_synced_at',
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
            'protocol_downloaded_at' => 'datetime',
            'tracking_email_sent_at' => 'datetime',
            'provider_status_updated_at' => 'datetime',
            'provider_delivered_at' => 'datetime',
            'status_synced_at' => 'datetime',
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
        $this->update(array_merge([
            'status' => ShipmentStatus::CREATED,
            'provider_reference' => $providerReference ?? $this->provider_reference,
            'payload' => $payload === [] ? $this->payload : $payload,
        ], $this->providerStatusAttributes($payload)));

        $this->order->events()->create([
            'type' => 'shipment_created',
            'description' => 'Shipment created.',
            'meta' => [
                'provider' => $this->provider->value,
                'provider_reference' => $this->provider_reference,
            ],
        ]);

        if (filled($this->tracking_number) || filled($this->tracking_url)) {
            ShipmentTrackingAvailable::dispatch($this->refresh());
        }
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
        $providerStatusAttributes = $this->providerStatusAttributes($payload);

        $this->update(array_merge([
            'status' => ShipmentStatus::DELIVERED,
            'payload' => $payload === [] ? $this->payload : $payload,
            'delivered_at' => $providerStatusAttributes['provider_delivered_at'] ?? now(),
        ], $providerStatusAttributes));

        $this->order->events()->create([
            'type' => 'shipment_delivered',
            'description' => 'Shipment delivered.',
        ]);
    }

    public function markAsFailed(array $payload = []): void
    {
        $this->update(array_merge([
            'status' => ShipmentStatus::FAILED,
            'payload' => $payload === [] ? $this->payload : $payload,
        ], $this->providerStatusAttributes($payload)));

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
        $this->update(array_merge([
            'status' => ShipmentStatus::CANCELLED,
            'payload' => $payload === [] ? $this->payload : $payload,
        ], $this->providerStatusAttributes($payload)));

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

        $this->update(array_merge([
            'status' => ShipmentStatus::RETURNED,
            'payload' => $payload === [] ? $this->payload : $payload,
        ], $this->providerStatusAttributes($payload)));

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
        $providerStatusAttributes = $this->providerStatusAttributes($payload);

        $this->update(array_merge([
            'status' => ShipmentStatus::IN_TRANSIT,
            'payload' => $payload === [] ? $this->payload : $payload,
            'shipped_at' => $this->shipped_at
                ?? $providerStatusAttributes['provider_status_updated_at']
                    ?? now(),
        ], $providerStatusAttributes));

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
        $this->update(array_merge([
            'payload' => $payload,
        ], $this->providerStatusAttributes($payload)));

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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function providerStatusAttributes(array $payload): array
    {
        $statusData = data_get($payload, 'polkurier_status');

        if (! is_array($statusData)) {
            return [];
        }

        $attributes = [
            'status_synced_at' => now(),
        ];

        $statusCode = $statusData['status_code'] ?? null;

        if (is_scalar($statusCode) && trim((string) $statusCode) !== '') {
            $attributes['provider_status_code'] = trim((string) $statusCode);
        }

        $statusLabel = $statusData['status'] ?? null;

        if (is_scalar($statusLabel) && trim((string) $statusLabel) !== '') {
            $attributes['provider_status_label'] = trim((string) $statusLabel);
        }

        $trackingUrl = $statusData['url'] ?? null;

        if (is_scalar($trackingUrl) && trim((string) $trackingUrl) !== '') {
            $attributes['tracking_url'] = trim((string) $trackingUrl);
        }

        $statusUpdatedAt = $this->parseProviderDateTime($statusData['status_date'] ?? null);

        if ($statusUpdatedAt !== null) {
            $attributes['provider_status_updated_at'] = $statusUpdatedAt;
        }

        $providerDeliveredAt = $this->parseProviderDateTime($statusData['delivered_date'] ?? null);

        if ($providerDeliveredAt !== null) {
            $attributes['provider_delivered_at'] = $providerDeliveredAt;
        }

        return $attributes;
    }

    private function parseProviderDateTime(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasStoredProtocol(): bool
    {
        return filled($this->protocol_disk) && filled($this->protocol_path);
    }

    public function markProtocolStored(string $disk, string $path): void
    {
        $this->update([
            'protocol_disk' => $disk,
            'protocol_path' => $path,
            'protocol_downloaded_at' => now(),
        ]);

        $this->order->events()->create([
            'type' => 'shipment_protocol_stored',
            'description' => 'Shipment handover protocol stored.',
            'meta' => [
                'provider' => $this->provider?->value,
                'provider_reference' => $this->provider_reference,
                'disk' => $disk,
                'path' => $path,
            ],
        ]);
    }
}
