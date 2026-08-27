<?php

use App\Services\Operations\ProductionRuntimeReadiness;

it('accepts the production runtime baseline', function (): void {
    $readiness = new ProductionRuntimeReadiness;

    $items = $readiness->evaluate([
        'environment' => 'production',
        'debug' => false,
        'config_cached' => true,
        'routes_cached' => true,
        'compiled_views' => 120,
        'opcache_enabled' => true,
        'opcache_cli_enabled' => true,
        'opcache_validate_timestamps' => false,
        'cache_store' => 'redis',
        'queue_connection' => 'redis',
        'session_driver' => 'redis',
        'log_channel' => 'stderr',
        'log_level' => 'warning',
    ]);

    expect($readiness->isReady($items))->toBeTrue()
        ->and(collect($items)->pluck('status')->unique()->all())->toBe(['PASS']);
});

it('fails unsafe or expensive production runtime settings', function (): void {
    $readiness = new ProductionRuntimeReadiness;

    $items = $readiness->evaluate([
        'environment' => 'production',
        'debug' => true,
        'config_cached' => false,
        'routes_cached' => false,
        'compiled_views' => 0,
        'opcache_enabled' => false,
        'opcache_cli_enabled' => false,
        'opcache_validate_timestamps' => true,
        'cache_store' => 'database',
        'queue_connection' => 'database',
        'session_driver' => 'database',
        'log_channel' => 'single',
        'log_level' => 'debug',
    ]);

    expect($readiness->isReady($items))->toBeFalse()
        ->and(collect($items)->where('status', 'FAIL')->count())->toBeGreaterThanOrEqual(10);
});
