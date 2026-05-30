<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('returns Polkurier pickup times for an admin order', function (): void {
    Config::set('delivery.providers.polkurier.base_url', 'https://api-sandbox.polkurier.test');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'test-token');
    Config::set('delivery.providers.polkurier.sender', [
        'postcode' => '87-100',
        'country' => 'PL',
    ]);
    Config::set('delivery.providers.polkurier.default_pack', [
        'shipmenttype' => 'box',
    ]);

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                [
                    'pickupdate' => now()->addDay()->format('Y-m-d'),
                    'time' => [
                        [
                            'timefrom' => '10:00',
                            'timeto' => '12:00',
                        ],
                        [
                            'timefrom' => '12:00',
                            'timeto' => '14:00',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => 'courier',
    ]);

    $order->shippingAddress()->create([
        'type' => 'shipping',
        'first_name' => 'Jan',
        'last_name' => 'Kowalski',
        'company' => null,
        'phone' => '123456789',
        'email' => 'customer@example.test',
        'address_line_1' => 'Testowa 1',
        'address_line_2' => null,
        'city' => 'Warszawa',
        'postcode' => '00-001',
        'country_code' => 'PL',
    ]);

    $expectedDate = now()->addDay()->format('Y-m-d');

    $this
        ->actingAs($admin)
        ->getJson(route('admin.orders.polkurier-pickup-times', $order))
        ->assertOk()
        ->assertJson([
            'data' => [
                [
                    'date' => $expectedDate,
                    'time_from' => '10:00',
                    'time_to' => '12:00',
                    'label' => $expectedDate.' 10:00-12:00',
                ],
                [
                    'date' => $expectedDate,
                    'time_from' => '12:00',
                    'time_to' => '14:00',
                    'label' => $expectedDate.' 12:00-14:00',
                ],
            ],
        ]);

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'get_courier_pickup_time'
        && $request['data']['courier'] === 'DPD'
        && $request['data']['shipfrom'] === '87-100'
        && $request['data']['shipto'] === '00-001'
        && $request['data']['shipmenttype'] === 'box');
});

it('does not allow guests to fetch Polkurier pickup times', function (): void {
    $order = Order::factory()->create();

    $this
        ->getJson(route('admin.orders.polkurier-pickup-times', $order))
        ->assertUnauthorized();
});

it('does not allow non-admin users to fetch Polkurier pickup times', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $order = Order::factory()->create();

    $this
        ->actingAs($user)
        ->getJson(route('admin.orders.polkurier-pickup-times', $order))
        ->assertForbidden();
});
