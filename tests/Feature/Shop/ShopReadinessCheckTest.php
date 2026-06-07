<?php

use App\Services\Shop\ShopReadinessCheck;
use Illuminate\Support\Facades\Config;

it('reports the shop as not ready when required production settings are missing', function (): void {
    Config::set('legal.versions.terms', '');
    Config::set('legal.versions.privacy', '');
    Config::set('legal.versions.returns', '');

    Config::set('legal.seller.company_name', '');
    Config::set('legal.seller.street', '');
    Config::set('legal.seller.postcode', '');
    Config::set('legal.seller.city', '');
    Config::set('legal.seller.country', '');

    Config::set('legal.seller.email', '');
    Config::set('legal.seller.phone', '');
    Config::set('legal.returns.return_address', '');

    Config::set('app.url', '');
    Config::set('app.debug', true);

    Config::set('payments.default', '');
    Config::set('mail.from.address', '');

    Config::set('delivery.providers.polkurier.base_url', '');
    Config::set('delivery.providers.polkurier.login', '');
    Config::set('delivery.providers.polkurier.token', '');

    $check = app(ShopReadinessCheck::class);

    expect($check->isReady())->toBeFalse();

    $items = collect($check->items());

    expect($items->where('status', 'missing')->pluck('name')->all())
        ->toContain(
            'Wersje dokumentów prawnych',
            'Tożsamość i adres sprzedawcy',
            'E-mail sprzedawcy',
            'Telefon sprzedawcy',
            'Adres zwrotu',
            'APP_URL',
            'APP_DEBUG',
            'Domyślny operator płatności',
            'Adres nadawcy e-mail',
            'Bazowy URL Polkurier',
            'Login Polkurier',
            'Token Polkurier',
        );
});

it('reports the shop as ready when required production settings are configured', function (): void {
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
    Config::set('legal.seller.tax_id', '');
    Config::set('legal.returns.return_address', 'Bolesława Prusa 20, 60-406 Poznań, Poland');

    Config::set('app.url', 'https://konji-shop.example.test');
    Config::set('app.debug', false);

    Config::set('payments.default', 'paynow');
    Config::set('mail.from.address', 'shop@example.test');

    Config::set('delivery.providers.polkurier.base_url', 'https://api.polkurier.pl');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'test-token');

    $check = app(ShopReadinessCheck::class);

    expect($check->isReady())->toBeTrue();

    $items = collect($check->items());

    expect($items->where('required', true)->where('status', '!=', 'ready'))->toHaveCount(0)
        ->and($items->firstWhere('name', 'NIP')['status'])->toBe('warning');
});
