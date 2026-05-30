<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\DeliveryService;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\PolkurierDeliveryGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('uses Polkurier point_id fields for parcel locker shipments', function (): void {
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
