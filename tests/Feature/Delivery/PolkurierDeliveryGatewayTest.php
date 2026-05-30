<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\DeliveryService;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use App\Services\Delivery\Polkurier\PolkurierDeliveryGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget(PolkurierAvailableCarriersService::CACHE_KEY);

    Config::set('delivery.providers.polkurier.sender', [
        'company' => 'Konji Shop',
        'person' => 'Konji Admin',
        'street' => 'Prusa',
        'housenumber' => '20',
        'flatnumber' => '',
        'postcode' => '87-100',
        'city' => 'Toruń',
        'email' => 'shop@example.test',
        'phone' => '123123123',
        'country' => 'PL',
        'point_id' => '',
    ]);

    Config::set('delivery.providers.polkurier.default_pack', [
        'shipmenttype' => 'box',
        'length' => 30,
        'width' => 20,
        'height' => 10,
        'weight' => 1,
        'amount' => 1,
        'type' => 'ST',
    ]);

    Config::set('delivery.providers.polkurier.available_carriers.guards.enabled', true);
    Config::set('delivery.providers.polkurier.available_carriers.guards.fail_when_cache_empty', false);
    Config::set('delivery.providers.polkurier.available_carriers.guards.block_required_additional_fields', true);
});

it('uses Polkurier point_id fields for parcel locker shipments', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'order_number' => '1234-10',
                'label' => ['13299300045383'],
                'url_tracktrace' => 'https://example.test/track/13299300045383',
            ],
        ]),
    ]);

    $order = Order::factory()->guest('guest@example.test')->paid()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::INPOST,
        'delivery_service' => DeliveryService::PARCEL_LOCKER->value,
        'delivery_locker_code' => 'WAW01A',
    ]);

    $order->shippingAddress()->create([
        'type' => 'shipping',
        'first_name' => 'Jan',
        'last_name' => 'Kowalski',
        'company' => null,
        'phone' => '123456789',
        'email' => 'guest@example.test',
        'address_line_1' => 'Test Street 1',
        'address_line_2' => null,
        'city' => 'Warszawa',
        'postcode' => '00-001',
        'country_code' => 'PL',
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::PENDING,
        'service' => DeliveryService::PARCEL_LOCKER->value,
        'locker_code' => 'WAW01A',
    ]);

    $result = app(PolkurierDeliveryGateway::class)->createShipment($order, $shipment);

    expect($result->providerReference)->toBe('1234-10')
        ->and($result->trackingNumber)->toBe('13299300045383')
        ->and($result->trackingUrl)->toBe('https://example.test/track/13299300045383');

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'create_order'
        && $request['data']['courier'] === 'INPOST_PACZKOMAT'
        && $request['data']['recipient']['point_id'] === 'WAW01A'
        && ! array_key_exists('machinename', $request['data']['recipient'])
        && array_key_exists('point_id', $request['data']['sender'])
        && ! array_key_exists('machinename', $request['data']['sender']));
});

it('sends Polkurier additional fields in the create order payload', function (): void {
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
                        'type' => 'TEXT',
                        'required' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'order_number' => '1234-11',
                'label' => ['TRACK123'],
                'url_tracktrace' => 'https://example.test/track/TRACK123',
            ],
        ]),
    ]);

    $order = Order::factory()->guest('guest@example.test')->paid()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
        'delivery_locker_code' => null,
    ]);

    $order->shippingAddress()->create([
        'type' => 'shipping',
        'first_name' => 'Jan',
        'last_name' => 'Kowalski',
        'company' => null,
        'phone' => '123456789',
        'email' => 'guest@example.test',
        'address_line_1' => 'Test Street 1',
        'address_line_2' => null,
        'city' => 'Warszawa',
        'postcode' => '00-001',
        'country_code' => 'PL',
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::PENDING,
        'service' => DeliveryService::COURIER->value,
        'locker_code' => null,
    ]);

    $result = app(PolkurierDeliveryGateway::class)->createShipment($order, $shipment, [
        'pickup' => [
            'nocourierorder' => true,
        ],
        'additional_fields' => [
            'external_transport_security' => 'Foam and cardboard protection',
        ],
    ]);

    expect($result->providerReference)->toBe('1234-11')
        ->and($result->trackingNumber)->toBe('TRACK123')
        ->and($result->trackingUrl)->toBe('https://example.test/track/TRACK123');

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'create_order'
        && $request['data']['courier'] === 'DPD'
        && $request['data']['additional_fields'] === [
            'external_transport_security' => 'Foam and cardboard protection',
        ]);
});
