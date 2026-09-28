<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

it('fails when required shop production configuration is missing', function (): void {
    Config::set('legal.versions.terms', '');
    Config::set('app.debug', true);

    $this->artisan('shop:check')
        ->expectsOutputToContain('Shop production readiness check')
        ->expectsOutputToContain('Shop configuration is NOT ready for production.')
        ->assertFailed();
});

it('passes when required shop production configuration is present', function (): void {
    configureReadyShopForCommandTest();

    $this->artisan('shop:check')
        ->expectsOutputToContain('Shop production readiness check')
        ->expectsOutputToContain('Shop configuration is ready for production.')
        ->assertSuccessful();
});

it('can output shop production readiness as json', function (): void {
    Config::set('legal.versions.terms', '');
    Config::set('app.debug', true);

    $exitCode = Artisan::call('shop:check', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(1)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload)->toBeArray()->toHaveKey('ready')->toHaveKey('items')
        ->and($payload['ready'])->toBeFalse()
        ->and($payload['items'])->toBeArray();
});

function configureReadyShopForCommandTest(): void
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
    Config::set('app.url', 'https://konji-shop.example.test');
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
