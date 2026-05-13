<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the admin order index page', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-TEST-INDEX',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.index'))
        ->assertOk()
        ->assertSee('Orders')
        ->assertSee($order->number);
});

it('shows the admin order detail page', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-TEST-SHOW',
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::INPOST,
        'delivery_service' => 'parcel_locker',
        'delivery_locker_code' => 'WAW01A',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Order '.$order->number)
        ->assertSee('Start processing')
        ->assertSee('Customer')
        ->assertSee('Delivery choice')
        ->assertSee('InPost')
        ->assertSee('parcel_locker')
        ->assertSee('WAW01A')
        ->assertSee('Items')
        ->assertSee('Payments')
        ->assertSee('Internal notes');
});

it('redirects guests away from admin orders', function (): void {
    $this->get(route('admin.orders.index'))
        ->assertRedirect(route('login'));
});

it('forbids non-admin users from admin orders', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

it('filters admin orders by search term', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    Order::factory()->create([
        'number' => 'ORD-MATCH-123',
        'guest_email' => 'matching@example.test',
    ]);

    Order::factory()->create([
        'number' => 'ORD-NOPE-999',
        'guest_email' => 'other@example.test',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.index', ['search' => 'MATCH']))
        ->assertOk()
        ->assertSee('ORD-MATCH-123')
        ->assertDontSee('ORD-NOPE-999');
});

it('filters admin orders by order status', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    Order::factory()->create([
        'number' => 'ORD-CONFIRMED',
        'status' => OrderStatus::CONFIRMED,
    ]);

    Order::factory()->create([
        'number' => 'ORD-CANCELLED',
        'status' => OrderStatus::CANCELLED,
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.index', ['status' => OrderStatus::CONFIRMED->value]))
        ->assertOk()
        ->assertSee('ORD-CONFIRMED')
        ->assertDontSee('ORD-CANCELLED');
});

it('shows order timeline events on the admin order detail page', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markAsPaid();

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Order timeline')
        ->assertSee('Order confirmed after successful payment.');
});

it('shows order timeline event metadata on the admin order detail page', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create();

    $order->events()->create([
        'type' => 'payment_notification_received',
        'description' => 'Payment notification received from provider.',
        'meta' => [
            'external_status' => 'CONFIRMED',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Payment notification received from provider.')
        ->assertSee('External Status')
        ->assertSee('CONFIRMED');
});

it('allows an admin to add an internal order note', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
        'email' => 'admin@example.test',
    ]);

    $order = Order::factory()->create([
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->from(route('admin.orders.show', $order))
        ->patch(route('admin.orders.notes.update', $order), [
            'note' => 'Customer asked about delivery date.',
        ])
        ->assertRedirect(route('admin.orders.show', $order))
        ->assertSessionHas('success');

    expect($order->refresh()->notes)
        ->toContain('admin@example.test: Customer asked about delivery date.');

    expect($order->events()->where('type', 'note_added')->exists())->toBeTrue();
});

it('does not allow a normal user to add an internal order note', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $order = Order::factory()->create();

    $this->actingAs($user)
        ->patch(route('admin.orders.notes.update', $order), [
            'note' => 'Should not be added.',
        ])
        ->assertForbidden();

    expect($order->refresh()->notes)->toBeNull();
});

it('allows an admin to cancel an order at any time', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
        'email' => 'admin@example.test',
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
    ]);

    $this->actingAs($user)
        ->from(route('admin.orders.show', $order))
        ->patch(route('admin.orders.cancel', $order), [
            'note' => 'Manual admin cancellation.',
        ])
        ->assertRedirect(route('admin.orders.show', $order))
        ->assertSessionHas('success');

    expect($order->refresh())
        ->status->toBe(OrderStatus::CANCELLED)
        ->notes->toContain('admin@example.test: Manual admin cancellation.');

    expect($order->events()->where('type', 'order_cancelled_by_admin')->exists())->toBeTrue();
});

it('shows shipments on the admin order detail page', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create();

    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::CREATED,
        'service' => 'parcel_locker',
        'locker_code' => 'WAW01A',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.test/track/TRACK123',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Shipments')
        ->assertSee('Polkurier')
        ->assertSee('Created')
        ->assertSee('parcel_locker')
        ->assertSee('WAW01A')
        ->assertSee('TRACK123');
});
