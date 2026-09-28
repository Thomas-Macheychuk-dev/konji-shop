<?php

use App\Services\Shop\ShopReadinessCheck;
use Illuminate\Support\Facades\Config;

it('reports the shop as not ready when required production settings are missing', function (): void {
    Config::set('legal.versions.terms', '');
    Config::set('legal.versions.privacy', '');
    Config::set('legal.versions.returns', '');
    Config::set('legal.effective_date', '');
    Config::set('legal.seller.company_name', '');
    Config::set('legal.seller.street', '');
    Config::set('legal.seller.postcode', '');
    Config::set('legal.seller.city', '');
    Config::set('legal.seller.country', '');
    Config::set('legal.seller.email', '');
    Config::set('legal.seller.phone', '');
    Config::set('legal.seller.tax_id', '');
    Config::set('legal.seller.business_registry_number', '');
    Config::set('legal.seller.regon', '');
    Config::set('legal.seller.share_capital', '');
    Config::set('legal.returns.return_address', '');
    Config::set('legal.returns.contact_email', '');
    Config::set('app.url', '');
    Config::set('app.debug', true);
    Config::set('payments.default', '');
    Config::set('payments.providers.paynow.api_key', '');
    Config::set('payments.providers.paynow.signature_key', '');
    Config::set('payments.providers.paynow.sandbox', true);
    Config::set('payments.providers.paynow.notification_path', '');
    Config::set('payments.providers.paynow.return_path', '');
    Config::set('mail.default', 'smtp');
    Config::set('mail.mailers.smtp.transport', 'smtp');
    Config::set('mail.mailers.smtp.host', '');
    Config::set('mail.mailers.smtp.port', 587);
    Config::set('mail.mailers.smtp.username', '');
    Config::set('mail.mailers.smtp.password', '');
    Config::set('mail.from.address', '');
    Config::set('delivery.providers.polkurier.base_url', '');
    Config::set('delivery.providers.polkurier.login', '');
    Config::set('delivery.providers.polkurier.token', '');

    $check = app(ShopReadinessCheck::class);
    expect($check->isReady())->toBeFalse();

    expect(collect($check->items())->where('status', 'missing')->pluck('name')->all())
        ->toContain(
            'Wersje dokumentów prawnych',
            'Tożsamość i adres sprzedawcy',
            'E-mail sprzedawcy',
            'Telefon sprzedawcy',
            'Dane rejestrowe sprzedawcy',
            'Adres zwrotu',
            'APP_URL',
            'APP_DEBUG',
            'Domyślny operator płatności',
            'Paynow API key',
            'Paynow signature key',
            'Paynow tryb produkcyjny',
            'Paynow notification path',
            'Paynow return path',
            'Transport e-mail',
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
    expect(collect($check->items())->where('required', true)->where('status', '!=', 'ready'))->toHaveCount(0);
});

it('rejects the previous legal release as stale', function (): void {
    configureProductionReadyShop();
    Config::set('legal.versions.terms', '2026-05-30');

    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Wersje dokumentów prawnych');

    expect($item['status'])->toBe('missing')
        ->and($item['required'])->toBeTrue()
        ->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires Paynow to be the default production payment provider', function (): void {
    configureProductionReadyShop();
    Config::set('payments.default', 'przelewy24');
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Domyślny operator płatności');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires the Paynow API key', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.api_key', '');
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Paynow API key');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires the Paynow signature key', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.signature_key', '');
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Paynow signature key');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires Paynow production mode', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.sandbox', true);
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Paynow tryb produkcyjny');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires a public HTTPS APP_URL', function (string $appUrl): void {
    configureProductionReadyShop();
    Config::set('app.url', $appUrl);
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'APP_URL');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
})->with([
    'plain HTTP' => 'http://ortezka.pl',
    'localhost' => 'https://localhost',
    'loopback' => 'https://127.0.0.1',
    'invalid URL' => 'ortezka.pl',
]);

it('requires the Paynow notification path to match the routed endpoint', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.notification_path', '/wrong-paynow-notification-path');
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Paynow notification path');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires the Paynow return path to match the routed checkout endpoint', function (): void {
    configureProductionReadyShop();
    Config::set('payments.providers.paynow.return_path', '/wrong-paynow-return-path');
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Paynow return path');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
});

it('requires a real authenticated SMTP transport when SMTP is the production mailer', function (string $configKey, mixed $value): void {
    configureProductionReadyShop();
    Config::set($configKey, $value);
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Transport e-mail');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
})->with([
    'missing host' => ['mail.mailers.smtp.host', ''],
    'development mailpit host' => ['mail.mailers.smtp.host', 'mailpit'],
    'missing port' => ['mail.mailers.smtp.port', 0],
    'missing username' => ['mail.mailers.smtp.username', ''],
    'missing password' => ['mail.mailers.smtp.password', ''],
]);

it('rejects non-delivery mail transports for production readiness', function (string $transport): void {
    configureProductionReadyShop();
    Config::set('mail.mailers.smtp.transport', $transport);
    $item = collect(app(ShopReadinessCheck::class)->items())->firstWhere('name', 'Transport e-mail');
    expect($item['status'])->toBe('missing')->and($item['required'])->toBeTrue()->and(app(ShopReadinessCheck::class)->isReady())->toBeFalse();
})->with(['array', 'log']);

function configureProductionReadyShop(): void
{
    Config::set('legal.versions.terms', '2026-09-24');
    Config::set('legal.versions.privacy', '2026-09-24');
    Config::set('legal.versions.returns', '2026-09-24');
    Config::set('legal.effective_date', '2026-09-28');
    Config::set('legal.seller.company_name', 'MAX-CORP sp. z o.o.');
    Config::set('legal.seller.street', 'Bolesława Prusa 20');
    Config::set('legal.seller.postcode', '60-820');
    Config::set('legal.seller.city', 'Poznań');
    Config::set('legal.seller.country', 'Polska');
    Config::set('legal.seller.email', 'sklep.ortezka@gmail.com');
    Config::set('legal.seller.phone', '+48 697 595 045');
    Config::set('legal.seller.tax_id', '7811955475');
    Config::set('legal.seller.business_registry_number', '0000701386');
    Config::set('legal.seller.regon', '368620625');
    Config::set('legal.seller.share_capital', '282 450,00 zł');
    Config::set('legal.returns.return_address', 'Bolesława Prusa 20, 60-820 Poznań');
    Config::set('legal.returns.contact_email', 'sklep.ortezka@gmail.com');
    Config::set('app.url', 'https://ortezka.pl');
    Config::set('app.debug', false);
    Config::set('payments.default', 'paynow');
    Config::set('payments.providers.paynow.api_key', 'paynow-api-key');
    Config::set('payments.providers.paynow.signature_key', 'paynow-signature-key');
    Config::set('payments.providers.paynow.sandbox', false);
    Config::set('payments.providers.paynow.notification_path', '/payments/paynow/notifications');
    Config::set('payments.providers.paynow.return_path', '/checkout/success');
    Config::set('mail.default', 'smtp');
    Config::set('mail.mailers.smtp.transport', 'smtp');
    Config::set('mail.mailers.smtp.host', 'smtp.example.test');
    Config::set('mail.mailers.smtp.port', 587);
    Config::set('mail.mailers.smtp.username', 'shop@example.test');
    Config::set('mail.mailers.smtp.password', 'test-mail-password');
    Config::set('mail.from.address', 'shop@example.test');
    Config::set('delivery.providers.polkurier.base_url', 'https://api.polkurier.pl');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'test-token');
}
