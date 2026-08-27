<?php

it('uses an immutable-code opcache profile for production containers', function (): void {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $ini = file_get_contents(base_path('docker/php/production.ini'));
    $fpm = file_get_contents(base_path('docker/php-fpm/zz-production.conf'));

    expect($dockerfile)
        ->toContain('FROM app AS app-production')
        ->toContain('COPY docker/php/production.ini')
        ->toContain('COPY docker/php-fpm/zz-production.conf')
        ->and($ini)
        ->toContain('opcache.enable=1')
        ->toContain('opcache.enable_cli=1')
        ->toContain('opcache.validate_timestamps=0')
        ->toContain('realpath_cache_ttl=600')
        ->and($fpm)
        ->toContain('pm = ondemand')
        ->toContain('pm.max_children = 6')
        ->toContain('pm.max_requests = 500')
        ->toContain('php_admin_value[memory_limit] = 256M');
});

it('keeps production workers bounded and reduces idle polling', function (): void {
    $compose = file_get_contents(base_path('docker-compose.prod.yml'));
    $queueConfig = file_get_contents(base_path('config/queue.php'));
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($compose)
        ->toContain('target: app-production')
        ->toContain('--memory=256')
        ->toContain('--max-jobs=1000')
        ->toContain('php artisan schedule:work --no-interaction')
        ->toContain('--maxmemory-policy volatile-lru')
        ->and($queueConfig)
        ->toContain("'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5)")
        ->and($entrypoint)
        ->toContain('php artisan optimize:clear')
        ->toContain('php artisan optimize');
});

it('builds Laravel runtime caches and gates deployment on the runtime check', function (): void {
    $deploy = file_get_contents(base_path('scripts/deploy/aws-production.sh'));

    expect($deploy)
        ->toContain('php artisan optimize:clear')
        ->toContain('php artisan config:cache')
        ->toContain('php artisan route:cache')
        ->toContain('php artisan view:cache')
        ->toContain('php artisan event:cache')
        ->toContain('php artisan shop:check-runtime --json')
        ->not->toContain('php artisan event:cache || true');
});
