<?php

declare(strict_types=1);

it('pins the owner-approved SEO-09 848-rule staging candidate without changing production', function (): void {
    $reviewAPath = 'resources/seo/ortezka/review/seo-09a-20261008/parent-only-candidates-140.json';
    $reviewBPath = 'resources/seo/ortezka/review/seo-09b-20261008/reviewed-parent-candidates-140.json';
    $decisionPath = 'resources/seo/ortezka/review/seo-09c-20261008/owner-decision-91.json';
    $basePath = 'resources/seo/ortezka/review/seo-08d-20261008/approved-708-manifest.json';
    $manifestPath = 'resources/seo/ortezka/review/seo-09d-20261008/approved-848-manifest.json';
    $candidateMapPath = 'resources/seo/ortezka/review/seo-09d-20261008/candidate-848-map.conf';
    $incrementalMapPath = 'resources/seo/ortezka/review/seo-09d-20261008/staging-incremental-140-map.conf';
    $productionMapPath = 'docker/nginx/generated/legacy-seo-product-map.conf';

    expect(hash_file('sha256', base_path($reviewAPath)))
        ->toBe('49ab32c8d3cb236b40e159114504c31d5a4738dea97461ea42e609159afafa75')
        ->and(hash_file('sha256', base_path($reviewBPath)))
        ->toBe('64b1cac5d36a50b20d3e2803f3ddfd275b772cabad63d081d430db661d85c141')
        ->and(hash_file('sha256', base_path($decisionPath)))
        ->toBe('ac038ad197c7a38e1367e7d161926c556ff1a1e252f3755eec85705ac6d6f6b9')
        ->and(hash_file('sha256', base_path($basePath)))
        ->toBe('ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db')
        ->and(hash_file('sha256', base_path($manifestPath)))
        ->toBe('ee5794a1464346a93e32db89efda9355dfc4040dff5a59d9acb52aafc794dcf0')
        ->and(hash_file('sha256', base_path($candidateMapPath)))
        ->toBe('990daea6d0027ce1fdbddf19015763f5a6655f772de3030592807e002e9e1cc6')
        ->and(hash_file('sha256', base_path($incrementalMapPath)))
        ->toBe('009f23ba3dfe2de6e1eceaf100cedcd7a5cb961708d3e16374c0aa95a8370b7f')
        ->and(hash_file('sha256', base_path($productionMapPath)))
        ->toBe('059d34d5e2b49301a4da744d904fd4e43a67c9005a10cb54e6ee90b6e2d4edff');

    $decision = json_decode(
        (string) file_get_contents(base_path($decisionPath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($decision['decision_source'])
        ->toBe('User message: APPROVE SEO-09 91/140; HOLD 49/88')
        ->and($decision['deployment_authorized'])->toBeFalse()
        ->and($decision['production_redirect_activation_authorized'])->toBeFalse()
        ->and($decision['counts']['approved_identities'])->toBe(91)
        ->and($decision['counts']['approved_source_paths'])->toBe(140)
        ->and($decision['counts']['held_identities'])->toBe(49)
        ->and($decision['counts']['held_source_paths'])->toBe(88)
        ->and($decision['counts']['prospective_combined_products'])->toBe(536)
        ->and($decision['counts']['prospective_combined_source_paths'])->toBe(848)
        ->and($decision['governance']['owner_approval_received'])->toBeTrue()
        ->and($decision['governance']['staging_candidate_activation_authorized'])->toBeFalse()
        ->and($decision['governance']['production_deployment_authorized'])->toBeFalse();

    $base = json_decode(
        (string) file_get_contents(base_path($basePath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $manifest = json_decode(
        (string) file_get_contents(base_path($manifestPath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['schema_version'])->toBe(7)
        ->and($manifest['validation_only'])->toBeFalse()
        ->and($manifest['deployment_authorized'])->toBeFalse()
        ->and($manifest['redirects_installed'])->toBe(0)
        ->and($manifest['product_count'])->toBe(536)
        ->and($manifest['source_path_count'])->toBe(848)
        ->and($manifest['base_manifest_sha256'])->toBe('ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db')
        ->and($manifest['parent_decision_sha256'])->toBe('ac038ad197c7a38e1367e7d161926c556ff1a1e252f3755eec85705ac6d6f6b9')
        ->and($manifest['approved_increment']['product_count'])->toBe(91)
        ->and($manifest['approved_increment']['source_path_count'])->toBe(140)
        ->and($manifest['held']['product_count'])->toBe(49)
        ->and($manifest['held']['source_path_count'])->toBe(88)
        ->and($manifest['records'])->toHaveCount(536)
        ->and(array_slice($manifest['records'], 0, 445))->toBe($base['records']);

    $expected = [];
    $targets = [];

    foreach ($manifest['records'] as $record) {
        expect($record['approved'])->toBeTrue()
            ->and($record['decision'])->toBe('APPROVE_301');

        $target = $record['target_path'];

        expect($targets)->not->toHaveKey($target);
        $targets[$target] = true;

        foreach ($record['source_paths'] as $source) {
            expect($expected)->not->toHaveKey($source);
            $expected[$source] = $target;
        }
    }

    expect($expected)->toHaveCount(848)
        ->and($targets)->toHaveCount(536)
        ->and(array_intersect(array_keys($expected), array_keys($targets)))
        ->toBe([]);

    ksort($expected, SORT_STRING);

    $parseMap = static function (string $path): array {
        preg_match_all(
            '/^\s*"([^"]+)"\s+"([^"]+)";\s*$/m',
            (string) file_get_contents(base_path($path)),
            $matches,
            PREG_SET_ORDER,
        );

        $rules = [];

        foreach ($matches as $match) {
            expect($rules)->not->toHaveKey($match[1]);
            $rules[$match[1]] = $match[2];
        }

        ksort($rules, SORT_STRING);

        return $rules;
    };

    $candidate = $parseMap($candidateMapPath);
    $production = $parseMap($productionMapPath);
    $incremental = $parseMap($incrementalMapPath);

    expect($candidate)->toHaveCount(848)
        ->and($candidate)->toBe($expected)
        ->and($production)->toHaveCount(708)
        ->and($incremental)->toHaveCount(140);

    $delta = array_diff_key($candidate, $production);

    expect($delta)->toHaveCount(140)
        ->and($delta)->toBe($incremental);

    foreach ($production as $source => $target) {
        expect($candidate[$source] ?? null)->toBe($target);
    }
});
