<?php

declare(strict_types=1);

use App\Console\Commands\GenerateLegacySeoProductRedirectMapCommand;
use Illuminate\Support\Facades\Artisan;

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

it('validates and generates exactly 400 schema-v4 rules without modifying the production 64-rule map', function (): void {
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
    ))->toBe(64);
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
