<?php

declare(strict_types=1);

use App\Console\Commands\GenerateLegacySeoProductRedirectMapCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function seo07V5Manifest(): array
{
    return json_decode(
        (string) file_get_contents(base_path(
            'resources/seo/ortezka/review/seo-07f-20261007/'
            .'approved-659-manifest.json',
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function seo07WriteV5Fixture(array $manifest): string
{
    $relative =
        'storage/framework/testing/'
        .'seo-07-schema-v5-fixture.json';

    $absolute = base_path($relative);

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0775, true);
    }

    file_put_contents(
        $absolute,
        json_encode(
            $manifest,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ),
    );

    return $relative;
}

afterEach(function (): void {
    foreach ([
        'storage/framework/testing/'
            .'seo-07-schema-v5-fixture.json',

        'storage/framework/testing/'
            .'seo-07-schema-v5-candidate.conf',

        'storage/framework/testing/'
            .'seo-07-v5-target-validation.json',

        'storage/framework/testing/'
            .'seo-07-v5-runtime-validation.json',
    ] as $path) {
        @unlink(base_path($path));
    }
});

it('generates exactly 659 schema-v5 rules without modifying the committed production 659-rule map', function (): void {
    $manifest = seo07V5Manifest();

    expect($manifest['schema_version'])
        ->toBe(5)
        ->and($manifest['product_count'])
        ->toBe(415)
        ->and($manifest['source_path_count'])
        ->toBe(659)
        ->and($manifest['deployment_authorized'])
        ->toBeFalse()
        ->and($manifest['redirects_installed'])
        ->toBe(0)
        ->and($manifest['records'])
        ->toHaveCount(415);

    $productionMap = base_path(
        'docker/nginx/generated/'
        .'legacy-seo-product-map.conf',
    );

    $before = hash_file(
        'sha256',
        $productionMap,
    );

    expect($before)->toBe(
        '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e',
    );

    $relative = seo07WriteV5Fixture(
        $manifest,
    );

    $output =
        'storage/framework/testing/'
        .'seo-07-schema-v5-candidate.conf';

    $exit = Artisan::call(
        'seo:generate-legacy-product-redirect-map',
        [
            '--manifest' => $relative,
            '--output' => $output,
        ],
    );

    expect($exit)
        ->toBe(0)
        ->and(Artisan::output())
        ->toContain(
            'Approved source paths: 659',
        );

    $generated = (string) file_get_contents(
        base_path($output),
    );

    $matches = [];

    $count = preg_match_all(
        '/^    "([^"]+)" "([^"]+)";$/m',
        $generated,
        $matches,
        PREG_SET_ORDER,
    );

    expect($count)->toBe(659);

    $sources = [];
    $targets = [];

    foreach ($matches as $match) {
        expect(isset($sources[$match[1]]))
            ->toBeFalse();

        $sources[$match[1]] = $match[2];
        $targets[$match[2]] = true;
    }

    expect($sources)->toHaveCount(659)
        ->and($targets)->toHaveCount(415)
        ->and(array_intersect(
            array_keys($sources),
            array_keys($targets),
        ))->toBe([])
        ->and(hash_file(
            'sha256',
            $productionMap,
        ))->toBe($before);

    expect(preg_match_all(
        '/^    "([^"]+)" "([^"]+)";$/m',
        (string) file_get_contents(
            $productionMap,
        ),
    ))->toBe(659);
});

it('shares strict schema-v5 validation and fails closed on provenance or record drift', function (): void {
    $manifest = seo07V5Manifest();

    $validator = app(
        GenerateLegacySeoProductRedirectMapCommand::class,
    );

    expect(
        $validator->validatedSchemaV5Records(
            $manifest,
        ),
    )->toHaveCount(415);

    $cases = [
        'base_hash',
        'source_hash',
        'review_hash',
        'decision_hash',
        'staging_hash',
        'owner_reference',
        'deployment',
        'redirects_installed',
        'missing_record',
        'changed_source',
        'changed_target',
        'changed_decision',
    ];

    foreach ($cases as $case) {
        $changed = $manifest;

        switch ($case) {
            case 'base_hash':
                $changed['base_manifest_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'source_hash':
                $changed['exact_name_source_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'review_hash':
                $changed['exact_name_review_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'decision_hash':
                $changed['exact_name_decision_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'staging_hash':
                $changed['staging_validation_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'owner_reference':
                $changed['owner_decision_reference'] =
                    'INVALID';
                break;

            case 'deployment':
                $changed['deployment_authorized'] =
                    true;
                break;

            case 'redirects_installed':
                $changed['redirects_installed'] =
                    659;
                break;

            case 'missing_record':
                array_pop($changed['records']);
                break;

            case 'changed_source':
                $changed['records'][266]['source_paths'][0] =
                    '/unapproved-seo07-source';
                break;

            case 'changed_target':
                $changed['records'][266]['target_path'] =
                    '/products/unapproved-seo07-target';
                break;

            case 'changed_decision':
                $changed['records'][266]['decision'] =
                    'HOLD';
                break;
        }

        $relative = seo07WriteV5Fixture(
            $changed,
        );

        $output =
            'storage/framework/testing/'
            .'seo-07-schema-v5-candidate.conf';

        $sentinel = "DO_NOT_OVERWRITE\n";

        file_put_contents(
            base_path($output),
            $sentinel,
        );

        $exit = Artisan::call(
            'seo:generate-legacy-product-redirect-map',
            [
                '--manifest' => $relative,

                '--output' => $output,
            ],
        );

        expect($exit)
            ->toBe(1)
            ->and(file_get_contents(
                base_path($output),
            ))
            ->toBe($sentinel);
    }
});

it('validates all 415 schema-v5 targets through the shared approved-target gate', function (): void {
    config([
        'traffic_protection.enabled' => false,
    ]);

    $manifest = seo07V5Manifest();
    $targets = [];

    foreach ($manifest['records'] as $record) {
        $targets[$record['target_path']] =
            $record['target_product_name'];
    }

    expect($targets)->toHaveCount(415);

    Http::fake(
        static function (Request $request) use ($targets) {
            $path = parse_url(
                $request->url(),
                PHP_URL_PATH,
            );

            if (
                ! is_string($path)
                || ! isset($targets[$path])
            ) {
                return Http::response(
                    'Unexpected URL',
                    500,
                );
            }

            $name = htmlspecialchars(
                $targets[$path],
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );

            return Http::response(
                '<!doctype html><html><head>'
                .'<link rel="canonical" href="'
                .'https://staging.example.test'
                .$path
                .'"></head><body><h1>'
                .$name
                .'</h1></body></html>',
                200,
                [
                    'Content-Type' => 'text/html; charset=utf-8',
                ],
            );
        },
    );

    $report =
        'storage/framework/testing/'
        .'seo-07-v5-target-validation.json';

    $exit = Artisan::call(
        'seo:validate-approved-product-targets',
        [
            '--manifest' => 'resources/seo/ortezka/review/'
                .'seo-07f-20261007/'
                .'approved-659-manifest.json',

            '--base-url' => 'https://staging.example.test',

            '--output' => $report,
        ],
    );

    expect($exit)
        ->toBe(0)
        ->and(Artisan::output())
        ->toContain('RESULT: PASS');

    $data = json_decode(
        (string) file_get_contents(
            base_path($report),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(
        $data['summary']['approved_target_products'],
    )->toBe(415)
        ->and($data['summary']['http_200'])
        ->toBe(415)
        ->and(
            $data['summary']['canonical_correct'],
        )->toBe(415)
        ->and(
            $data['summary']['product_identity_correct'],
        )->toBe(415);

    Http::assertSentCount(415);
});

it('validates all 659 schema-v5 mappings through the shared runtime gate', function (): void {
    config([
        'traffic_protection.enabled' => false,
    ]);

    $manifest = seo07V5Manifest();

    $sourceTargets = [];
    $targetNames = [];

    foreach ($manifest['records'] as $record) {
        $targetNames[$record['target_path']] =
            $record['target_product_name'];

        foreach (
            $record['source_paths'] as $source
        ) {
            $sourceTargets[$source] =
                $record['target_path'];
        }
    }

    expect($sourceTargets)
        ->toHaveCount(659)
        ->and($targetNames)
        ->toHaveCount(415);

    Http::fake(
        static function (Request $request) use (
            $sourceTargets,
            $targetNames,
        ) {
            $path = parse_url(
                $request->url(),
                PHP_URL_PATH,
            );

            if ($path === '/robots.txt') {
                return Http::response(
                    "User-agent: *\nAllow: /\n",
                    200,
                );
            }

            if (
                is_string($path)
                && isset($sourceTargets[$path])
            ) {
                return Http::response(
                    '',
                    301,
                    [
                        'Location' => 'https://staging.example.test'
                            .$sourceTargets[$path],
                    ],
                );
            }

            if (
                is_string($path)
                && isset($targetNames[$path])
            ) {
                $name = htmlspecialchars(
                    $targetNames[$path],
                    ENT_QUOTES
                        | ENT_SUBSTITUTE,
                    'UTF-8',
                );

                return Http::response(
                    '<!doctype html><html><head>'
                    .'<link rel="canonical" href="'
                    .'https://staging.example.test'
                    .$path
                    .'"></head><body><h1>'
                    .$name
                    .'</h1></body></html>',
                    200,
                    [
                        'Content-Type' => 'text/html; charset=utf-8',
                    ],
                );
            }

            return Http::response(
                'Unexpected URL',
                500,
            );
        },
    );

    $report =
        'storage/framework/testing/'
        .'seo-07-v5-runtime-validation.json';

    $exit = Artisan::call(
        'seo:validate-legacy-product-redirect-runtime',
        [
            '--manifest' => 'resources/seo/ortezka/review/'
                .'seo-07f-20261007/'
                .'approved-659-manifest.json',

            '--base-url' => 'https://staging.example.test',

            '--output' => $report,
        ],
    );

    expect($exit)
        ->toBe(0)
        ->and(Artisan::output())
        ->toContain('RESULT: PASS');

    $data = json_decode(
        (string) file_get_contents(
            base_path($report),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(
        $data['summary']['approved_source_paths'],
    )->toBe(659)
        ->and(
            $data['summary']['source_http_301'],
        )->toBe(659)
        ->and(
            $data['summary']['correct_destinations'],
        )->toBe(659)
        ->and(
            $data['summary']['query_strings_dropped'],
        )->toBe(659)
        ->and(
            $data['summary']['target_http_200'],
        )->toBe(659)
        ->and(
            $data['summary']['canonical_correct'],
        )->toBe(659)
        ->and(
            $data['summary']['product_identity_correct'],
        )->toBe(659)
        ->and(
            $data['summary']['redirect_chains'],
        )->toBe(0)
        ->and(
            $data['summary']['redirect_loops'],
        )->toBe(0)
        ->and(
            $data['control']['unchanged'],
        )->toBeTrue();

    Http::assertSentCount(1319);
});
