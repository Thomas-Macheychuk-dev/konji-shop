<?php

declare(strict_types=1);

use App\Console\Commands\GenerateLegacySeoProductRedirectMapCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function seo06V4Manifest(): array
{
    return json_decode(
        (string) file_get_contents(base_path(
            'resources/seo/ortezka/review/seo-06c-20261006/'
            .'approved-400-manifest.json',
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function seo06WriteV4Fixture(array $manifest): string
{
    $relative =
        'storage/framework/testing/seo-06-schema-v4-fixture.json';

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
    @unlink(base_path(
        'storage/framework/testing/seo-06-schema-v4-fixture.json',
    ));

    @unlink(base_path(
        'storage/framework/testing/seo-06-schema-v4-candidate.conf',
    ));
});

it('validates and generates exactly 400 schema-v4 rules without modifying the committed production 400-rule map', function (): void {
    $manifest = seo06V4Manifest();

    expect($manifest['schema_version'])->toBe(4)
        ->and($manifest['product_count'])->toBe(266)
        ->and($manifest['source_path_count'])->toBe(400)
        ->and($manifest['deployment_authorized'])->toBeFalse()
        ->and($manifest['redirects_installed'])->toBe(0)
        ->and($manifest['records'])->toHaveCount(266);

    $productionMap = base_path(
        'docker/nginx/generated/legacy-seo-product-map.conf',
    );

    $productionHashBefore = hash_file(
        'sha256',
        $productionMap,
    );

    $relative = seo06WriteV4Fixture($manifest);

    $outputRelative =
        'storage/framework/testing/seo-06-schema-v4-candidate.conf';

    $exit = Artisan::call(
        'seo:generate-legacy-product-redirect-map',
        [
            '--manifest' => $relative,
            '--output' => $outputRelative,
        ],
    );

    if ($exit !== 0) {
        throw new RuntimeException(
            'Valid schema-v4 candidate rejected: '.Artisan::output(),
        );
    }

    expect($exit)->toBe(0)
        ->and(Artisan::output())
        ->toContain('Approved source paths: 400');

    $generated = (string) file_get_contents(
        base_path($outputRelative),
    );

    $matches = [];

    $ruleCount = preg_match_all(
        '/^    "([^"]+)" "([^"]+)";$/m',
        $generated,
        $matches,
        PREG_SET_ORDER,
    );

    expect($ruleCount)->toBe(400);

    $sources = [];
    $targets = [];

    foreach ($matches as $match) {
        expect(isset($sources[$match[1]]))->toBeFalse();

        $sources[$match[1]] = $match[2];
        $targets[$match[2]] = true;
    }

    expect($sources)->toHaveCount(400)
        ->and($targets)->toHaveCount(266)
        ->and(array_intersect(
            array_keys($sources),
            array_keys($targets),
        ))->toBe([])
        ->and(hash_file('sha256', $productionMap))
        ->toBe($productionHashBefore);

    $productionMapContents = (string) file_get_contents(
        $productionMap,
    );

    expect(preg_match_all(
        '/^    "([^"]+)" "([^"]+)";$/m',
        $productionMapContents,
    ))->toBe(400);
});

it('shares strict schema-v4 validation without generating a map', function (): void {
    $manifest = seo06V4Manifest();

    $validator = app(
        GenerateLegacySeoProductRedirectMapCommand::class,
    );

    $records = $validator->validatedSchemaV4Records(
        $manifest,
    );

    expect($records)->toHaveCount(266);
});

it('fails closed when schema-v4 provenance or frozen records are changed', function (): void {
    $cases = [
        'review_hash',
        'decision_hash',
        'base_hash',
        'deployment_authorized',
        'missing_record',
        'changed_target',
        'changed_source',
        'changed_decision',
    ];

    $outputRelative =
        'storage/framework/testing/seo-06-schema-v4-candidate.conf';

    $output = base_path($outputRelative);

    foreach ($cases as $case) {
        $manifest = seo06V4Manifest();

        switch ($case) {
            case 'review_hash':
                $manifest['semantic_support_review_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'decision_hash':
                $manifest['semantic_support_decision_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'base_hash':
                $manifest['base_manifest_sha256'] =
                    str_repeat('0', 64);
                break;

            case 'deployment_authorized':
                $manifest['deployment_authorized'] = true;
                break;

            case 'missing_record':
                array_pop($manifest['records']);
                break;

            case 'changed_target':
                $manifest['records'][45]['target_path'] =
                    '/products/unapproved-target';
                break;

            case 'changed_source':
                $manifest['records'][45]['source_paths'][0] =
                    '/unapproved-legacy-source';
                break;

            case 'changed_decision':
                $manifest['records'][45]['decision'] = 'HOLD';
                break;

            default:
                throw new RuntimeException(
                    'Unknown schema-v4 test case: '.$case,
                );
        }

        $relative = seo06WriteV4Fixture($manifest);

        $sentinel = "DO_NOT_OVERWRITE\n";
        file_put_contents($output, $sentinel);

        $exit = Artisan::call(
            'seo:generate-legacy-product-redirect-map',
            [
                '--manifest' => $relative,
                '--output' => $outputRelative,
            ],
        );

        $this->assertSame(
            1,
            $exit,
            'Invalid schema-v4 candidate accepted: '.$case,
        );

        $this->assertSame(
            $sentinel,
            file_get_contents($output),
            'Invalid schema-v4 candidate overwrote output: '.$case,
        );
    }
});

it('validates all 266 schema-v4 targets through the shared approval gate', function (): void {
    config(['traffic_protection.enabled' => false]);

    $manifest = seo06V4Manifest();
    $targets = [];

    foreach ($manifest['records'] as $record) {
        $targets[$record['target_path']] =
            $record['target_product_name'];
    }

    expect($targets)->toHaveCount(266);

    Http::fake(
        static function (Request $request) use ($targets) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (! is_string($path) || ! isset($targets[$path])) {
                return Http::response('Unexpected URL', 500);
            }

            $name = htmlspecialchars(
                $targets[$path],
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );

            $canonical =
                'https://staging.example.test'.$path;

            return Http::response(
                '<!doctype html><html><head>'
                .'<link rel="canonical" href="'.$canonical.'">'
                .'</head><body><h1>'.$name.'</h1></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        },
    );

    $report =
        'storage/framework/testing/seo-06-v4-target-validation.json';

    try {
        $exit = Artisan::call(
            'seo:validate-approved-product-targets',
            [
                '--manifest' => 'resources/seo/ortezka/review/'
                    .'seo-06c-20261006/approved-400-manifest.json',
                '--base-url' => 'https://staging.example.test',
                '--output' => $report,
            ],
        );

        expect($exit)->toBe(0)
            ->and(Artisan::output())
            ->toContain('RESULT: PASS');

        $data = json_decode(
            (string) file_get_contents(base_path($report)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($data['result'])->toBe('PASS')
            ->and($data['summary']['approved_target_products'])
            ->toBe(266)
            ->and($data['summary']['http_200'])
            ->toBe(266)
            ->and($data['summary']['canonical_correct'])
            ->toBe(266)
            ->and($data['summary']['indexable'])
            ->toBe(266)
            ->and($data['summary']['product_identity_correct'])
            ->toBe(266);

        Http::assertSentCount(266);
    } finally {
        @unlink(base_path($report));
    }
});

it('validates all 400 schema-v4 redirects through the shared approval gate', function (): void {
    config(['traffic_protection.enabled' => false]);

    $manifest = seo06V4Manifest();

    $sourceTargets = [];
    $targetNames = [];

    foreach ($manifest['records'] as $record) {
        $targetNames[$record['target_path']] =
            $record['target_product_name'];

        foreach ($record['source_paths'] as $source) {
            $sourceTargets[$source] =
                $record['target_path'];
        }
    }

    expect($sourceTargets)->toHaveCount(400)
        ->and($targetNames)->toHaveCount(266);

    Http::fake(
        static function (Request $request) use (
            $sourceTargets,
            $targetNames,
        ) {
            $path = parse_url($request->url(), PHP_URL_PATH);

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
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                );

                $canonical =
                    'https://staging.example.test'.$path;

                return Http::response(
                    '<!doctype html><html><head>'
                    .'<link rel="canonical" href="'
                    .$canonical
                    .'"></head><body><h1>'
                    .$name
                    .'</h1></body></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                );
            }

            return Http::response(
                'Unexpected URL',
                500,
            );
        },
    );

    $report =
        'storage/framework/testing/seo-06-v4-runtime-validation.json';

    try {
        $exit = Artisan::call(
            'seo:validate-legacy-product-redirect-runtime',
            [
                '--manifest' => 'resources/seo/ortezka/review/'
                    .'seo-06c-20261006/approved-400-manifest.json',
                '--base-url' => 'https://staging.example.test',
                '--output' => $report,
            ],
        );

        expect($exit)->toBe(0)
            ->and(Artisan::output())
            ->toContain('RESULT: PASS');

        $data = json_decode(
            (string) file_get_contents(base_path($report)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($data['result'])->toBe('PASS')
            ->and($data['summary']['approved_source_paths'])
            ->toBe(400)
            ->and($data['summary']['source_http_301'])
            ->toBe(400)
            ->and($data['summary']['correct_destinations'])
            ->toBe(400)
            ->and($data['summary']['query_strings_dropped'])
            ->toBe(400)
            ->and($data['summary']['target_http_200'])
            ->toBe(400)
            ->and($data['summary']['canonical_correct'])
            ->toBe(400)
            ->and($data['summary']['indexable'])
            ->toBe(400)
            ->and($data['summary']['product_identity_correct'])
            ->toBe(400)
            ->and($data['summary']['redirect_chains'])
            ->toBe(0)
            ->and($data['summary']['redirect_loops'])
            ->toBe(0)
            ->and($data['control']['unchanged'])
            ->toBeTrue();

        Http::assertSentCount(801);
    } finally {
        @unlink(base_path($report));
    }
});
