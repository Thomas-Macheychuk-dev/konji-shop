<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('shows the production readiness page to admins', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

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

    $this
        ->actingAs($admin)
        ->get(route('admin.shop.readiness'))
        ->assertOk()
        ->assertSee('Gotowość produkcyjna')
        ->assertSee('Gotowe do produkcji')
        ->assertSee('Kontrole konfiguracji')
        ->assertSee('Wersje dokumentów prawnych')
        ->assertSee('Tożsamość i adres sprzedawcy')
        ->assertSee('Domyślny operator płatności')
        ->assertSee(route('admin.shop.readiness'), false)
        ->assertSee('Gotowość')
        ->assertSee('Bazowy URL Polkurier')
        ->assertSee('Polecenie konsoli')
        ->assertSee('php artisan shop:check');
});

it('shows missing production readiness items to admins', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    Config::set('legal.versions.terms', '');
    Config::set('legal.versions.privacy', '');
    Config::set('legal.versions.returns', '');

    Config::set('app.debug', true);

    $this
        ->actingAs($admin)
        ->get(route('admin.shop.readiness'))
        ->assertOk()
        ->assertSee('Gotowość produkcyjna')
        ->assertSee('Nie gotowe do produkcji')
        ->assertSee('Wersje dokumentów prawnych')
        ->assertSee('APP_DEBUG')
        ->assertSee('APP_DEBUG musi mieć wartość false na produkcji.');
});

it('does not allow guests to view the production readiness page', function (): void {
    $this
        ->get(route('admin.shop.readiness'))
        ->assertRedirect(route('login'));
});

it('does not allow non-admin users to view the production readiness page', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->get(route('admin.shop.readiness'))
        ->assertForbidden();
});
