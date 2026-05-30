<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('fails when required Polkurier configuration is missing', function (): void {
    Config::set('delivery.providers.polkurier.base_url', null);
    Config::set('delivery.providers.polkurier.login', null);
    Config::set('delivery.providers.polkurier.token', null);

    $this
        ->artisan('polkurier:check')
        ->expectsOutputToContain('Polkurier configuration check')
        ->expectsOutputToContain('Polkurier is NOT ready for production.')
        ->assertFailed();
});

it('passes when required Polkurier configuration is present', function (): void {
    Config::set('delivery.providers.polkurier.base_url', 'https://example.com');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'secret-token');

    Config::set('delivery.providers.polkurier.sender', [
        'company' => 'Konji Shop',
        'person' => 'Konji Admin',
        'street' => 'Prusa',
        'housenumber' => '20',
        'flatnumber' => '',
        'postcode' => '87-100',
        'city' => 'Toruń',
        'email' => 'shop@example.com',
        'phone' => '123123123',
        'country' => 'PL',
        'machinename' => '',
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

    Config::set('delivery.providers.polkurier.labels', [
        'disk' => 'local',
        'path' => 'polkurier/labels',
    ]);

    $this
        ->artisan('polkurier:check')
        ->expectsOutputToContain('Polkurier configuration check')
        ->expectsOutputToContain('Polkurier is ready for production.')
        ->assertSuccessful();
});

it('can output Polkurier readiness as json', function (): void {
    Config::set('delivery.providers.polkurier.base_url', null);

    $exitCode = Artisan::call('polkurier:check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);

    $payload = json_decode(trim($output), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE);

    expect($payload)
        ->toBeArray()
        ->toHaveKey('ready')
        ->toHaveKey('items')
        ->and($payload['ready'])->toBeFalse()
        ->and($payload['items'])->toBeArray();
});
