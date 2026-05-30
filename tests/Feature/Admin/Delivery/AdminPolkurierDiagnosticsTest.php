<?php

use App\Models\User;
use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget(PolkurierAvailableCarriersService::CACHE_KEY);
});

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
        ->assertSee('Available carriers')
        ->assertSee('Cached Polkurier carrier data: 0 carrier(s).')
        ->assertSee('No Polkurier carrier data cached yet.')
        ->assertSee('Test valuation')
        ->assertSee('php artisan polkurier:check')
        ->assertSee('Protocols')
        ->assertSee('Carrier availability')
        ->assertSee('Operations')
        ->assertDontSee('secret-token');
});

it('shows cached available carrier readiness on the diagnostics page', function (): void {
    Cache::put(PolkurierAvailableCarriersService::CACHE_KEY, [
        [
            'servicecode' => 'UPS',
            'name' => 'UPS - Standard',
            'foreign_shipments' => false,
            'additional_data' => [
                'shipmenttype' => [
                    'box' => [
                        'available' => true,
                        'description' => 'Paczka',
                    ],
                ],
                'courierservice' => [
                    'ROD' => [
                        'available' => true,
                        'description' => 'Zwrot dokumentów',
                    ],
                ],
                'additional_fields' => [
                    [
                        'name' => 'external_transport_security',
                        'label' => 'Sposób zabezpieczenia towaru',
                        'type' => 'TEXT',
                        'required' => true,
                    ],
                ],
            ],
        ],
    ], now()->addHour());

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
        ->assertSee('Available carriers')
        ->assertSee('Cached Polkurier carrier data: 1 carrier(s).')
        ->assertSee('UPS courier')
        ->assertSee('UPS - Standard')
        ->assertSee('box')
        ->assertSee('ROD')
        ->assertSee('external_transport_security')
        ->assertDontSee('No Polkurier carrier data cached yet.');
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
