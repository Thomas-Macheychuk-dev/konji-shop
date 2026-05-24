<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Services\Delivery\Polkurier\PolkurierShippingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('returns a free shipping quote for local pickup', function (): void {
    Http::fake();

    $this
        ->postJson(route('checkout.shipping-quote'), [
            'delivery_provider' => DeliveryProvider::POLKURIER->value,
            'delivery_carrier' => DeliveryCarrier::LOCAL_PICKUP->value,
            'delivery_service' => 'local_pickup',
            'currency' => 'PLN',
        ])
        ->assertOk()
        ->assertJson([
            'amount' => 0,
            'formatted' => 'Free',
            'currency' => 'PLN',
            'provider' => 'polkurier',
            'carrier' => 'local_pickup',
            'service' => 'local_pickup',
            'source' => 'local_pickup',
        ]);

    Http::assertNothingSent();
});

it('returns a fallback shipping quote for courier delivery', function (): void {
    Config::set('delivery.providers.polkurier.valuation.enabled', false);
    Config::set('delivery.providers.polkurier.valuation.fallback_prices.ups.courier', 1599);

    Http::fake();

    $this
        ->postJson(route('checkout.shipping-quote'), [
            'delivery_provider' => DeliveryProvider::POLKURIER->value,
            'delivery_carrier' => DeliveryCarrier::UPS->value,
            'delivery_service' => 'courier',
            'shipping_postcode' => '87-100',
            'shipping_country_code' => 'PL',
            'currency' => 'PLN',
        ])
        ->assertOk()
        ->assertJson([
            'amount' => 1599,
            'formatted' => '15,99 PLN',
            'currency' => 'PLN',
            'provider' => 'polkurier',
            'carrier' => 'ups',
            'service' => 'courier',
            'source' => 'fallback',
        ]);

    Http::assertNothingSent();
});

it('requires postcode for courier delivery quote', function (): void {
    $this
        ->postJson(route('checkout.shipping-quote'), [
            'delivery_provider' => DeliveryProvider::POLKURIER->value,
            'delivery_carrier' => DeliveryCarrier::UPS->value,
            'delivery_service' => 'courier',
            'shipping_country_code' => 'PL',
            'currency' => 'PLN',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shipping_postcode']);
});
