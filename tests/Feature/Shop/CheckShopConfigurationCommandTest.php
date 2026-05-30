<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

it('fails when required shop production configuration is missing', function (): void {
    Config::set('legal.versions.terms', '');
    Config::set('app.debug', true);

    $this
        ->artisan('shop:check')
        ->expectsOutputToContain('Shop production readiness check')
        ->expectsOutputToContain('Shop configuration is NOT ready for production.')
        ->assertFailed();
});

it('passes when required shop production configuration is present', function (): void {
    configureReadyShopForCommandTest();

    $this
        ->artisan('shop:check')
        ->expectsOutputToContain('Shop production readiness check')
        ->expectsOutputToContain('Shop configuration is ready for production.')
        ->assertSuccessful();
});

it('can output shop production readiness as json', function (): void {
    Config::set('legal.versions.terms', '');
    Config::set('app.debug', true);

    $exitCode = Artisan::call('shop:check', [
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

function configureReadyShopForCommandTest(): void
{
    Config::set('legal.versions.terms', 'terms-v1');
    Config::set('legal.versions.privacy', 'privacy-v1');
    Config::set('legal.versions.returns', 'returns-v1');

    Config::set('legal.seller.company_name', 'Konji Shop Sp. z o.o.');
    Config::set('legal.seller.street', 'Bolesława Prusa 20');
    Config::set('legal.seller.postcode', '60-406');
    Config::set('legal.seller.city', 'Poznań');
    Config::set('legal.seller.country', 'Poland');

    Config::set('legal.seller.email', 'shop@example.test');
    Config::set('legal.seller.phone', '+48123123123');
    Config::set('legal.returns.return_address', 'Bolesława Prusa 20, 60-406 Poznań, Poland');

    Config::set('app.url', 'https://konji-shop.example.test');
    Config::set('app.debug', false);

    Config::set('payments.default', 'paynow');
    Config::set('mail.from.address', 'shop@example.test');

    Config::set('delivery.providers.polkurier.base_url', 'https://api.polkurier.pl');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'test-token');
}
