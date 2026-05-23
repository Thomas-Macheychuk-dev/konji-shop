<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Shipment;
use App\Contracts\Delivery\CreatesShipments;
use App\Enums\ShipmentStatus;

uses(RefreshDatabase::class);

it('moves a confirmed paid order into fulfilment processing', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markFulfilmentAsProcessing();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::PROCESSING);
});

it('ships a confirmed paid order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
    ]);

    $order->markAsShipped();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::SHIPPED);
});

it('delivers a shipped order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $order->markAsDelivered();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});

it('completes a delivered order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
    ]);

    $order->complete();

    expect($order->refresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('allows an authenticated user to move an order into fulfilment processing through the admin route', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'processing']))
        ->assertRedirect();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::PROCESSING);
});

it('redirects back with error for an invalid fulfilment action', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $this->actingAs($user)
        ->from('/admin/orders/'.$order->id)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'invalid-action']))
        ->assertRedirect('/admin/orders/'.$order->id)
        ->assertSessionHas('error');

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::UNFULFILLED);
});

it('allows an authenticated user to ship an order through the admin route', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::UPS,
        'delivery_service' => 'UPS',
        'delivery_locker_code' => null,
    ]);

    $this->mock(CreatesShipments::class, function ($mock) use ($order): void {
        $mock->shouldReceive('create')
            ->once()
            ->with(
                \Mockery::on(fn (Order $givenOrder): bool => $givenOrder->is($order)),
                DeliveryProvider::POLKURIER->value,
                'UPS',
                null,
            )
            ->andReturnUsing(function (
                Order $order,
                string $provider,
                ?string $service = null,
                ?string $lockerCode = null,
            ): Shipment {
                return Shipment::query()->create([
                    'order_id' => $order->id,
                    'provider' => DeliveryProvider::from($provider),
                    'status' => ShipmentStatus::CREATED,
                    'provider_reference' => 'test-polkurier-order-123',
                    'tracking_number' => 'test-tracking-123',
                    'tracking_url' => 'https://example.com/track/test-tracking-123',
                    'service' => $service,
                    'locker_code' => $lockerCode,
                    'payload' => [
                        'test' => true,
                    ],
                ]);
            });
    });

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'shipped']))
        ->assertRedirect();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::SHIPPED);
    expect($order->shipments()->count())->toBe(1);
});

it('marks a pickup order as ready for pickup instead of creating a shipment', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_carrier' => 'local_pickup',
        'delivery_service' => 'pickup',
        'shipping_amount' => 0,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.orders.fulfilment.update', [
            'order' => $order,
            'action' => 'shipped',
        ]))
        ->assertRedirect();

    $order->refresh();

    expect($order->fulfilment_status)->toBe(FulfilmentStatus::READY_FOR_PICKUP);
    expect($order->shipments()->exists())->toBeFalse();
});

it('allows an authenticated user to deliver an order through the admin route', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'delivered']))
        ->assertRedirect();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});

it('allows an authenticated user to complete an order through the admin route', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'completed']))
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('marks a shipped order as returned to sender through the admin route', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::INPOST,
        'delivery_service' => 'courier',
        'delivery_locker_code' => null,
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::DISPATCHED,
        'provider_reference' => 'test-polkurier-order-123',
        'tracking_number' => 'test-tracking-123',
        'tracking_url' => 'https://example.com/track/test-tracking-123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [
            'test' => true,
        ],
        'shipped_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'returned']))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::RETURNED);
    expect($order->status)->toBe(OrderStatus::CONFIRMED);
    expect($order->payment_status)->toBe(PaymentStatus::PAID);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RETURNED);

    expect(
        $order->events()
            ->where('type', 'shipment_returned_to_sender')
            ->exists()
    )->toBeTrue();

    expect(
        $order->events()
            ->where('type', 'order_returned_to_sender')
            ->exists()
    )->toBeTrue();
});
