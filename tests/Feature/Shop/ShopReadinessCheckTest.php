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
    Config::set('payments.providers.paynow.api_key', '');
    Config::set('payments.providers.paynow.signature_key', '');
    Config::set('payments.providers.paynow.sandbox', true);
    Config::set('payments.providers.paynow.notification_path', '');
    Config::set('payments.providers.paynow.return_path', '');
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
            'Paynow API key',
            'Paynow signature key',
            'Paynow tryb produkcyjny',
            'Paynow notification path',
            'Paynow return path',
            'Adres nadawcy e-mail',
            'Bazowy URL Polkurier',
            'Login Polkurier',
            'Token Polkurier',
        );
});

it('reports the shop as ready when required production settings are configured', function (): void {
    configureProductionReadyShop();

    $check = app(ShopReadinessCheck::class);

    expect($check->isReady())->toBeTrue();

    $items = collect($check->items());

    expect($items->where('required', true)->where('status', '!=', 'ready'))->toHaveCount(0)
        ->and($items->firstWhere('name', 'NIP')['status'])->toBe('warning');
});

it('requires Paynow to be the default production payment provider', function (): void {
    configureProductionReadyShop();
    Config::set('payments.default', 'przelewy24');

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'Domyślny operator płatności');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires the Paynow API key', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.api_key', '');

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'Paynow API key');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires the Paynow signature key', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.signature_key', '');

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'Paynow signature key');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires Paynow production mode', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.sandbox', true);

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'Paynow tryb produkcyjny');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires a public HTTPS APP_URL', function (string $appUrl): void {
    configureProductionReadyShop();
    Config::set('app.url', $appUrl);

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'APP_URL');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
})->with([
    'plain HTTP' => 'http://ortezka.pl',
    'localhost' => 'https://localhost',
    'loopback' => 'https://127.0.0.1',
    'invalid URL' => 'ortezka.pl',
]);

it('requires the Paynow notification path to match the routed endpoint', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.notification_path', '/wrong-paynow-notification-path');

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'Paynow notification path');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires the Paynow return path to match the routed checkout endpoint', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.return_path', '/wrong-paynow-return-path');

    $item = collect(app(ShopReadinessCheck::class)->items())
        ->firstWhere('name', 'Paynow return path');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

function configureProductionReadyShop(): void
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
    Config::set('legal.seller.tax_id', '');
    Config::set('legal.returns.return_address', 'Bolesława Prusa 20, 60-406 Poznań, Poland');

    Config::set('app.url', 'https://ortezka.pl');
    Config::set('app.debug', false);

    Config::set('payments.default', 'paynow');
    Config::set('payments.providers.paynow.api_key', 'paynow-api-key');
    Config::set('payments.providers.paynow.signature_key', 'paynow-signature-key');
    Config::set('payments.providers.paynow.sandbox', false);
    Config::set('payments.providers.paynow.notification_path', '/payments/paynow/notifications');
    Config::set('payments.providers.paynow.return_path', '/checkout/success');
    Config::set('mail.from.address', 'shop@example.test');

    Config::set('delivery.providers.polkurier.base_url', 'https://api.polkurier.pl');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'test-token');
}
