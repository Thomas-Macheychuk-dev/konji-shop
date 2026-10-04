<?php

declare(strict_types=1);

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use App\Support\Seo\ParentProductRedirectDecisionLedger;

it('preserves all 36 original redirects, adds exactly 22, and excludes every held source', function (): void {
    $originalPath = 'resources/seo/ortezka/product-redirect-approvals.json';

    $promotedPath =
        'resources/seo/ortezka/review/seo-03b-p4-20261003/approved-58-manifest.json';

    $readRules = static function (string $path): array {
        $manifest = json_decode(
            (string) file_get_contents(base_path($path)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

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

    $original = $readRules($originalPath);
    $promoted = $readRules($promotedPath);

    expect($original)->toHaveCount(36)
        ->and($promoted)->toHaveCount(58);

    // Every historical source must retain its exact destination.
    foreach ($original as $source => $target) {
        expect($promoted[$source] ?? null)->toBe($target);
    }

    $additional = array_diff_key($promoted, $original);

    expect($additional)->toHaveCount(22);

    // Inspect the actual production map, not only its input manifest.
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

    $actual = [];

    foreach ($matches as $match) {
        expect(array_key_exists($match[1], $actual))->toBeFalse();

        $actual[$match[1]] = $match[2];
    }

    ksort($actual);
    ksort($promoted);

    expect($actual)->toBe($promoted);

    // Derive HOLD sources from the authoritative reviewed records.
    $reviewed = app(ParentProductRedirectApprovalPolicy::class)
        ->reviewedRecords();

    $approved = app(ParentProductRedirectDecisionLedger::class)
        ->approvedRecords($reviewed);

    $held = array_diff_key($reviewed, $approved);

    $heldIds = array_map('strval', array_keys($held));
    sort($heldIds);

    expect($heldIds)->toBe(['6632', '6646', '6664', '6687']);

    $heldSources = [];

    foreach ($held as $record) {
        foreach ($record['source_paths'] as $source) {
            $heldSources[] = $source;

            expect(array_key_exists($source, $actual))->toBeFalse();
        }
    }

    expect($heldSources)->toHaveCount(6)
        ->and(array_unique($heldSources))->toHaveCount(6);

    // The generated map must identify the permanent frozen manifest.
    expect($map)
        ->toContain('# Source: '.$promotedPath)
        ->toContain('# Manifest SHA-256: '.hash_file(
            'sha256',
            base_path($promotedPath),
        ));
});
