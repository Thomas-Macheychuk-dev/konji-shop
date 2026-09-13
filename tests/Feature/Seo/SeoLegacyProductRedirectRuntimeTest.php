<?php

use Illuminate\Support\Facades\Artisan;

it('generates the committed nginx map deterministically from the approved manifest', function (): void {
    $temporaryRelative = 'storage/framework/testing/legacy-seo-product-map.conf';
    $temporaryPath = base_path($temporaryRelative);

    @unlink($temporaryPath);

    try {
        $exitCode = Artisan::call('seo:generate-legacy-product-redirect-map', [
            '--manifest' => 'resources/seo/ortezka/product-redirect-approvals.json',
            '--output' => $temporaryRelative,
        ]);

        expect($exitCode)->toBe(0)
            ->and(is_file($temporaryPath))->toBeTrue();

        $generated = (string) file_get_contents($temporaryPath);
        $committed = (string) file_get_contents(base_path('docker/nginx/generated/legacy-seo-product-map.conf'));

        expect($generated)->toBe($committed)
            ->and(substr_count($generated, '"/'))->toBeGreaterThanOrEqual(72)
            ->and(substr_count($generated, ' "/products/'))->toBe(36)
            ->and($generated)->toContain('map_hash_bucket_size 128;')
            ->and($generated)->toContain('map $uri $legacy_seo_product_redirect_target {')
            ->and($generated)->toContain('default "";');
    } finally {
        @unlink($temporaryPath);
    }
});

it('keeps the runtime redirect layer disabled by default and conditionally injectable', function (): void {
    $productionConfig = (string) file_get_contents(base_path('docker/nginx/production.conf'));
    $compose = (string) file_get_contents(base_path('docker-compose.prod.yml'));
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $environment = (string) file_get_contents(base_path('.env.production.example'));
    $startup = (string) file_get_contents(base_path('docker/nginx/start-production.sh'));
    $enabledSnippet = (string) file_get_contents(base_path('docker/nginx/legacy-seo/available/10-product-redirects-enabled.conf'));

    expect(substr_count($productionConfig, 'include /etc/nginx/legacy-seo/runtime/10-product-redirects.conf;'))->toBe(5)
        ->and($compose)->toContain('LEGACY_SEO_REDIRECTS_ENABLED: "${LEGACY_SEO_REDIRECTS_ENABLED:-false}"')
        ->and($compose)->toContain('command: sh -c "ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage && exec /usr/local/bin/konji-nginx-start"')
        ->and($environment)->toContain('LEGACY_SEO_REDIRECTS_ENABLED=false')
        ->and($dockerfile)->toContain('COPY docker/nginx/generated/legacy-seo-product-map.conf /etc/nginx/conf.d/00-legacy-seo-product-map.conf')
        ->and($dockerfile)->toContain('COPY docker/nginx/start-production.sh /usr/local/bin/konji-nginx-start')
        ->and($startup)->toContain('${LEGACY_SEO_REDIRECTS_ENABLED:-false}')
        ->and($startup)->not->toContain('ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage')
        ->and($startup)->toContain('nginx -t')
        ->and($productionConfig)->toContain('map $host $legacy_seo_redirect_origin {')
        ->and($enabledSnippet)->toContain('return 301 $legacy_seo_redirect_origin$legacy_seo_product_redirect_target;');
});

it('keeps approved sources direct and target paths product-only', function (): void {
    $manifest = json_decode(
        (string) file_get_contents(base_path('resources/seo/ortezka/product-redirect-approvals.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $sources = [];
    $targets = [];

    foreach ($manifest['records'] as $record) {
        expect($record['approved'])->toBeTrue()
            ->and($record['decision'])->toBe('APPROVE_301')
            ->and($record['target_path'])->toStartWith('/products/')
            ->and(str_contains($record['target_path'], '?'))->toBeFalse()
            ->and(str_contains($record['target_path'], '#'))->toBeFalse();

        $targets[] = $record['target_path'];

        foreach ($record['source_paths'] as $sourcePath) {
            expect(str_contains($sourcePath, '?'))->toBeFalse()
                ->and(str_contains($sourcePath, '#'))->toBeFalse()
                ->and($sourcePath)->not->toBe($record['target_path']);

            $sources[] = $sourcePath;
        }
    }

    expect($sources)->toHaveCount(36)
        ->and(array_unique($sources))->toHaveCount(36)
        ->and(array_intersect($sources, $targets))->toBe([]);
});
