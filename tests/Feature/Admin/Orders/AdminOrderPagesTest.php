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
use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('shows a blocking Polkurier carrier availability warning on the shipment form', function (): void {
    Cache::put(PolkurierAvailableCarriersService::CACHE_KEY, [
        [
            'servicecode' => 'UPS',
            'name' => 'UPS - Standard',
            'additional_data' => [
                'shipmenttype' => [
                    'box' => [
                        'available' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-CARRIER-GUARD',
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => 'courier',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Polkurier carrier availability')
        ->assertSee('Polkurier did not return the selected courier code DPD in available carriers.')
        ->assertSee('Shipment creation is blocked until this is resolved.')
        ->assertSee('disabled', false);
});

it('shows failed shipment retry information on the admin order detail page', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => 'ups',
        'delivery_service' => 'courier',
    ]);

    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::FAILED,
        'service' => 'courier',
        'payload' => [
            'error' => [
                'message' => 'Polkurier rejected create_order.',
                'class' => RuntimeException::class,
            ],
        ],
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Latest shipment creation failed.')
        ->assertSee('Polkurier rejected create_order.')
        ->assertSee('Retry create shipment');
});

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
        ->assertSee('Fulfilment actions')
        ->assertSee('Customer')
        ->assertSee('Delivery choice')
        ->assertSee('InPost')
        ->assertSee('Parcel locker')
        ->assertSee('WAW01A')
        ->assertSee('Items')
        ->assertSee('Payments')
        ->assertSee('Internal notes');
});

it('shows the Polkurier pickup selector when creating a courier shipment', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-PICKUP-SELECTOR',
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => 'courier',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Fulfilment actions')
        ->assertSee('admin-polkurier-pickup-selector', false)
        ->assertSee(route('admin.orders.polkurier-pickup-times', $order), false)
        ->assertSeeText('Create shipment')
        ->assertDontSeeText('Create shipment & mark as shipped');
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

it('allows an admin to cancel a cancellable order', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
        'email' => 'admin@example.test',
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
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

it('does not allow an admin to cancel a completed order', function (): void {
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
        ->assertSessionHas('error');

    expect($order->refresh()->status)->toBe(OrderStatus::COMPLETED);
    expect((string) $order->notes)->not->toContain('Manual admin cancellation.');
});

it('shows required Polkurier additional fields on the shipment form', function (): void {
    Cache::put(PolkurierAvailableCarriersService::CACHE_KEY, [
        [
            'servicecode' => 'DPD',
            'name' => 'DPD Classic',
            'additional_data' => [
                'shipmenttype' => [
                    'box' => [
                        'available' => true,
                    ],
                ],
                'additional_fields' => [
                    [
                        'name' => 'external_transport_security',
                        'label' => 'Transport security',
                        'description' => 'Describe how the parcel is secured.',
                        'type' => 'SELECT',
                        'required' => true,
                        'options' => [
                            [
                                'value' => 'stretch_wrap',
                                'label' => 'Stretch wrap',
                            ],
                            [
                                'value' => 'cardboard',
                                'label' => 'Cardboard',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-ADDITIONAL-FIELDS',
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => 'courier',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Polkurier carrier availability')
        ->assertSee('Polkurier carrier DPD requires additional fields. Fill them in before creating the shipment.')
        ->assertSee('Polkurier additional fields')
        ->assertSee('Transport security')
        ->assertSee('Describe how the parcel is secured.')
        ->assertSee('polkurier_additional_fields[external_transport_security]', false)
        ->assertSee('Stretch wrap')
        ->assertSee('Cardboard')
        ->assertDontSee('Shipment creation is blocked until this is resolved.');
});

it('shows shipments on the admin order detail page', function (): void {
    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::INPOST,
        'delivery_service' => 'parcel_locker',
        'delivery_locker_code' => 'WAW01A',
    ]);

    Shipment::query()->create([
        'order_id' => $order->id,
        'provider_reference' => '1234-1',
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::CREATED,
        'service' => 'parcel_locker',
        'locker_code' => 'WAW01A',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.test/track/TRACK123',
        'provider_status_code' => 'WP',
        'provider_status_label' => 'W przewozie',
        'provider_status_updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Shipments')
        ->assertSee('InPost')
        ->assertSee('Created')
        ->assertSee('Parcel locker')
        ->assertSee('WAW01A')
        ->assertSee('TRACK123')
        ->assertSee('Documents')
        ->assertSee('Download label')
        ->assertSee('Download protocol')
        ->assertSee('Polkurier:')
        ->assertSee('W przewozie')
        ->assertSee('WP');
});
