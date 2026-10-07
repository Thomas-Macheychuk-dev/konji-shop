<?php

declare(strict_types=1);

use App\Console\Commands\ValidateExactNameOnlySeoTargetsCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function seo07FrozenManifest(): array
{
    return json_decode(
        (string) file_get_contents(
            base_path(
                ValidateExactNameOnlySeoTargetsCommand::MANIFEST,
            ),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function seo07TargetHtml(
    string $name,
    string $canonical,
    ?string $robots = null,
): string {
    $robotsMeta = $robots !== null
        ? '<meta name="robots" content="'.htmlspecialchars($robots, ENT_QUOTES).'">'
        : '';

    return '<!doctype html><html><head>'
        .$robotsMeta
        .'<link rel="canonical" href="'.htmlspecialchars($canonical, ENT_QUOTES).'">'
        .'</head><body><h1>'
        .htmlspecialchars($name, ENT_QUOTES)
        .'</h1></body></html>';
}

/**
 * @return array<string, array<string, mixed>>
 */
function seo07RecordsByTargetPath(): array
{
    $manifest = seo07FrozenManifest();
    $records = [];

    foreach ($manifest['records'] as $record) {
        $records[$record['target_path']] = $record;
    }

    return $records;
}

function seo07FakeSuccessfulTargets(
    ?string $robots = null,
    ?string $wrongIdentityPath = null,
): void {
    $records = seo07RecordsByTargetPath();

    Http::fake(
        function (Request $request) use (
            $records,
            $robots,
            $wrongIdentityPath,
        ) {
            $path = parse_url(
                $request->url(),
                PHP_URL_PATH,
            );

            if (! is_string($path)
                || ! isset($records[$path])) {
                return Http::response(
                    'unexpected target',
                    500,
                );
            }

            $record = $records[$path];

            $name = $path === $wrongIdentityPath
                ? 'Wrong product identity'
                : $record['target_product_name'];

            return Http::response(
                seo07TargetHtml(
                    $name,
                    'https://staging.example.test'.$path,
                    $robots,
                ),
                200,
                [
                    'Content-Type' =>
                        'text/html; charset=utf-8',
                ],
            );
        },
    );
}

beforeEach(function (): void {
    config([
        'traffic_protection.enabled' => false,
    ]);
});

afterEach(function (): void {
    @unlink(
        base_path(
            'storage/framework/testing/'
            .'seo07-target-validation.json',
        ),
    );
});

it('pins the immutable review-only SEO-07B exact-name-only cohort', function (): void {
    $path = base_path(
        ValidateExactNameOnlySeoTargetsCommand::MANIFEST,
    );

    $manifest = seo07FrozenManifest();

    expect(hash_file('sha256', $path))
        ->toBe(
            ValidateExactNameOnlySeoTargetsCommand::MANIFEST_SHA256,
        )
        ->and($manifest['phase'])
        ->toBe('SEO-07B')
        ->and($manifest['classification'])
        ->toBe('exact_name_only')
        ->and($manifest['approval_state'])
        ->toBe('REVIEW_ONLY')
        ->and($manifest['redirects_approved'])
        ->toBe(0)
        ->and($manifest['redirects_installed'])
        ->toBe(0)
        ->and($manifest['summary']['product_count'])
        ->toBe(149)
        ->and($manifest['summary']['source_path_count'])
        ->toBe(259)
        ->and($manifest['summary']['unique_target_count'])
        ->toBe(149)
        ->and($manifest['summary']['static_blocker_count'])
        ->toBe(0)
        ->and($manifest['records'])
        ->toHaveCount(149);
});

it('validates all 149 frozen candidate targets without granting approval', function (): void {
    seo07FakeSuccessfulTargets();

    $output = 'storage/framework/testing/seo07-target-validation.json';

    $exitCode = Artisan::call(
        'seo:validate-exact-name-only-targets',
        [
            '--base-url' =>
                'https://staging.example.test',
            '--output' => $output,
        ],
    );

    $console = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($console)
        ->toContain(
            'Candidate target products:      149',
        )
        ->toContain(
            'HTTP 200:                       149',
        )
        ->toContain(
            'Canonical correct:              149',
        )
        ->toContain(
            'Product identity correct:       149',
        )
        ->toContain(
            'Approval state: REVIEW_ONLY',
        )
        ->toContain(
            'Redirects approved by this command: 0',
        )
        ->toContain(
            'Redirects enabled by this command: NO',
        )
        ->toContain('RESULT: PASS');

    $report = json_decode(
        (string) file_get_contents(
            base_path($output),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['phase'])
        ->toBe('SEO-07C')
        ->and($report['validation_only'])
        ->toBeTrue()
        ->and($report['approval_state'])
        ->toBe('REVIEW_ONLY')
        ->and($report['classification'])
        ->toBe('exact_name_only')
        ->and($report['redirects_approved'])
        ->toBe(0)
        ->and(
            $report[
                'redirects_enabled_by_this_command'
            ]
        )
        ->toBeFalse()
        ->and($report['result'])
        ->toBe('PASS')
        ->and(
            $report['summary']
            ['candidate_target_products']
        )
        ->toBe(149)
        ->and($report['summary']['http_200'])
        ->toBe(149)
        ->and(
            $report['summary']
            ['canonical_correct']
        )
        ->toBe(149)
        ->and(
            $report['summary']
            ['product_identity_correct']
        )
        ->toBe(149)
        ->and($report['records'])
        ->toHaveCount(149);

    Http::assertSentCount(149);
});

it('allows staging noindex only when explicitly requested', function (): void {
    seo07FakeSuccessfulTargets(
        'noindex, nofollow, noarchive',
    );

    $output = 'storage/framework/testing/seo07-target-validation.json';

    $strictExit = Artisan::call(
        'seo:validate-exact-name-only-targets',
        [
            '--base-url' =>
                'https://staging.example.test',
            '--output' => $output,
        ],
    );

    expect($strictExit)->toBe(1)
        ->and(Artisan::output())
        ->toContain(
            'Noindex targets:                 149',
        )
        ->toContain('RESULT: FAIL');

    $allowedExit = Artisan::call(
        'seo:validate-exact-name-only-targets',
        [
            '--base-url' =>
                'https://staging.example.test',
            '--output' => $output,
            '--allow-noindex' => true,
        ],
    );

    expect($allowedExit)->toBe(0)
        ->and(Artisan::output())
        ->toContain(
            'Noindex targets:                 149',
        )
        ->toContain(
            'Approval state: REVIEW_ONLY',
        )
        ->toContain('RESULT: PASS');

    $report = json_decode(
        (string) file_get_contents(
            base_path($output),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['allow_noindex'])
        ->toBeTrue()
        ->and($report['summary']['indexable'])
        ->toBe(0)
        ->and(
            $report['summary']['noindex_targets']
        )
        ->toBe(149)
        ->and($report['redirects_approved'])
        ->toBe(0);

    Http::assertSentCount(298);
});

it('fails the cohort when one target renders the wrong product identity', function (): void {
    $records = seo07RecordsByTargetPath();
    $wrongPath = array_key_first($records);

    expect($wrongPath)->toBeString();

    seo07FakeSuccessfulTargets(
        null,
        $wrongPath,
    );

    $output = 'storage/framework/testing/seo07-target-validation.json';

    $exitCode = Artisan::call(
        'seo:validate-exact-name-only-targets',
        [
            '--base-url' =>
                'https://staging.example.test',
            '--output' => $output,
        ],
    );

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain(
            'Identity mismatches:              1',
        )
        ->toContain('RESULT: FAIL');

    $report = json_decode(
        (string) file_get_contents(
            base_path($output),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(
        $report['summary']['identity_mismatches']
    )
        ->toBe(1)
        ->and(
            $report[
                'summary'
            ]['product_identity_correct']
        )
        ->toBe(148)
        ->and($report['redirects_approved'])
        ->toBe(0);
});

it('requires an explicit base URL before making requests', function (): void {
    Http::fake();

    $exitCode = Artisan::call(
        'seo:validate-exact-name-only-targets',
        [
            '--output' =>
                'storage/framework/testing/'
                .'seo07-target-validation.json',
        ],
    );

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain(
            'Option --base-url is required.',
        );

    Http::assertNothingSent();
});

it('preserves the UTF-8-safe DOM bridge for Polish product identity', function (): void {
    $source = (string) file_get_contents(
        base_path(
            'app/Console/Commands/'
            .'ValidateExactNameOnlySeoTargetsCommand.php',
        ),
    );

    expect($source)
        ->toContain(
            'mb_encode_numericentity(',
        )
        ->toContain(
            "new \\DOMDocument('1.0', 'UTF-8')",
        )
        ->toContain('LIBXML_NONET')
        ->toContain(
            'return new Crawler($document, $url);',
        );
});
