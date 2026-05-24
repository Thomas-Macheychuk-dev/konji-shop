<?php

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('allows an admin to cancel a Polkurier shipment', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

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

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'cancellation' => true,
            ],
        ]),
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.shipments.cancel', $shipment))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($shipment->refresh())
        ->status->toBe(ShipmentStatus::CANCELLED)
        ->payload->toHaveKey('polkurier_cancellation');

    expect($shipment->payload['polkurier_cancellation'])
        ->toBe([
            'cancellation' => true,
        ]);

    expect($order->events()->where('type', 'shipment_cancelled')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'cancel_order'
        && $request['data']['orderno'] === '1234-1');
});

it('does not allow cancelling a delivered shipment through Polkurier', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::DELIVERED,
        'provider_reference' => '1234-1',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.com/track/TRACK123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
    ]);

    Http::fake();

    $this
        ->actingAs($admin)
        ->patch(route('admin.shipments.cancel', $shipment))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::DELIVERED);

    Http::assertNothingSent();
});
