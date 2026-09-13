<?php

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function seoTargetValidationManifest(array $records): array
{
    return [
        'schema_version' => 1,
        'purpose' => 'test',
        'approved_product_count' => count($records),
        'approved_source_path_count' => array_sum(array_map(
            static fn (array $record): int => count($record['source_paths']),
            $records,
        )),
        'redirects_installed' => 0,
        'records' => $records,
    ];
}

function seoTargetValidationRecord(string $id, string $name, string $path, string $source): array
{
    return [
        'target_product_id' => $id,
        'target_product_name' => $name,
        'target_path' => $path,
        'target_product_status' => 'active',
        'matched_variant_status' => 'active',
        'decision' => 'APPROVE_301',
        'approved' => true,
        'source_paths' => [$source],
    ];
}

function writeSeoTargetValidationManifest(array $manifest): string
{
    $relative = 'storage/framework/testing/seo-target-validation-manifest.json';
    $absolute = base_path($relative);

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0775, true);
    }

    file_put_contents(
        $absolute,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    return $relative;
}

function seoTargetValidationHtml(string $name, string $canonical, ?string $robots = null): string
{
    $robotsMeta = $robots !== null
        ? '<meta name="robots" content="'.htmlspecialchars($robots, ENT_QUOTES).'">'
        : '';

    return '<!doctype html><html><head>'
        .$robotsMeta
        .'<link rel="canonical" href="'.htmlspecialchars($canonical, ENT_QUOTES).'">'
        .'</head><body><h1>'.htmlspecialchars($name, ENT_QUOTES).'</h1></body></html>';
}

function seoTargetValidationDecryptedCookieValue(Request $request, string $name): ?string
{
    $cookieHeader = $request->toPsrRequest()->getHeaderLine('Cookie');

    if (preg_match('/(?:^|;\\s*)'.preg_quote($name, '/').'=(?<value>[^;]+)/', $cookieHeader, $matches) !== 1) {
        return null;
    }

    $wireValue = rawurldecode(trim((string) $matches['value'], '"'));
    $encrypter = app(Encrypter::class);

    try {
        $decrypted = $encrypter->decrypt(
            $wireValue,
            EncryptCookies::serialized($name),
        );
    } catch (Throwable) {
        return null;
    }

    if (! is_string($decrypted)) {
        return null;
    }

    return CookieValuePrefix::validate($name, $decrypted, $encrypter->getAllKeys());
}

beforeEach(function (): void {
    config([
        'traffic_protection.enabled' => false,
    ]);
});

afterEach(function (): void {
    @unlink(base_path('storage/framework/testing/seo-target-validation-manifest.json'));
    @unlink(base_path('storage/framework/testing/seo-target-validation-report.json'));
});

it('passes only when every approved final target is a direct indexable self-canonical 200 with the expected product identity', function (): void {
    $records = [
        seoTargetValidationRecord('10', 'Produkt Alfa', '/products/produkt-alfa', '/legacy-alfa-id-10'),
        seoTargetValidationRecord('20', 'Produkt Beta', '/products/produkt-beta', '/legacy-beta-id-20'),
    ];
    $manifest = writeSeoTargetValidationManifest(seoTargetValidationManifest($records));

    Http::fake(function (Request $request) {
        return match ($request->url()) {
            'https://staging.example.test/products/produkt-alfa' => Http::response(
                seoTargetValidationHtml('Produkt Alfa', 'https://staging.example.test/products/produkt-alfa'),
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://staging.example.test/products/produkt-beta' => Http::response(
                seoTargetValidationHtml('Produkt Beta', '/products/produkt-beta'),
                200,
                ['Content-Type' => 'text/html'],
            ),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Approved target products:       2')
        ->and($output)->toContain('HTTP 200:                       2')
        ->and($output)->toContain('Canonical correct:              2')
        ->and($output)->toContain('Indexable:                      2')
        ->and($output)->toContain('Product identity correct:       2')
        ->and($output)->toContain('Human verification cookie used: NO')
        ->and($output)->toContain('Redirects enabled by this command: NO')
        ->and($output)->toContain('RESULT: PASS');

    $report = json_decode(
        (string) file_get_contents(base_path('storage/framework/testing/seo-target-validation-report.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['result'])->toBe('PASS')
        ->and($report['summary']['approved_target_products'])->toBe(2)
        ->and($report['summary']['http_200'])->toBe(2)
        ->and($report['summary']['canonical_correct'])->toBe(2)
        ->and($report['summary']['indexable'])->toBe(2)
        ->and($report['summary']['product_identity_correct'])->toBe(2)
        ->and($report['records'])->toHaveCount(2);

    Http::assertSentCount(2);
});

it('fails a redirected target without following the redirect', function (): void {
    $manifest = writeSeoTargetValidationManifest(seoTargetValidationManifest([
        seoTargetValidationRecord('10', 'Produkt Alfa', '/products/produkt-alfa', '/legacy-alfa-id-10'),
    ]));

    Http::fake([
        'https://staging.example.test/products/produkt-alfa' => Http::response('', 301, [
            'Location' => 'https://staging.example.test/products/inny-produkt',
        ]),
    ]);

    $exitCode = Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('HTTP 200:                       0')
        ->and($output)->toContain('Redirected targets:              1')
        ->and($output)->toContain('RESULT: FAIL');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://staging.example.test/products/produkt-alfa'
            && $request->method() === 'GET';
    });
});

it('fails 200 responses that are noindex, canonical-mismatched, or the wrong product', function (): void {
    $manifest = writeSeoTargetValidationManifest(seoTargetValidationManifest([
        seoTargetValidationRecord('10', 'Produkt Alfa', '/products/produkt-alfa', '/legacy-alfa-id-10'),
    ]));

    Http::fake([
        'https://staging.example.test/products/produkt-alfa' => Http::response(
            seoTargetValidationHtml(
                'Nie ten produkt',
                'https://staging.example.test/products/inny-produkt',
                'noindex, follow',
            ),
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $exitCode = Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('HTTP 200:                       1')
        ->and($output)->toContain('Canonical mismatches:            1')
        ->and($output)->toContain('Noindex targets:                 1')
        ->and($output)->toContain('Identity mismatches:              1')
        ->and($output)->toContain('RESULT: FAIL');
});

it('uses the existing signed human-verification cookie when traffic protection is enabled for the configured storefront host', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://staging.example.test',
        'session.domain' => null,
        'session.secure' => true,
        'traffic_protection.enabled' => true,
        'traffic_protection.human_cookie.name' => 'konji_human_verified',
        'traffic_protection.human_cookie.lifetime_minutes' => 60,
    ]);

    $manifest = writeSeoTargetValidationManifest(seoTargetValidationManifest([
        seoTargetValidationRecord('10', 'Produkt Alfa', '/products/produkt-alfa', '/legacy-alfa-id-10'),
    ]));

    Http::fake(function (Request $request) {
        $cookieValue = seoTargetValidationDecryptedCookieValue($request, 'konji_human_verified');

        if (! is_string($cookieValue) || ! str_starts_with($cookieValue, 'v1.')) {
            return Http::response('', 302, [
                'Location' => 'https://staging.example.test/human-check?return_to=%2Fproducts%2Fprodukt-alfa',
            ]);
        }

        return Http::response(
            seoTargetValidationHtml(
                'Produkt Alfa',
                'https://staging.example.test/products/produkt-alfa',
            ),
            200,
            ['Content-Type' => 'text/html'],
        );
    });

    $exitCode = Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('HTTP 200:                       1')
        ->and($output)->toContain('Human verification cookie used: YES')
        ->and($output)->toContain('RESULT: PASS');

    $report = json_decode(
        (string) file_get_contents(base_path('storage/framework/testing/seo-target-validation-report.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['human_verification_cookie_used'])->toBeTrue()
        ->and($report['human_verification_cookie_name'])->toBe('konji_human_verified');

    Http::assertSent(function (Request $request): bool {
        $cookieValue = seoTargetValidationDecryptedCookieValue($request, 'konji_human_verified');

        return $request->url() === 'https://staging.example.test/products/produkt-alfa'
            && is_string($cookieValue)
            && str_starts_with($cookieValue, 'v1.');
    });
});

it('refuses to send a signed human-verification cookie to a host different from configured APP_URL', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://trusted.example.test',
        'traffic_protection.enabled' => true,
    ]);

    Http::fake();

    $manifest = writeSeoTargetValidationManifest(seoTargetValidationManifest([
        seoTargetValidationRecord('10', 'Produkt Alfa', '/products/produkt-alfa', '/legacy-alfa-id-10'),
    ]));

    $exitCode = Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(
            'Traffic protection is enabled; --base-url host (staging.example.test) must match configured APP_URL host (trusted.example.test) before a signed human-verification cookie can be sent.'
        );

    Http::assertNothingSent();
});

it('rejects duplicate target paths before making network requests', function (): void {
    Http::fake();

    $manifest = writeSeoTargetValidationManifest([
        'schema_version' => 1,
        'approved_product_count' => 2,
        'approved_source_path_count' => 2,
        'redirects_installed' => 0,
        'records' => [
            seoTargetValidationRecord('10', 'Produkt Alfa', '/products/shared', '/legacy-alfa-id-10'),
            seoTargetValidationRecord('20', 'Produkt Beta', '/products/shared', '/legacy-beta-id-20'),
        ],
    ]);

    $exitCode = Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Duplicate/conflicting approved target path: /products/shared');

    Http::assertNothingSent();
});

it('requires an explicit base URL and never silently validates the configured app URL', function (): void {
    $manifest = writeSeoTargetValidationManifest(seoTargetValidationManifest([
        seoTargetValidationRecord('10', 'Produkt Alfa', '/products/produkt-alfa', '/legacy-alfa-id-10'),
    ]));

    expect(Artisan::call('seo:validate-approved-product-targets', [
        '--manifest' => $manifest,
        '--output' => 'storage/framework/testing/seo-target-validation-report.json',
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Option --base-url is required.');
});
