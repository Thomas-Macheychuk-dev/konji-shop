<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Services\Delivery\Polkurier\PolkurierShippingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('returns zero shipping amount for local pickup without calling Polkurier', function (): void {
    Http::fake();

    $quote = app(PolkurierShippingQuoteService::class)->quote(
        provider: DeliveryProvider::POLKURIER,
        carrier: DeliveryCarrier::LOCAL_PICKUP,
        service: 'local_pickup',
        shippingAddress: [
            'postcode' => '87-100',
            'country_code' => 'PL',
        ],
        currency: 'PLN',
    );

    expect($quote->amount)->toBe(0)
        ->and($quote->provider)->toBe('polkurier')
        ->and($quote->carrier)->toBe('local_pickup')
        ->and($quote->service)->toBe('local_pickup');

    Http::assertNothingSent();
});

it('returns fallback shipping amount when Polkurier valuation is disabled', function (): void {
    Config::set('delivery.providers.polkurier.valuation.enabled', false);
    Config::set('delivery.providers.polkurier.valuation.fallback_prices.ups.courier', 1599);

    Http::fake();

    $quote = app(PolkurierShippingQuoteService::class)->quote(
        provider: DeliveryProvider::POLKURIER,
        carrier: DeliveryCarrier::UPS,
        service: 'courier',
        shippingAddress: [
            'postcode' => '87-100',
            'country_code' => 'PL',
        ],
        currency: 'PLN',
    );

    expect($quote->amount)->toBe(1599)
        ->and($quote->payload['source'])->toBe('fallback');

    Http::assertNothingSent();
});

it('returns Polkurier valuation gross price in grosze when valuation is enabled', function (): void {
    Config::set('delivery.providers.polkurier.valuation.enabled', true);
    Config::set('delivery.providers.polkurier.sender.postcode', '87-100');
    Config::set('delivery.providers.polkurier.sender.country', 'PL');

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                [
                    'servicecode' => 'UPS',
                    'servicename' => 'UPS - Standard',
                    'netprice' => 12.19,
                    'grossprice' => 14.99,
                    'shipment' => true,
                    'available' => true,
                    'unavailable_message' => '',
                ],
            ],
        ]),
    ]);

    $quote = app(PolkurierShippingQuoteService::class)->quote(
        provider: DeliveryProvider::POLKURIER,
        carrier: DeliveryCarrier::UPS,
        service: 'courier',
        shippingAddress: [
            'postcode' => '87-100',
            'country_code' => 'PL',
        ],
        currency: 'PLN',
    );

    expect($quote->amount)->toBe(1499)
        ->and($quote->providerServiceCode)->toBe('UPS')
        ->and($quote->providerServiceName)->toBe('UPS - Standard')
        ->and($quote->payload['source'])->toBe('polkurier_order_valuation_v2');

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'order_valuation_v2'
        && $request['data']['returnvaluations'] === 'UPS'
        && $request['data']['packs'][0]['length'] === 30
        && $request['data']['recipient']['postcode'] === '87-100');
});
