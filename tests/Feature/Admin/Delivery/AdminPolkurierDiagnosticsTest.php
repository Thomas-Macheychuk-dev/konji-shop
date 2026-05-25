<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('shows the Polkurier diagnostics page to admins', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    Config::set('delivery.providers.polkurier.base_url', 'https://example.com');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'secret-token');

    $this
        ->actingAs($admin)
        ->get(route('admin.polkurier.index'))
        ->assertOk()
        ->assertSee('Polkurier diagnostics')
        ->assertSee('API configuration')
        ->assertSee('Test valuation')
        ->assertDontSee('secret-token');
});

it('does not allow guests to view Polkurier diagnostics', function (): void {
    $this
        ->get(route('admin.polkurier.index'))
        ->assertRedirect();
});

it('does not allow non-admin users to view Polkurier diagnostics', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->get(route('admin.polkurier.index'))
        ->assertForbidden();
});

it('allows an admin to run a Polkurier valuation test', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    Config::set('delivery.providers.polkurier.base_url', 'https://example.com');
    Config::set('delivery.providers.polkurier.login', 'test-login');
    Config::set('delivery.providers.polkurier.token', 'secret-token');
    Config::set('delivery.providers.polkurier.sender.postcode', '87-100');
    Config::set('delivery.providers.polkurier.sender.country', 'PL');
    Config::set('delivery.providers.polkurier.default_pack', [
        'shipmenttype' => 'box',
        'length' => 30,
        'width' => 20,
        'height' => 10,
        'weight' => 1,
        'amount' => 1,
        'type' => 'ST',
    ]);

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                [
                    'servicecode' => 'UPS',
                    'servicename' => 'UPS - Standard',
                    'netprice' => 12.19,
                    'grossprice' => 14.99,
                    'shipment' => true,
                    'available' => true,
                ],
            ],
        ]),
    ]);

    $this
        ->actingAs($admin)
        ->post(route('admin.polkurier.valuation-test'), [
            'courier_code' => 'UPS',
            'recipient_postcode' => '87-100',
            'recipient_country' => 'PL',
        ])
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionHas('polkurier_valuation_request')
        ->assertSessionHas('polkurier_valuation_response');

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'order_valuation_v2'
        && $request['data']['returnvaluations'] === 'UPS'
        && $request['data']['recipient']['postcode'] === '87-100'
        && $request['data']['packs'][0]['length'] === 30);
});
