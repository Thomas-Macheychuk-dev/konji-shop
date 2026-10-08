<?php

declare(strict_types=1);

it('pins the authorised SEO-08 708-rule production source to the passed staging proof', function (): void {
    $manifestPath =
        'resources/seo/ortezka/review/seo-08d-20261008/'
        .'approved-708-manifest.json';

    $proofPath =
        'resources/seo/ortezka/review/seo-08e-20261008/'
        .'staging-runtime-proof.json';

    $authorizationPath =
        'resources/seo/ortezka/review/seo-08f-20261008/'
        .'production-promotion-708.json';

    $mapPath =
        'docker/nginx/generated/legacy-seo-product-map.conf';

    $manifest = json_decode(
        (string) file_get_contents(base_path($manifestPath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $proof = json_decode(
        (string) file_get_contents(base_path($proofPath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $authorization = json_decode(
        (string) file_get_contents(base_path($authorizationPath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(hash_file('sha256', base_path($manifestPath)))
        ->toBe('ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db')
        ->and(hash_file('sha256', base_path($proofPath)))
        ->toBe('dd4fb3a95d55fd4540132951790de7b9d7656c0463f6d661f9fc83a8e316baac')
        ->and(hash_file('sha256', base_path($authorizationPath)))
        ->toBe('7116b063d95aec383cabaf0a9a50ef2e947ea4623afa389c79c80c6197bd7530');

    expect($manifest['schema_version'])->toBe(6)
        ->and($manifest['product_count'])->toBe(445)
        ->and($manifest['source_path_count'])->toBe(708)
        ->and($manifest['deployment_authorized'])->toBeFalse()
        ->and($manifest['redirects_installed'])->toBe(0);

    expect($proof['result'])->toBe('PASS')
        ->and($proof['target_validation']['approved_target_products'])
        ->toBe(445)
        ->and($proof['runtime_validation']['approved_source_paths'])
        ->toBe(708)
        ->and($proof['runtime_validation']['redirect_chains'])
        ->toBe(0)
        ->and($proof['runtime_validation']['redirect_loops'])
        ->toBe(0)
        ->and($proof['restoration']['staging_overlay_final_state'])
        ->toBe('OFF');

    expect($authorization['decision'])
        ->toBe('AUTHORIZE_PRODUCTION_708')
        ->and($authorization['authorization_text'])
        ->toBe('AUTHORIZE SEO-08 PRODUCTION 708')
        ->and($authorization['authorization']['production_source_promotion'])
        ->toBeTrue()
        ->and($authorization['authorization']['production_runtime_activation'])
        ->toBeTrue()
        ->and($authorization['authorization']['production_ordinary_redirect_rule_count'])
        ->toBe(708)
        ->and($authorization['redirects_installed_at_record_time'])
        ->toBe(0);

    $expected = [];
    $approvedLegacyIds = [];

    foreach ($manifest['records'] as $record) {
        $legacyId = (string) (
            $record['legacy_id']
            ?? $record['legacy_product_id']
            ?? ''
        );

        expect($legacyId)->not->toBe('');

        $approvedLegacyIds[$legacyId] = true;

        foreach ($record['source_paths'] as $source) {
            expect($expected)->not->toHaveKey($source);
            $expected[$source] = $record['target_path'];
        }
    }

    expect($expected)->toHaveCount(708)
        ->and($approvedLegacyIds)->not->toHaveKey('3321')
        ->and($approvedLegacyIds)->not->toHaveKey('7560');

    ksort($expected, SORT_STRING);

    $map = (string) file_get_contents(base_path($mapPath));

    preg_match_all(
        '/^\s*"([^"]+)"\s+"([^"]+)";\s*$/m',
        $map,
        $matches,
        PREG_SET_ORDER,
    );

    $actual = [];

    foreach ($matches as $match) {
        expect($actual)->not->toHaveKey($match[1]);
        $actual[$match[1]] = $match[2];
    }

    ksort($actual, SORT_STRING);

    expect($actual)->toHaveCount(708)
        ->and($actual)->toBe($expected)
        ->and(array_intersect(
            array_keys($actual),
            array_values($actual),
        ))->toBe([])
        ->and(hash('sha256', $map))
        ->toBe('059d34d5e2b49301a4da744d904fd4e43a67c9005a10cb54e6ee90b6e2d4edff')
        ->and($map)
        ->toContain('# Source: '.$manifestPath)
        ->toContain(
            '# Manifest SHA-256: '
            .'ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db',
        );
});

it('keeps automatic deployment locked while exposing a read-only SEO-08F preflight', function (): void {
    $workflow = (string) file_get_contents(base_path(
        '.github/workflows/deploy-prod.yml',
    ));

    $gate = (string) file_get_contents(base_path(
        'scripts/deploy/seo08f-production708-source-gate.sh',
    ));

    $preflight = (string) file_get_contents(base_path(
        'scripts/deploy/seo08f-production708-preflight.sh',
    ));

    expect($workflow)
        ->toContain('Guard SEO-08 708-rule production source HOLD')
        ->toContain(
            'run: bash scripts/deploy/seo08f-production708-source-gate.sh',
        )
        ->and(substr_count(
            $workflow,
            'if: ${{ false }}',
        ))->toBe(2);

    expect($gate)
        ->toContain('SEO08F_EXACT_659_PLUS_49_UNION=PASS')
        ->toContain('SEO08F_SOURCE_RULES=708')
        ->toContain('SEO08F_DEPLOYMENT=WITHHELD')
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    expect($preflight)
        ->toContain('--candidate-708-predeploy')
        ->toContain('SEO08F_SOURCE_RULES=708')
        ->toContain('SEO08F_ACTIVE_PRODUCTION_RULES=659')
        ->toContain('SEO08F_PREDEPLOY_DEPLOY_AUTHORIZED=true')
        ->toContain('059d34d5e2b49301a4da744d904fd4e43a67c9005a10cb54e6ee90b6e2d4edff')
        ->toContain(
            '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e',
        );
});
