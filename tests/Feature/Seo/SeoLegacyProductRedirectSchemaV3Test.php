<?php

declare(strict_types=1);

use App\Console\Commands\GenerateLegacySeoProductRedirectMapCommand;
use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function seo03bV3Fixture(): array
{
    $originalPath = base_path(
        'resources/seo/ortezka/product-redirect-approvals.json'
    );

    $original = json_decode(
        (string) file_get_contents($originalPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $policy = app(ParentProductRedirectApprovalPolicy::class);
    $reviewed = array_values($policy->reviewedRecords());

    $records = [];

    foreach ($original['records'] as $record) {
        $record['approval_basis'] = 'exact_active_variant_sku_and_name';
        $records[] = $record;
    }

    foreach ($reviewed as $record) {
        // Synthetic approval metadata exists ONLY inside this test fixture.
        $record['approved'] = true;
        $record['decision'] = 'APPROVE_301';
        $record['approved_by'] = 'synthetic-phpunit-reviewer';
        $record['approved_at'] = '2026-10-03T18:00:00+02:00';
        $record['approval_reference'] = 'SEO-03B-TEST-NOT-A-REAL-APPROVAL';

        $records[] = $record;
    }

    return [
        'schema_version' => 3,
        'validation_only' => false,
        'redirects_installed' => 0,
        'product_count' => 45,
        'source_path_count' => 64,

        'original_manifest_sha256' => hash_file('sha256', $originalPath),

        'parent_review_sha256' => ParentProductRedirectApprovalPolicy::REVIEW_SHA256,

        'parent_validation_sha256' => ParentProductRedirectApprovalPolicy::VALIDATION_SHA256,

        'records' => $records,
    ];
}

function seo03bWriteV3Fixture(array $manifest): string
{
    $relative = 'storage/framework/testing/seo-03b-schema-v3-fixture.json';
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
        'storage/framework/testing/seo-03b-schema-v3-fixture.json'
    ));

    @unlink(base_path(
        'storage/framework/testing/seo-03b-schema-v3-candidate.conf'
    ));
});

it('generates exactly 64 synthetic schema v3 mappings without modifying the committed production map', function (): void {
    $manifest = seo03bV3Fixture();

    expect($manifest['records'])->toHaveCount(45);

    $manifestRelative = seo03bWriteV3Fixture($manifest);

    $outputRelative =
        'storage/framework/testing/seo-03b-schema-v3-candidate.conf';

    $outputPath = base_path($outputRelative);

    $productionMap = base_path(
        'docker/nginx/generated/legacy-seo-product-map.conf'
    );

    $productionHashBefore = hash_file('sha256', $productionMap);

    @unlink($outputPath);

    $exit = Artisan::call(
        'seo:generate-legacy-product-redirect-map',
        [
            '--manifest' => $manifestRelative,
            '--output' => $outputRelative,
        ],
    );

    if ($exit !== 0) {
        throw new RuntimeException(
            'Valid schema-v3 fixture rejected: '.Artisan::output()
        );
    }

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Approved source paths: 64')
        ->and(is_file($outputPath))->toBeTrue();

    $generated = (string) file_get_contents($outputPath);

    expect($generated)
        ->toContain('map $uri $legacy_seo_product_redirect_target {')
        ->toContain('default "";')
        ->toContain('# Source: '.$manifestRelative)
        ->and(substr_count($generated, ' "/products/'))->toBe(64);

    // Check every mapping, not merely the summary count.
    $allSources = [];

    foreach ($manifest['records'] as $record) {
        foreach ($record['source_paths'] as $source) {
            $allSources[] = $source;

            $expected = sprintf(
                '    "%s" "%s";',
                $source,
                $record['target_path'],
            );

            expect(substr_count($generated, $expected))->toBe(1);
        }
    }

    expect($allSources)->toHaveCount(64)
        ->and(array_unique($allSources))->toHaveCount(64)
        ->and(hash_file('sha256', $productionMap))
        ->toBe($productionHashBefore);
});

it('fails closed for invalid schema v3 approvals without overwriting the output map', function (): void {
    $cases = [
        'pending_parent',
        'missing_reviewer',
        'missing_approval_reference',
        'invalid_approval_timestamp',
        'changed_parent_identifier',
        'changed_parent_destination',
        'changed_parent_source_paths',
        'inactive_parent_variants',
        'changed_original_record',
        'missing_parent_record',
        'duplicate_parent_record',
        'incorrect_original_hash',
        'incorrect_review_hash',
        'incorrect_validation_hash',
        'validation_only',
        'unsupported_parent_basis',
        'source_collision',
        'redirect_chain',
    ];

    $outputRelative =
        'storage/framework/testing/seo-03b-schema-v3-candidate.conf';

    $outputPath = base_path($outputRelative);

    foreach ($cases as $case) {
        $manifest = seo03bV3Fixture();

        // Original products occupy 0..22.
        // Parent-product candidates occupy 23..44.
        switch ($case) {
            case 'pending_parent':
                $manifest['records'][23]['approved'] = false;
                $manifest['records'][23]['decision'] =
                    'PENDING_HUMAN_APPROVAL';
                break;

            case 'missing_reviewer':
                unset($manifest['records'][23]['approved_by']);
                break;

            case 'missing_approval_reference':
                unset($manifest['records'][23]['approval_reference']);
                break;

            case 'invalid_approval_timestamp':
                $manifest['records'][23]['approved_at'] = 'not-a-date';
                break;

            case 'changed_parent_identifier':
                $manifest['records'][23]['legacy_index'] = 'WRONG-SKU';
                break;

            case 'changed_parent_destination':
                $manifest['records'][23]['target_path'] =
                    '/products/unreviewed-destination';
                break;

            case 'changed_parent_source_paths':
                $manifest['records'][23]['source_paths'][] =
                    '/unreviewed-extra-source';
                break;

            case 'inactive_parent_variants':
                $manifest['records'][23]['active_variant_count'] = 0;
                break;

            case 'changed_original_record':
                $manifest['records'][0]['target_path'] =
                    '/products/incorrect-original-target';
                break;

            case 'missing_parent_record':
                array_pop($manifest['records']);
                break;

            case 'duplicate_parent_record':
                $manifest['records'][] = $manifest['records'][23];
                break;

            case 'incorrect_original_hash':
                $manifest['original_manifest_sha256'] = str_repeat('0', 64);
                break;

            case 'incorrect_review_hash':
                $manifest['parent_review_sha256'] = str_repeat('0', 64);
                break;

            case 'incorrect_validation_hash':
                $manifest['parent_validation_sha256'] = str_repeat('0', 64);
                break;

            case 'validation_only':
                $manifest['validation_only'] = true;
                break;

            case 'unsupported_parent_basis':
                $manifest['records'][23]['approval_basis'] =
                    'exact_name_only';
                break;

            case 'source_collision':
                $manifest['records'][23]['source_paths'][0] =
                    $manifest['records'][0]['source_paths'][0];
                break;

            case 'redirect_chain':
                $manifest['records'][0]['source_paths'][0] =
                    $manifest['records'][23]['target_path'];
                break;

            default:
                throw new RuntimeException('Unknown test case: '.$case);
        }

        $manifestRelative = seo03bWriteV3Fixture($manifest);

        // A failure must not publish a new map or overwrite an existing one.
        $sentinel = "DO_NOT_OVERWRITE\n";

        file_put_contents($outputPath, $sentinel);

        $exit = Artisan::call(
            'seo:generate-legacy-product-redirect-map',
            [
                '--manifest' => $manifestRelative,
                '--output' => $outputRelative,
            ],
        );

        $this->assertSame(
            1,
            $exit,
            'Generator unexpectedly accepted case: '.$case,
        );

        $this->assertSame(
            $sentinel,
            file_get_contents($outputPath),
            'Generator overwrote output for case: '.$case,
        );
    }
});

it('shares the strict schema v3 approval gate without generating an nginx map', function (): void {
    $validator = app(
        GenerateLegacySeoProductRedirectMapCommand::class,
    );

    $manifest = seo03bV3Fixture();

    $output = base_path(
        'storage/framework/testing/seo-03b-schema-v3-candidate.conf',
    );

    @unlink($output);

    $records = $validator->validatedSchemaV3Records($manifest);

    expect($records)->toHaveCount(45)
        ->and(is_file($output))->toBeFalse();

    $manifest['records'][23]['approved'] = false;
    $manifest['records'][23]['decision'] = 'PENDING_HUMAN_APPROVAL';

    expect(fn () => $validator->validatedSchemaV3Records($manifest))
        ->toThrow(RuntimeException::class);

    expect(is_file($output))->toBeFalse();
});

it('validates all 64 synthetic schema v3 runtime redirects without changing activation', function (): void {
    config(['traffic_protection.enabled' => false]);

    $manifest = seo03bV3Fixture();
    $manifestRelative = seo03bWriteV3Fixture($manifest);

    $sourceTargets = [];
    $targetNames = [];

    foreach ($manifest['records'] as $record) {
        $targetNames[$record['target_path']] = $record['target_product_name'];

        foreach ($record['source_paths'] as $source) {
            $sourceTargets[$source] = $record['target_path'];
        }
    }

    expect($sourceTargets)->toHaveCount(64)
        ->and($targetNames)->toHaveCount(45);

    Http::fake(
        static function (Request $request) use (
            $sourceTargets,
            $targetNames
        ) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/robots.txt') {
                return Http::response(
                    "User-agent: *\nAllow: /\n",
                    200,
                );
            }

            if (is_string($path) && isset($sourceTargets[$path])) {
                return Http::response(
                    '',
                    301,
                    [
                        'Location' => 'https://staging.example.test'.$sourceTargets[$path],
                    ],
                );
            }

            if (is_string($path) && isset($targetNames[$path])) {
                $name = htmlspecialchars(
                    $targetNames[$path],
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                );

                $canonical = 'https://staging.example.test'.$path;

                $html = '<!doctype html><html><head>'
                    .'<link rel="canonical" href="'.$canonical.'">'
                    .'</head><body><h1>'.$name.'</h1></body></html>';

                return Http::response(
                    $html,
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

    $reportRelative =
        'storage/framework/testing/seo-03b-v3-runtime-validation.json';

    try {
        $exit = Artisan::call(
            'seo:validate-legacy-product-redirect-runtime',
            [
                '--manifest' => $manifestRelative,
                '--base-url' => 'https://staging.example.test',
                '--output' => $reportRelative,
            ],
        );

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('RESULT: PASS');

        $report = json_decode(
            (string) file_get_contents(base_path($reportRelative)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($report['result'])->toBe('PASS')
            ->and($report['summary']['approved_source_paths'])->toBe(64)
            ->and($report['summary']['source_http_301'])->toBe(64)
            ->and($report['summary']['correct_destinations'])->toBe(64)
            ->and($report['summary']['query_strings_dropped'])->toBe(64)
            ->and($report['summary']['target_http_200'])->toBe(64)
            ->and($report['summary']['canonical_correct'])->toBe(64)
            ->and($report['summary']['indexable'])->toBe(64)
            ->and($report['summary']['product_identity_correct'])->toBe(64)
            ->and($report['summary']['redirect_chains'])->toBe(0)
            ->and($report['summary']['redirect_loops'])->toBe(0)
            ->and($report['control']['unchanged'])->toBeTrue()
            ->and($report['redirect_activation_changed_by_this_command'])
            ->toBeFalse();

        // 64 source requests + 64 destination requests + 1 control.
        Http::assertSentCount(129);
    } finally {
        @unlink(base_path($reportRelative));
    }
});

it('rejects invalid schema v3 runtime manifests before issuing HTTP requests', function (): void {
    config(['traffic_protection.enabled' => false]);

    Http::fake();

    $reportRelative =
        'storage/framework/testing/seo-03b-v3-runtime-validation.json';

    $reportPath = base_path($reportRelative);

    $cases = [
        'pending_parent',
        'changed_parent_source',
        'changed_original_target',
        'validation_only',
        'incorrect_provenance',
    ];

    try {
        foreach ($cases as $case) {
            $manifest = seo03bV3Fixture();

            switch ($case) {
                case 'pending_parent':
                    $manifest['records'][23]['approved'] = false;
                    $manifest['records'][23]['decision'] =
                        'PENDING_HUMAN_APPROVAL';
                    break;

                case 'changed_parent_source':
                    $manifest['records'][23]['source_paths'][0] =
                        '/unreviewed-legacy-source';
                    break;

                case 'changed_original_target':
                    $manifest['records'][0]['target_path'] =
                        '/products/incorrect-target';
                    break;

                case 'validation_only':
                    $manifest['validation_only'] = true;
                    break;

                case 'incorrect_provenance':
                    $manifest['parent_validation_sha256'] =
                        str_repeat('0', 64);
                    break;

                default:
                    throw new RuntimeException('Unknown case: '.$case);
            }

            $manifestRelative = seo03bWriteV3Fixture($manifest);

            $sentinel = "DO_NOT_OVERWRITE\n";

            file_put_contents($reportPath, $sentinel);

            $exit = Artisan::call(
                'seo:validate-legacy-product-redirect-runtime',
                [
                    '--manifest' => $manifestRelative,
                    '--base-url' => 'https://staging.example.test',
                    '--output' => $reportRelative,
                ],
            );

            $this->assertSame(
                1,
                $exit,
                'Invalid runtime manifest accepted: '.$case,
            );

            $this->assertSame(
                $sentinel,
                file_get_contents($reportPath),
                'Invalid manifest overwrote report: '.$case,
            );
        }

        Http::assertNothingSent();
    } finally {
        @unlink($reportPath);
    }
});
