<?php

use App\Enums\DeliveryProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows shipment tracking on the account order detail page', function (): void {
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
    ]);

    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::DISPATCHED,
        'provider_reference' => 'POL-123',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.com/track/TRACK123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
        'shipped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->get(route('account.orders.show', $order->id))
        ->assertOk()
        ->assertSee('Shipment tracking')
        ->assertSee('TRACK123')
        ->assertSee('Track shipment')
        ->assertSee('POL-123');
});

it('shows shipment tracking on the guest order status page', function (): void {
    $order = Order::factory()->create([
        'user_id' => null,
        'guest_email' => 'guest@example.com',
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
    ]);

    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::DISPATCHED,
        'provider_reference' => 'POL-456',
        'tracking_number' => 'TRACK456',
        'tracking_url' => 'https://example.com/track/TRACK456',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
        'shipped_at' => now(),
    ]);

    $this
        ->post(route('guest.orders.track.lookup'), [
            'number' => $order->number,
            'email' => 'guest@example.com',
        ])
        ->assertRedirect(route('guest.orders.show', $order));

    $this
        ->get(route('guest.orders.show', $order))
        ->assertOk()
        ->assertSee('Shipment tracking')
        ->assertSee('TRACK456')
        ->assertSee('Track shipment')
        ->assertSee('POL-456');
});
