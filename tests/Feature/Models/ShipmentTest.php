<?php

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to an order', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::PENDING,
    ]);

    expect($shipment->order->is($order))->toBeTrue();
});

it('casts provider and status to enums', function (): void {
    $shipment = Shipment::query()->create([
        'order_id' => Order::factory()->create()->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::PENDING,
    ]);

    expect($shipment->refresh())
        ->provider->toBe(DeliveryProvider::POLKURIER)
        ->status->toBe(ShipmentStatus::PENDING);
});

it('marks a shipment as created', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::PENDING,
    ]);

    $shipment->markAsCreated('inpost-123', [
        'raw' => 'payload',
    ]);

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::CREATED)
        ->provider_reference->toBe('inpost-123')
        ->payload->toBe([
            'raw' => 'payload',
        ]);

    expect($order->events()->where('type', 'shipment_created')->exists())->toBeTrue();
});

it('marks a shipment as dispatched', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::CREATED,
    ]);

    $shipment->markAsDispatched(
        trackingNumber: 'TRACK123',
        trackingUrl: 'https://example.test/track/TRACK123',
    );

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::DISPATCHED)
        ->tracking_number->toBe('TRACK123')
        ->tracking_url->toBe('https://example.test/track/TRACK123')
        ->shipped_at->not->toBeNull();

    expect($order->events()->where('type', 'shipment_dispatched')->exists())->toBeTrue();
});

it('marks a shipment as in transit', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::DISPATCHED,
    ]);

    $shipment->markAsInTransit([
        'status_code' => 'WP',
    ]);

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::IN_TRANSIT)
        ->payload->toBe([
            'status_code' => 'WP',
        ]);

    expect($order->events()->where('type', 'shipment_in_transit')->exists())->toBeTrue();
});

it('marks a shipment as delivered', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::DISPATCHED,
    ]);

    $shipment->markAsDelivered();

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::DELIVERED)
        ->delivered_at->not->toBeNull();

    expect($order->events()->where('type', 'shipment_delivered')->exists())->toBeTrue();
});

it('marks a shipment as failed', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::PENDING,
    ]);

    $shipment->markAsFailed([
        'error' => 'Provider rejected shipment',
    ]);

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::FAILED)
        ->payload->toBe([
            'error' => 'Provider rejected shipment',
        ]);

    expect($order->events()->where('type', 'shipment_failed')->exists())->toBeTrue();
});

it('marks a shipment as cancelled', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::CREATED,
    ]);

    $shipment->markAsCancelled();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::CANCELLED);

    expect($order->events()->where('type', 'shipment_cancelled')->exists())->toBeTrue();
});
