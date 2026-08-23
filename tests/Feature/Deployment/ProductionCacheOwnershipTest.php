<?php

declare(strict_types=1);

it('gives the production deploy script sole ownership of app bootstrap caches', function (): void {
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));
    $compose = file_get_contents(base_path('docker-compose.prod.yml'));
    $deploy = file_get_contents(base_path('scripts/deploy/aws-production.sh'));

    expect($entrypoint)
        ->toContain('LARAVEL_CACHE_ON_BOOT')
        ->toContain('Skipping Laravel cache refresh on container start')
        ->toContain('php artisan route:cache');

    expect($compose)
        ->toContain('LARAVEL_CACHE_ON_BOOT: "false"');

    expect($deploy)
        ->toContain('App entrypoint cache refresh is disabled in production; deploy owns bootstrap/cache.')
        ->toContain('php artisan optimize:clear')
        ->toContain('php artisan config:cache')
        ->toContain('php artisan route:cache')
        ->toContain('php artisan view:cache');
});
