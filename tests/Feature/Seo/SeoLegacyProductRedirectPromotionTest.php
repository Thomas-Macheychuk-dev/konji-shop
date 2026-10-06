<?php

declare(strict_types=1);

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use App\Support\Seo\ParentProductRedirectDecisionLedger;

it('promotes the committed deployment source additively from 64 to the approved 400-rule cohort', function (): void {
    $originalPath =
        'resources/seo/ortezka/product-redirect-approvals.json';

    $historical58Path =
        'resources/seo/ortezka/review/'
        .'seo-03b-p4-20261003/approved-58-manifest.json';

    $approved64Path =
        'resources/seo/ortezka/review/'
        .'seo-05h-20261006/approved-64-manifest.json';

    $approved400Path =
        'resources/seo/ortezka/review/'
        .'seo-06c-20261006/approved-400-manifest.json';

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
                expect(array_key_exists($source, $rules))
                    ->toBeFalse();

                $rules[$source] = $record['target_path'];
            }
        }

        ksort($rules);

        return $rules;
    };

    $originalManifest = $readManifest($originalPath);
    $historical58Manifest = $readManifest($historical58Path);
    $approved64Manifest = $readManifest($approved64Path);
    $approved400Manifest = $readManifest($approved400Path);

    $original = $readRules($originalManifest);
    $historical58 = $readRules($historical58Manifest);
    $approved64 = $readRules($approved64Manifest);
    $approved400 = $readRules($approved400Manifest);

    expect($original)->toHaveCount(36)
        ->and($historical58)->toHaveCount(58)
        ->and($approved64)->toHaveCount(64)
        ->and($approved400)->toHaveCount(400);

    foreach ($original as $source => $target) {
        expect($historical58[$source] ?? null)->toBe($target)
            ->and($approved64[$source] ?? null)->toBe($target)
            ->and($approved400[$source] ?? null)->toBe($target);
    }

    foreach ($historical58 as $source => $target) {
        expect($approved64[$source] ?? null)->toBe($target)
            ->and($approved400[$source] ?? null)->toBe($target);
    }

    foreach ($approved64 as $source => $target) {
        expect($approved400[$source] ?? null)->toBe($target);
    }

    expect(array_diff_key(
        $historical58,
        $original,
    ))->toHaveCount(22);

    expect(array_diff_key(
        $approved64,
        $original,
    ))->toHaveCount(28);

    expect(array_diff_key(
        $approved400,
        $approved64,
    ))->toHaveCount(336);

    expect(array_intersect_key(
        array_diff_key($approved400, $approved64),
        $approved64,
    ))->toBe([]);

    $reviewed = app(
        ParentProductRedirectApprovalPolicy::class,
    )->reviewedRecords();

    $approvedParents = app(
        ParentProductRedirectDecisionLedger::class,
    )->approvedRecords($reviewed);

    expect($reviewed)->toHaveCount(22)
        ->and($approvedParents)->toHaveCount(22)
        ->and(
            array_diff_key($reviewed, $approvedParents),
        )->toBe([]);

    expect($approved64Manifest['product_count'])->toBe(45)
        ->and($approved64Manifest['source_path_count'])->toBe(64)
        ->and(
            $approved64Manifest['deployment_authorized'],
        )->toBeFalse()
        ->and($approved64Manifest['redirects_installed'])->toBe(0);

    expect($approved400Manifest['schema_version'])->toBe(4)
        ->and($approved400Manifest['product_count'])->toBe(266)
        ->and(
            $approved400Manifest['source_path_count'],
        )->toBe(400)
        ->and(
            $approved400Manifest['deployment_authorized'],
        )->toBeFalse()
        ->and(
            $approved400Manifest['redirects_installed'],
        )->toBe(0)
        ->and(
            $approved400Manifest['base_manifest_sha256'],
        )->toBe(
            'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7',
        );

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

    expect($ruleCount)->toBe(400);

    $runtime = [];

    foreach ($matches as $match) {
        expect(array_key_exists($match[1], $runtime))
            ->toBeFalse();

        $runtime[$match[1]] = $match[2];
    }

    ksort($runtime);

    expect($runtime)->toBe($approved400);

    expect($map)
        ->toContain('# Source: '.$approved400Path)
        ->toContain(
            '# Manifest SHA-256: '
            .hash_file(
                'sha256',
                base_path($approved400Path),
            ),
        );

    expect(hash('sha256', $map))->toBe(
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
    );
});
