<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::forget('polkurier:checkout-map-token');
});

it('returns a cached Polkurier points-map token for checkout', function (): void {
    config()->set('delivery.providers.polkurier.base_url', 'https://api.polkurier.pl');
    config()->set('delivery.providers.polkurier.login', 'test-login');
    config()->set('delivery.providers.polkurier.token', 'test-token');

    Http::fake([
        'https://api.polkurier.pl/*' => Http::response([
            'status' => 'success',
            'response' => [
                'token' => 'map-jwt-token',
            ],
        ]),
    ]);

    $expected = [
        'token' => 'map-jwt-token',
        'map_base_url' => 'https://maps.polkurier.pl/',
    ];

    $this->getJson(route('checkout.polkurier-map-token'))
        ->assertOk()
        ->assertExactJson($expected);

    $this->getJson(route('checkout.polkurier-map-token'))
        ->assertOk()
        ->assertExactJson($expected);

    Http::assertSentCount(1);

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'get_map_token'
        && $request['data'] === []
        && $request['authorization']['login'] === 'test-login'
        && $request['authorization']['token'] === 'test-token');
});

it('returns the sandbox map base URL when Polkurier API uses sandbox', function (): void {
    config()->set('delivery.providers.polkurier.base_url', 'https://api-sandbox.polkurier.pl');
    config()->set('delivery.providers.polkurier.login', 'test-login');
    config()->set('delivery.providers.polkurier.token', 'test-token');

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'token' => 'sandbox-map-jwt-token',
            ],
        ]),
    ]);

    $this->getJson(route('checkout.polkurier-map-token'))
        ->assertOk()
        ->assertJsonPath('map_base_url', 'https://maps-sandbox.polkurier.pl/');
});
