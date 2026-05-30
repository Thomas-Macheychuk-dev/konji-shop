<?php

use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('allows an admin to refresh a Polkurier shipment status as delivered', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

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
        ->actingAs($admin)
        ->patch(route('admin.shipments.status.refresh', $shipment))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::DELIVERED)
        ->delivered_at->not->toBeNull();

    expect($shipment->payload)
        ->toHaveKey('polkurier_status')
        ->and($shipment->payload['polkurier_status']['status_code'])->toBe('D');

    expect($shipment->refresh())
        ->provider_status_code->toBe('D')
        ->provider_status_label->toBe('Dostarczone')
        ->provider_status_updated_at->not->toBeNull()
        ->provider_delivered_at->not->toBeNull()
        ->status_synced_at->not->toBeNull();

    expect($shipment->tracking_url)->toBe('https://example.com/track/TRACK123');

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});
