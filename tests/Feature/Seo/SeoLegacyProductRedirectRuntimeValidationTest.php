<?php

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function seoRuntimeManifest(array $records): array
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

function seoRuntimeRecord(string $id, string $name, string $path, array $sources): array
{
    return [
        'target_product_id' => $id,
        'target_product_name' => $name,
        'target_path' => $path,
        'target_product_status' => 'active',
        'matched_variant_status' => 'active',
        'decision' => 'APPROVE_301',
        'approved' => true,
        'source_paths' => $sources,
    ];
}

function writeSeoRuntimeManifest(array $manifest): string
{
    $relative = 'storage/framework/testing/seo-runtime-validation-manifest.json';
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

function seoRuntimeHtml(string $name, string $canonical, ?string $robots = null): string
{
    $robotsMeta = $robots !== null
        ? '<meta name="robots" content="'.htmlspecialchars($robots, ENT_QUOTES).'">'
        : '';

    return '<!doctype html><html><head>'
        .$robotsMeta
        .'<link rel="canonical" href="'.htmlspecialchars($canonical, ENT_QUOTES).'">'
        .'</head><body><h1>'.htmlspecialchars($name, ENT_QUOTES).'</h1></body></html>';
}

function seoRuntimeDecryptedCookieValue(Request $request, string $name): ?string
{
    $cookieHeader = $request->toPsrRequest()->getHeaderLine('Cookie');

    if (preg_match('/(?:^|;\\s*)'.preg_quote($name, '/').'=(?<value>[^;]+)/', $cookieHeader, $matches) !== 1) {
        return null;
    }

    $wireValue = rawurldecode(trim((string) $matches['value'], '"'));
    $encrypter = app(Encrypter::class);

    try {
        $decrypted = $encrypter->decrypt($wireValue, EncryptCookies::serialized($name));
    } catch (Throwable) {
        return null;
    }

    if (! is_string($decrypted)) {
        return null;
    }

    return CookieValuePrefix::validate($name, $decrypted, $encrypter->getAllKeys());
}

beforeEach(function (): void {
    config(['traffic_protection.enabled' => false]);
});

afterEach(function (): void {
    @unlink(base_path('storage/framework/testing/seo-runtime-validation-manifest.json'));
    @unlink(base_path('storage/framework/testing/seo-runtime-validation-report.json'));
});

it('passes only when every source is exactly one 301 to its expected direct indexable product target and query strings are dropped', function (): void {
    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', [
            '/legacy-alfa-id-10',
            '/legacy-alfa-old-id-10',
        ]),
        seoRuntimeRecord('20', 'Produkt Beta', '/products/produkt-beta', ['/legacy-beta-id-20']),
    ]));

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/legacy-alfa')) {
            return Http::response('', 301, ['Location' => 'https://staging.example.test/products/produkt-alfa']);
        }

        if (str_contains($url, '/legacy-beta')) {
            return Http::response('', 301, ['Location' => 'https://staging.example.test/products/produkt-beta']);
        }

        return match ($url) {
            'https://staging.example.test/products/produkt-alfa' => Http::response(
                seoRuntimeHtml('Produkt Alfa', 'https://staging.example.test/products/produkt-alfa'),
                200,
            ),
            'https://staging.example.test/products/produkt-beta' => Http::response(
                seoRuntimeHtml('Produkt Beta', '/products/produkt-beta'),
                200,
            ),
            'https://staging.example.test/robots.txt?__konji_seo_redirect_control=1' => Http::response('User-agent: *', 200),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Approved legacy source paths:   3')
        ->and($output)->toContain('Source HTTP 301:                 3')
        ->and($output)->toContain('Correct destinations:            3')
        ->and($output)->toContain('Query strings dropped:           3')
        ->and($output)->toContain('Final target HTTP 200:            3')
        ->and($output)->toContain('Redirect chains:                  0')
        ->and($output)->toContain('Redirect loops:                   0')
        ->and($output)->toContain('Unrelated control failures:       0')
        ->and($output)->toContain('Redirect activation changed by this command: NO')
        ->and($output)->toContain('RESULT: PASS');

    $report = json_decode(
        (string) file_get_contents(base_path('storage/framework/testing/seo-runtime-validation-report.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['result'])->toBe('PASS')
        ->and($report['summary']['approved_source_paths'])->toBe(3)
        ->and($report['summary']['source_http_301'])->toBe(3)
        ->and($report['summary']['target_http_200'])->toBe(3)
        ->and($report['control']['unchanged'])->toBeTrue()
        ->and($report['control']['location'])->toBeNull()
        ->and($report['redirect_activation_changed_by_this_command'])->toBeFalse();

    Http::assertSentCount(7);
});

it('fails wrong destinations and preserved source query strings', function (): void {
    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/legacy-alfa-id-10') => Http::response('', 301, [
                'Location' => 'https://staging.example.test/products/wrong?__konji_seo_redirect_probe=1',
            ]),
            str_contains($request->url(), '/robots.txt') => Http::response('ok', 200),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Wrong destinations:               1')
        ->and($output)->toContain('Query strings preserved:          1')
        ->and($output)->toContain('RESULT: FAIL');
});

it('counts a 301 without a Location header as a missing redirect destination', function (): void {
    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/legacy-alfa-id-10') => Http::response('', 301),
            str_contains($request->url(), '/robots.txt') => Http::response('ok', 200),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Missing Location headers:         1')
        ->and($output)->toContain('Wrong destinations:               1')
        ->and($output)->toContain('RESULT: FAIL');
});

it('fails a redirect chain instead of following the final target redirect', function (): void {
    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/legacy-alfa-id-10') => Http::response('', 301, [
                'Location' => 'https://staging.example.test/products/produkt-alfa',
            ]),
            $request->url() === 'https://staging.example.test/products/produkt-alfa' => Http::response('', 302, [
                'Location' => 'https://staging.example.test/products/produkt-alfa-2',
            ]),
            str_contains($request->url(), '/robots.txt') => Http::response('ok', 200),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Redirect chains:                  1')
        ->and($output)->toContain('Final target HTTP 200:            0')
        ->and($output)->toContain('RESULT: FAIL');
});

it('fails a final target that is noindex, canonical-mismatched, or the wrong product', function (): void {
    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/legacy-alfa-id-10') => Http::response('', 301, [
                'Location' => 'https://staging.example.test/products/produkt-alfa',
            ]),
            $request->url() === 'https://staging.example.test/products/produkt-alfa' => Http::response(
                seoRuntimeHtml('Inny produkt', 'https://staging.example.test/products/inny-produkt', 'noindex'),
                200,
            ),
            str_contains($request->url(), '/robots.txt') => Http::response('ok', 200),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Canonical mismatches:             1')
        ->and($output)->toContain('Noindex targets:                  1')
        ->and($output)->toContain('Identity mismatches:               1')
        ->and($output)->toContain('RESULT: FAIL');
});

it('fails when the unrelated control URL is redirected or otherwise changed', function (): void {
    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/legacy-alfa-id-10') => Http::response('', 301, [
                'Location' => 'https://staging.example.test/products/produkt-alfa',
            ]),
            $request->url() === 'https://staging.example.test/products/produkt-alfa' => Http::response(
                seoRuntimeHtml('Produkt Alfa', 'https://staging.example.test/products/produkt-alfa'),
                200,
            ),
            str_contains($request->url(), '/robots.txt') => Http::response('', 301, [
                'Location' => 'https://staging.example.test/products/produkt-alfa',
            ]),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Unrelated control failures:       1')
        ->and($output)->toContain('RESULT: FAIL');
});

it('uses the encrypted signed human-verification cookie for the configured protected storefront host', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://staging.example.test',
        'session.domain' => null,
        'session.secure' => true,
        'traffic_protection.enabled' => true,
        'traffic_protection.human_cookie.name' => 'konji_human_verified',
        'traffic_protection.human_cookie.lifetime_minutes' => 60,
    ]);

    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    Http::fake(function (Request $request) {
        $cookie = seoRuntimeDecryptedCookieValue($request, 'konji_human_verified');

        if (! is_string($cookie) || ! str_starts_with($cookie, 'v1.')) {
            return Http::response('', 302, ['Location' => 'https://staging.example.test/human-check']);
        }

        return match (true) {
            str_contains($request->url(), '/legacy-alfa-id-10') => Http::response('', 301, [
                'Location' => 'https://staging.example.test/products/produkt-alfa',
            ]),
            $request->url() === 'https://staging.example.test/products/produkt-alfa' => Http::response(
                seoRuntimeHtml('Produkt Alfa', 'https://staging.example.test/products/produkt-alfa'),
                200,
            ),
            str_contains($request->url(), '/robots.txt') => Http::response('ok', 200),
            default => Http::response('unexpected', 500),
        };
    });

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Human verification cookie used: YES')
        ->and($output)->toContain('RESULT: PASS');

    $report = json_decode(
        (string) file_get_contents(base_path('storage/framework/testing/seo-runtime-validation-report.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['human_verification_cookie_used'])->toBeTrue()
        ->and($report['human_verification_cookie_name'])->toBe('konji_human_verified')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('v1.');
});

it('refuses to send the signed human-verification cookie to a host different from APP_URL', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://trusted.example.test',
        'traffic_protection.enabled' => true,
    ]);

    Http::fake();

    $manifest = writeSeoRuntimeManifest(seoRuntimeManifest([
        seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/legacy-alfa-id-10']),
    ]));

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('--base-url host (staging.example.test) must match configured APP_URL host (trusted.example.test)');

    Http::assertNothingSent();
});

it('rejects duplicate approved source paths before making network requests', function (): void {
    Http::fake();

    $manifest = writeSeoRuntimeManifest([
        'schema_version' => 1,
        'purpose' => 'test',
        'approved_product_count' => 2,
        'approved_source_path_count' => 2,
        'redirects_installed' => 0,
        'records' => [
            seoRuntimeRecord('10', 'Produkt Alfa', '/products/produkt-alfa', ['/same-source']),
            seoRuntimeRecord('20', 'Produkt Beta', '/products/produkt-beta', ['/same-source']),
        ],
    ]);

    $exitCode = Artisan::call('seo:validate-legacy-product-redirect-runtime', [
        '--manifest' => $manifest,
        '--base-url' => 'https://staging.example.test',
        '--output' => 'storage/framework/testing/seo-runtime-validation-report.json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Duplicate/conflicting approved redirect source path: /same-source');

    Http::assertNothingSent();
});
