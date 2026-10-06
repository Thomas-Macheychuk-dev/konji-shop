<?php

use Illuminate\Support\Facades\Artisan;

it('keeps the committed production map exactly bound to the frozen approved-58 manifest', function (): void {
    $manifestRelative =
        'resources/seo/ortezka/review/'
        .'seo-03b-p4-20261003/approved-58-manifest.json';

    $manifestPath = base_path($manifestRelative);

    $rawManifest = (string) file_get_contents($manifestPath);

    $manifest = json_decode(
        $rawManifest,
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['product_count'])->toBe(41)
        ->and($manifest['source_path_count'])->toBe(58);

    $expected = [];

    foreach ($manifest['records'] as $record) {
        expect($record['approved'])->toBeTrue()
            ->and($record['decision'])->toBe('APPROVE_301');

        foreach ($record['source_paths'] as $source) {
            expect(array_key_exists($source, $expected))->toBeFalse();

            $expected[$source] = $record['target_path'];
        }
    }

    expect($expected)->toHaveCount(58);

    $committed = (string) file_get_contents(base_path(
        'docker/nginx/generated/legacy-seo-product-map.conf',
    ));

    $matches = [];

    $count = preg_match_all(
        '/^    "([^"]+)" "([^"]+)";$/m',
        $committed,
        $matches,
        PREG_SET_ORDER,
    );

    expect($count)->toBe(58);

    $actual = [];

    foreach ($matches as $match) {
        expect(array_key_exists($match[1], $actual))->toBeFalse();

        $actual[$match[1]] = $match[2];
    }

    ksort($expected);
    ksort($actual);

    expect($actual)->toBe($expected)
        ->and($committed)
        ->toContain('# Source: '.$manifestRelative)
        ->toContain(
            '# Manifest SHA-256: '.hash('sha256', $rawManifest),
        )
        ->toContain('map_hash_bucket_size 128;')
        ->toContain('map $uri $legacy_seo_product_redirect_target {')
        ->toContain('default "";');
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

it('allows an explicitly approved schema v2 exact-identity product even when its historical matched variant is not active', function (): void {
    $manifestRelative = 'storage/framework/testing/schema-v2-product-redirect-approvals.json';
    $manifestPath = base_path($manifestRelative);
    $outputRelative = 'storage/framework/testing/schema-v2-product-redirect-map.conf';
    $outputPath = base_path($outputRelative);

    if (! is_dir(dirname($manifestPath))) {
        mkdir(dirname($manifestPath), 0775, true);
    }

    $manifest = [
        'schema_version' => 2,
        'validation_only' => false,
        'product_count' => 2,
        'source_path_count' => 2,
        'redirects_installed' => 0,
        'records' => [
            [
                'target_product_id' => '100',
                'target_product_name' => 'Produkt Alfa',
                'target_path' => '/products/produkt-alfa',
                'target_product_status' => 'active',
                'target_storefront_reachable' => true,
                'matched_variant_status' => 'draft',
                'approval_basis' => 'exact_identifier_and_name',
                'decision' => 'APPROVE_301',
                'approved' => true,
                'source_paths' => ['/produkt-alfa-id-10'],
            ],
            [
                'target_product_id' => '200',
                'target_product_name' => 'Produkt Beta',
                'target_path' => '/products/produkt-beta',
                'target_product_status' => 'active',
                'target_storefront_reachable' => true,
                'matched_variant_status' => null,
                'approval_basis' => 'exact_identifier_and_name',
                'decision' => 'APPROVE_301',
                'approved' => true,
                'source_paths' => ['/produkt-beta-id-20'],
            ],
        ],
    ];

    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    @unlink($outputPath);

    try {
        expect(Artisan::call('seo:generate-legacy-product-redirect-map', [
            '--manifest' => $manifestRelative,
            '--output' => $outputRelative,
        ]))->toBe(0);

        $generated = (string) file_get_contents($outputPath);

        expect($generated)
            ->toContain('"/produkt-alfa-id-10" "/products/produkt-alfa";')
            ->toContain('"/produkt-beta-id-20" "/products/produkt-beta";');
    } finally {
        @unlink($manifestPath);
        @unlink($outputPath);
    }
});

it('refuses to generate runtime redirects from a schema v2 validation-only manifest', function (): void {
    $manifestRelative = 'storage/framework/testing/schema-v2-validation-only.json';
    $manifestPath = base_path($manifestRelative);

    if (! is_dir(dirname($manifestPath))) {
        mkdir(dirname($manifestPath), 0775, true);
    }

    file_put_contents($manifestPath, json_encode([
        'schema_version' => 2,
        'validation_only' => true,
        'product_count' => 1,
        'source_path_count' => 1,
        'redirects_installed' => 0,
        'records' => [[
            'target_product_id' => '100',
            'target_product_name' => 'Produkt Alfa',
            'target_path' => '/products/produkt-alfa',
            'target_product_status' => 'active',
            'target_storefront_reachable' => true,
            'approval_basis' => 'exact_identifier_and_name',
            'decision' => 'VALIDATE_301_CANDIDATE',
            'approved' => false,
            'source_paths' => ['/produkt-alfa-id-10'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        expect(Artisan::call('seo:generate-legacy-product-redirect-map', [
            '--manifest' => $manifestRelative,
            '--output' => 'storage/framework/testing/should-not-exist.conf',
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('Validation-only schema v2 manifests cannot generate runtime redirects.');
    } finally {
        @unlink($manifestPath);
        @unlink(base_path('storage/framework/testing/should-not-exist.conf'));
    }
});
