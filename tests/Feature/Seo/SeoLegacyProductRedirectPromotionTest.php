<?php

declare(strict_types=1);

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use App\Support\Seo\ParentProductRedirectDecisionLedger;

it('keeps production on 58 while the approved candidate is a strictly additive 64-rule cohort', function (): void {
    $originalPath =
        'resources/seo/ortezka/product-redirect-approvals.json';

    $historical58Path =
        'resources/seo/ortezka/review/'
        .'seo-03b-p4-20261003/approved-58-manifest.json';

    $candidate64Path =
        'resources/seo/ortezka/review/'
        .'seo-05h-20261006/approved-64-manifest.json';

    $readManifest = static function (string $path): array {
        return json_decode(
            (string) file_get_contents(base_path($path)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    };

    $readRules = static function (array $manifest): array {
        $rules = [];

        foreach ($manifest['records'] as $record) {
            expect($record['approved'])->toBeTrue()
                ->and($record['decision'])->toBe('APPROVE_301');

            foreach ($record['source_paths'] as $source) {
                expect(array_key_exists($source, $rules))->toBeFalse();

                $rules[$source] = $record['target_path'];
            }
        }

        return $rules;
    };

    $originalManifest = $readManifest($originalPath);
    $historical58Manifest = $readManifest($historical58Path);
    $candidate64Manifest = $readManifest($candidate64Path);

    $original = $readRules($originalManifest);
    $historical58 = $readRules($historical58Manifest);
    $candidate64 = $readRules($candidate64Manifest);

    expect($original)->toHaveCount(36)
        ->and($historical58)->toHaveCount(58)
        ->and($candidate64)->toHaveCount(64);

    // Both promoted cohorts must preserve every original destination.
    foreach ($original as $source => $target) {
        expect($historical58[$source] ?? null)->toBe($target)
            ->and($candidate64[$source] ?? null)->toBe($target);
    }

    expect(array_diff_key(
        $historical58,
        $original,
    ))->toHaveCount(22);

    expect(array_diff_key(
        $candidate64,
        $original,
    ))->toHaveCount(28);

    // SEO-05 must be strictly additive to the already-deployed 58.
    foreach ($historical58 as $source => $target) {
        expect($candidate64[$source] ?? null)->toBe($target);
    }

    $added = array_diff_key(
        $candidate64,
        $historical58,
    );

    $expectedAdded = [
        '/pilka-rehabilitacyjna-midi-reh-37988-id-6632'
            => '/products/pilka-rehabilitacyjna-midi-reh',

        '/pilka-rehabilitacyjna-midi-reh-id-6632'
            => '/products/pilka-rehabilitacyjna-midi-reh',

        '/kula-lokciowa-aluminiowa-z-ruchoma-obejma-ergonomiczny-uchwyt-70785-id-6646'
            => '/products/kula-lokciowa-aluminiowa-z-ruchoma-obejma-ergonomiczny-uchwyt',

        '/kula-lokciowa-aluminiowa-z-ruchoma-obejma-ergonomiczny-uchwyt-id-6646'
            => '/products/kula-lokciowa-aluminiowa-z-ruchoma-obejma-ergonomiczny-uchwyt',

        '/podporka-rehabilitacyjna-dwukolowa-standard-id-6664'
            => '/products/podporka-rehabilitacyjna-dwukolowa-standard',

        '/taboret-prysznicowy-z-wycieciem-u-id-6687'
            => '/products/taboret-prysznicowy-z-wycieciem-u',
    ];

    ksort($added);
    ksort($expectedAdded);

    expect($added)->toBe($expectedAdded);

    // Current governance must now accept all 22 reviewed parent products.
    $reviewed = app(
        ParentProductRedirectApprovalPolicy::class,
    )->reviewedRecords();

    $approved = app(
        ParentProductRedirectDecisionLedger::class,
    )->approvedRecords($reviewed);

    expect($reviewed)->toHaveCount(22)
        ->and($approved)->toHaveCount(22)
        ->and(array_diff_key($reviewed, $approved))->toBe([]);

    // Candidate remains explicitly non-deployed.
    expect($candidate64Manifest['product_count'])->toBe(45)
        ->and($candidate64Manifest['source_path_count'])->toBe(64)
        ->and($candidate64Manifest['deployment_authorized'])->toBeFalse()
        ->and($candidate64Manifest['redirects_installed'])->toBe(0)
        ->and($candidate64Manifest['parent_decision_sha256'])
        ->toBe(ParentProductRedirectDecisionLedger::DECISION_SHA256);

    // Production must STILL equal the historical approved-58 manifest.
    $map = (string) file_get_contents(base_path(
        'docker/nginx/generated/legacy-seo-product-map.conf',
    ));

    $matches = [];

    $ruleCount = preg_match_all(
        '/^    "([^"]+)" "([^"]+)";$/m',
        $map,
        $matches,
        PREG_SET_ORDER,
    );

    expect($ruleCount)->toBe(58);

    $runtime = [];

    foreach ($matches as $match) {
        expect(array_key_exists($match[1], $runtime))->toBeFalse();

        $runtime[$match[1]] = $match[2];
    }

    ksort($runtime);
    ksort($historical58);

    expect($runtime)->toBe($historical58);

    expect($map)
        ->toContain('# Source: '.$historical58Path)
        ->toContain('# Manifest SHA-256: '.hash_file(
            'sha256',
            base_path($historical58Path),
        ));
});
