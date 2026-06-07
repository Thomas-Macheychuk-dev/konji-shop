<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\DeliveryService;
use App\Exceptions\Delivery\PolkurierCarrierAvailabilityException;
use App\Models\Order;
use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use App\Services\Delivery\Polkurier\PolkurierCarrierAvailabilityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget(PolkurierAvailableCarriersService::CACHE_KEY);

    config()->set('delivery.providers.polkurier.available_carriers.guards.enabled', true);
    config()->set('delivery.providers.polkurier.available_carriers.guards.fail_when_cache_empty', false);
    config()->set('delivery.providers.polkurier.available_carriers.guards.block_required_additional_fields', true);
    config()->set('delivery.providers.polkurier.default_pack.shipmenttype', 'box');
});

it('allows shipment creation when carrier cache has not been refreshed yet by default', function (): void {
    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    $check = app(PolkurierCarrierAvailabilityGuard::class)->check($order);

    expect($check)
        ->blocking->toBeFalse()
        ->severity->toBe('warning')
        ->courier_code->toBe('DPD');

    app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order);

    expect(true)->toBeTrue();
});

it('blocks shipment creation when cache is required but empty', function (): void {
    config()->set('delivery.providers.polkurier.available_carriers.guards.fail_when_cache_empty', true);

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    expect(fn () => app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order))
        ->toThrow(PolkurierCarrierAvailabilityException::class, 'Dane dostępnych przewoźników Polkurier nie zostały jeszcze odświeżone.');
});

it('blocks shipment creation when selected courier is not returned by Polkurier', function (): void {
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

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    expect(fn () => app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order))
        ->toThrow(PolkurierCarrierAvailabilityException::class, 'Polkurier nie zwrócił wybranego kodu kuriera DPD w dostępnych przewoźnikach.');
});

it('blocks shipment creation when shipment type is explicitly unavailable', function (): void {
    Cache::put(PolkurierAvailableCarriersService::CACHE_KEY, [
        [
            'servicecode' => 'DPD',
            'name' => 'DPD Classic',
            'additional_data' => [
                'shipmenttype' => [
                    'box' => [
                        'available' => false,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    expect(fn () => app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order))
        ->toThrow(PolkurierCarrierAvailabilityException::class, 'Polkurier carrier DPD does not currently support shipment type box.');
});

it('warns and exposes definitions when selected courier requires additional fields', function (): void {
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
                        'label' => 'Zabezpieczenie transportu',
                        'description' => 'Opisz, jak paczka jest zabezpieczona.',
                        'type' => 'TEXT',
                        'required' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    $check = app(PolkurierCarrierAvailabilityGuard::class)->check($order);

    expect($check)
        ->blocking->toBeFalse()
        ->severity->toBe('warning')
        ->courier_code->toBe('DPD')
        ->missing_required_fields->toBe(['external_transport_security']);

    expect($check['additional_fields'])
        ->toHaveCount(1)
        ->and($check['additional_fields'][0]['name'])->toBe('external_transport_security')
        ->and($check['additional_fields'][0]['label'])->toBe('Zabezpieczenie transportu')
        ->and($check['additional_fields'][0]['description'])->toBe('Opisz, jak paczka jest zabezpieczona.')
        ->and($check['additional_fields'][0]['type'])->toBe('TEXT')
        ->and($check['additional_fields'][0]['required'])->toBeTrue();
});

it('blocks shipment creation when required additional field values are missing', function (): void {
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
                        'required' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    expect(fn () => app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order))
        ->toThrow(PolkurierCarrierAvailabilityException::class, 'external_transport_security');

    expect(fn () => app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order, [
        'external_transport_security' => '',
    ]))->toThrow(PolkurierCarrierAvailabilityException::class, 'external_transport_security');
});

it('allows shipment creation when required additional field values are provided', function (): void {
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
                        'required' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order, [
        'external_transport_security' => 'Foam and cardboard protection',
    ]);

    expect(true)->toBeTrue();
});

it('normalizes select additional field definitions', function (): void {
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
                        'label' => 'Zabezpieczenie transportu',
                        'type' => 'SELECT',
                        'required' => true,
                        'options' => [
                            [
                                'value' => 'stretch_wrap',
                                'label' => 'Folia stretch',
                            ],
                            [
                                'value' => 'cardboard',
                                'label' => 'Karton',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    $definitions = app(PolkurierCarrierAvailabilityGuard::class)
        ->additionalFieldDefinitions($order);

    expect($definitions)
        ->toHaveCount(1)
        ->and($definitions[0]['name'])->toBe('external_transport_security')
        ->and($definitions[0]['type'])->toBe('SELECT')
        ->and($definitions[0]['options'])->toBe([
            [
                'value' => 'stretch_wrap',
                'label' => 'Folia stretch',
            ],
            [
                'value' => 'cardboard',
                'label' => 'Karton',
            ],
        ]);
});

it('allows shipment creation when selected courier is available and has no required additional fields', function (): void {
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
                        'name' => 'optional_note',
                        'required' => false,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    $check = app(PolkurierCarrierAvailabilityGuard::class)->check($order);

    expect($check)
        ->blocking->toBeFalse()
        ->severity->toBe('success')
        ->courier_code->toBe('DPD');

    app(PolkurierCarrierAvailabilityGuard::class)->ensureCanCreateShipment($order);

    expect(true)->toBeTrue();
});

it('maps InPost parcel locker delivery to the InPost Paczkomat courier code', function (): void {
    Cache::put(PolkurierAvailableCarriersService::CACHE_KEY, [
        [
            'servicecode' => 'INPOST_PACZKOMAT',
            'name' => 'InPost Paczkomat',
            'additional_data' => [
                'shipmenttype' => [
                    'box' => [
                        'available' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

    $order = Order::factory()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::INPOST,
        'delivery_service' => DeliveryService::PARCEL_LOCKER->value,
    ]);

    $check = app(PolkurierCarrierAvailabilityGuard::class)->check($order);

    expect($check)
        ->blocking->toBeFalse()
        ->courier_code->toBe('INPOST_PACZKOMAT');
});
