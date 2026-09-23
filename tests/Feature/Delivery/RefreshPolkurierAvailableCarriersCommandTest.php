<?php

use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget(PolkurierAvailableCarriersService::CACHE_KEY);
});

it('refreshes Polkurier available carriers and can output json', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                [
                    'servicecode' => 'UPS',
                    'name' => 'UPS Standard',
                    'foreign_shipments' => false,
                    'additional_data' => [
                        'shipmenttype' => [
                            'box' => [
                                'available' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $exitCode = Artisan::call('polkurier:refresh-carriers', [
        '--json' => true,
    ]);

    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload)
        ->toBeArray()
        ->and($payload['refreshed'])->toBeTrue()
        ->and($payload['carrier_count'])->toBe(1)
        ->and($payload['configured_carriers'])->toBeArray()
        ->and(Cache::get(PolkurierAvailableCarriersService::CACHE_KEY))
        ->toBeArray()
        ->toHaveCount(1);

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'available_carriers'
        && ($request['data']['additional_data'] ?? false) === true);
});

it('fails without replacing the carrier cache when Polkurier refresh fails', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'error',
            'response' => 'Temporary Polkurier failure.',
        ]),
    ]);

    $exitCode = Artisan::call('polkurier:refresh-carriers', [
        '--json' => true,
    ]);

    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(1)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload)
        ->toBeArray()
        ->and($payload['refreshed'])->toBeFalse()
        ->and($payload['carrier_count'])->toBe(0)
        ->and($payload['error'])->toBeString()
        ->and(Cache::get(PolkurierAvailableCarriersService::CACHE_KEY))->toBeNull();
});

it('refreshes the Polkurier carrier cache after deployment cache clearing and before readiness', function (): void {
    $deploy = file_get_contents(base_path('scripts/deploy/aws-production.sh'));

    $optimizeClear = strpos($deploy, 'php artisan optimize:clear');
    $refreshCarriers = strpos($deploy, 'php artisan polkurier:refresh-carriers --json || true');
    $readinessCheck = strpos($deploy, 'php artisan polkurier:check --json || true');

    expect($optimizeClear)->not->toBeFalse()
        ->and($refreshCarriers)->not->toBeFalse()
        ->and($readinessCheck)->not->toBeFalse()
        ->and($refreshCarriers)->toBeGreaterThan($optimizeClear)
        ->and($readinessCheck)->toBeGreaterThan($refreshCarriers);
});
