<?php

use App\Models\User;
use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget(PolkurierAvailableCarriersService::CACHE_KEY);
});

it('shows an empty available carriers cache on the diagnostics page', function (): void {
    Http::fake();

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.polkurier.index'))
        ->assertOk()
        ->assertSee('Available carriers')
        ->assertSee('Cached Polkurier carrier data: 0 carrier(s).')
        ->assertSee('No Polkurier carrier data cached yet.')
        ->assertSee('Refresh carriers from Polkurier');

    Http::assertNothingSent();
});

it('refreshes and displays Polkurier available carriers', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
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
                            'palette' => [
                                'available' => false,
                                'description' => 'Paleta',
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
                                'type' => 'SELECT',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
                [
                    'servicecode' => 'DPD',
                    'name' => 'DPD Classic',
                    'foreign_shipments' => false,
                    'additional_data' => [
                        'shipmenttype' => [
                            'box' => [
                                'available' => true,
                                'description' => 'Paczka',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this
        ->actingAs($admin)
        ->post(route('admin.polkurier.available-carriers.refresh'))
        ->assertRedirect(route('admin.polkurier.index'))
        ->assertSessionHas('success');

    $this
        ->actingAs($admin)
        ->get(route('admin.polkurier.index'))
        ->assertOk()
        ->assertSee('Cached Polkurier carrier data: 2 carrier(s).')
        ->assertSee('UPS courier')
        ->assertSee('UPS - Standard')
        ->assertSee('DPD courier')
        ->assertSee('DPD Classic')
        ->assertSee('Available')
        ->assertSee('Not returned')
        ->assertSee('box')
        ->assertSee('ROD')
        ->assertSee('external_transport_security');

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'available_carriers'
        && ($request['data']['additional_data'] ?? false) === true);
});

it('redirects back with an error when refreshing available carriers fails', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'error',
            'response' => 'Invalid token.',
        ]),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this
        ->actingAs($admin)
        ->post(route('admin.polkurier.available-carriers.refresh'))
        ->assertRedirect(route('admin.polkurier.index'))
        ->assertSessionHas('error');

    expect(Cache::get(PolkurierAvailableCarriersService::CACHE_KEY))->toBeNull();
});

it('does not allow non-admin users to refresh available carriers', function (): void {
    Http::fake();

    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->post(route('admin.polkurier.available-carriers.refresh'))
        ->assertForbidden();

    Http::assertNothingSent();
});
