<?php

use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs active Polkurier shipment statuses through the command', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::IN_TRANSIT,
        'provider_reference' => '1234-1',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.com/track/TRACK123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
    ]);

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'url' => 'https://example.com/track/TRACK123',
                'status_date' => now()->format('Y-m-d H:i'),
                'status' => 'Dostarczone',
                'status_code' => 'D',
                'delivered_date' => now()->format('Y-m-d'),
            ],
        ]),
    ]);

    $this
        ->artisan('polkurier:sync-shipments')
        ->expectsOutputToContain('Shipment #'.$shipment->id.' synced')
        ->expectsOutputToContain('Polkurier shipment sync finished. Synced: 1. Failed: 0.')
        ->assertSuccessful();

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::DELIVERED)
        ->delivered_at->not->toBeNull();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});

it('supports dry run without calling Polkurier', function (): void {
    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::CREATED,
        'provider_reference' => '1234-1',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.com/track/TRACK123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
    ]);

    Http::fake();

    $this
        ->artisan('polkurier:sync-shipments --dry-run')
        ->expectsOutputToContain('Dry run complete. No shipments were synced.')
        ->assertSuccessful();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::CREATED);

    Http::assertNothingSent();
});

it('can sync one Polkurier shipment by id', function (): void {
    $firstOrder = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $secondOrder = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $firstShipment = Shipment::query()->create([
        'order_id' => $firstOrder->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::IN_TRANSIT,
        'provider_reference' => '1234-1',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.com/track/TRACK123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
    ]);

    $secondShipment = Shipment::query()->create([
        'order_id' => $secondOrder->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::IN_TRANSIT,
        'provider_reference' => '5678-1',
        'tracking_number' => 'TRACK456',
        'tracking_url' => 'https://example.com/track/TRACK456',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
    ]);

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'url' => 'https://example.com/track/TRACK123',
                'status_date' => now()->format('Y-m-d H:i'),
                'status' => 'Dostarczone',
                'status_code' => 'D',
                'delivered_date' => now()->format('Y-m-d'),
            ],
        ]),
    ]);

    $this
        ->artisan('polkurier:sync-shipments --shipment='.$firstShipment->id)
        ->expectsOutputToContain('Shipment #'.$firstShipment->id.' synced')
        ->assertSuccessful();

    expect($firstShipment->refresh()->status)->toBe(ShipmentStatus::DELIVERED);
    expect($secondShipment->refresh()->status)->toBe(ShipmentStatus::IN_TRANSIT);

    Http::assertSentCount(1);
});

it('returns success when no Polkurier shipments need syncing', function (): void {
    $this
        ->artisan('polkurier:sync-shipments')
        ->expectsOutput('No Polkurier shipments found for status sync.')
        ->assertSuccessful();
});
