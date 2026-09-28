<?php

use App\Models\ShopConfigurationValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('shows the production readiness page to admins', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

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

    $this->actingAs($admin)
        ->get(route('admin.shop.readiness'))
        ->assertOk()
        ->assertSee('Gotowość produkcyjna')
        ->assertSee('Gotowe do produkcji')
        ->assertSee('Kontrole konfiguracji')
        ->assertSee('Ustawienia gotowości produkcyjnej')
        ->assertSee('Pełna nazwa firmy')
        ->assertSee('KRS')
        ->assertSee('REGON')
        ->assertSee('Kapitał zakładowy')
        ->assertSee('Wersja Regulaminu')
        ->assertSee('Data wejścia w życie')
        ->assertSee('Adres nadawcy e-mail')
        ->assertSee('Login Polkurier')
        ->assertSee('Token Polkurier')
        ->assertSee('Wersje dokumentów prawnych')
        ->assertSee('Domyślny operator płatności')
        ->assertSee(route('admin.shop.readiness'), false)
        ->assertSee('Bazowy URL Polkurier')
        ->assertSee('php artisan shop:check');
});

it('allows admins to update editable production readiness settings', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $settings = [
        'seller_company_name' => 'MAX-CORP sp. z o.o.',
        'seller_street' => 'Bolesława Prusa 20',
        'seller_postcode' => '60-820',
        'seller_city' => 'Poznań',
        'seller_country' => 'Polska',
        'seller_email' => 'sklep.ortezka@gmail.com',
        'seller_phone' => '+48 697 595 045',
        'seller_tax_id' => '7811955475',
        'seller_registry_number' => '0000701386',
        'seller_regon' => '368620625',
        'seller_share_capital' => '282 450,00 zł',
        'return_address' => 'Bolesława Prusa 20, 60-820 Poznań',
        'returns_email' => 'sklep.ortezka@gmail.com',
        'legal_terms_version' => '2026-09-24',
        'legal_privacy_version' => '2026-09-24',
        'legal_returns_version' => '2026-09-24',
        'legal_effective_date' => '2026-09-28',
        'mail_from_address' => 'shop@example.test',
        'polkurier_login' => 'polkurier-login',
        'polkurier_token' => 'polkurier-token',
    ];

    $this->actingAs($admin)
        ->patch(route('admin.shop.readiness.update'), ['settings' => $settings])
        ->assertRedirect(route('admin.shop.readiness'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Ustawienia gotowości produkcyjnej zostały zapisane.');

    expect(ShopConfigurationValue::query()->count())->toBe(count($settings));

    $this->assertDatabaseHas('shop_configuration_values', [
        'key' => 'legal.seller.company_name',
        'value' => 'MAX-CORP sp. z o.o.',
    ]);
    $this->assertDatabaseHas('shop_configuration_values', [
        'key' => 'legal.seller.business_registry_number',
        'value' => '0000701386',
    ]);
    $this->assertDatabaseHas('shop_configuration_values', [
        'key' => 'legal.effective_date',
        'value' => '2026-09-28',
    ]);
    $this->assertDatabaseHas('shop_configuration_values', [
        'key' => 'delivery.providers.polkurier.token',
        'value' => 'polkurier-token',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.shop.readiness'))
        ->assertOk()
        ->assertSee('MAX-CORP sp. z o.o.')
        ->assertSee('sklep.ortezka@gmail.com')
        ->assertSee('7811955475')
        ->assertSee('0000701386')
        ->assertSee('2026-09-28')
        ->assertSee('polkurier-login');
});

it('validates editable production readiness settings', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->from(route('admin.shop.readiness'))
        ->patch(route('admin.shop.readiness.update'), [
            'settings' => [
                'seller_company_name' => '',
                'seller_street' => '',
                'seller_postcode' => '',
                'seller_city' => '',
                'seller_country' => '',
                'seller_email' => 'not-an-email',
                'seller_phone' => '',
                'seller_tax_id' => '',
                'seller_registry_number' => '',
                'seller_regon' => '',
                'seller_share_capital' => '',
                'return_address' => '',
                'returns_email' => 'not-an-email',
                'legal_terms_version' => '',
                'legal_privacy_version' => '',
                'legal_returns_version' => '',
                'legal_effective_date' => 'not-a-date',
                'mail_from_address' => 'not-an-email',
                'polkurier_login' => '',
                'polkurier_token' => '',
            ],
        ])
        ->assertRedirect(route('admin.shop.readiness'))
        ->assertSessionHasErrors([
            'settings.seller_company_name',
            'settings.seller_street',
            'settings.seller_postcode',
            'settings.seller_city',
            'settings.seller_country',
            'settings.seller_email',
            'settings.seller_phone',
            'settings.seller_tax_id',
            'settings.seller_registry_number',
            'settings.seller_regon',
            'settings.seller_share_capital',
            'settings.return_address',
            'settings.returns_email',
            'settings.legal_terms_version',
            'settings.legal_privacy_version',
            'settings.legal_returns_version',
            'settings.legal_effective_date',
            'settings.mail_from_address',
            'settings.polkurier_login',
            'settings.polkurier_token',
        ]);
});

it('shows missing production readiness items to admins', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    Config::set('legal.versions.terms', '2026-05-30');
    Config::set('legal.versions.privacy', '2026-05-30');
    Config::set('legal.versions.returns', '2026-05-30');
    Config::set('legal.effective_date', '');
    Config::set('app.debug', true);

    $this->actingAs($admin)
        ->get(route('admin.shop.readiness'))
        ->assertOk()
        ->assertSee('Gotowość produkcyjna')
        ->assertSee('Nie gotowe do produkcji')
        ->assertSee('Wersje dokumentów prawnych')
        ->assertSee('APP_DEBUG')
        ->assertSee('APP_DEBUG musi mieć wartość false na produkcji.');
});

it('does not allow guests to view the production readiness page', function (): void {
    $this->get(route('admin.shop.readiness'))->assertRedirect(route('login'));
});

it('does not allow non-admin users to view the production readiness page', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $this->actingAs($user)->get(route('admin.shop.readiness'))->assertForbidden();
});
