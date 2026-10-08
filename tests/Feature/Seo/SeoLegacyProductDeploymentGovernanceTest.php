<?php

declare(strict_types=1);

it('retains two independent production deployment locks through the SEO-08 708-source governance gate', function (): void {
    $workflow = (string) file_get_contents(base_path(
        '.github/workflows/deploy-prod.yml',
    ));

    expect($workflow)
        ->toContain(
            'name: Guard SEO-08 708-rule production source HOLD',
        )
        ->toContain(
            'run: bash scripts/deploy/seo08f-production708-source-gate.sh',
        );

    foreach ([
        'Configure AWS credentials',
        'Deploy over SSM',
    ] as $step) {
        $pattern = '/^      - name: '
            .preg_quote($step, '/')
            .'\R        if: \$\{\{ false \}\}\R/m';

        expect(preg_match($pattern, $workflow))->toBe(1);
    }

    expect(substr_count(
        $workflow,
        'if: ${{ false }}',
    ))->toBe(2);

    expect($workflow)
        ->not->toContain(
            "if: steps.seo08f_gate.outputs.deploy == 'true'",
        )
        ->not->toContain(
            "if: steps.seo03b_gate.outputs.deploy == 'true'",
        );
});

it('governs the verified live-659 baseline while withholding automatic deployment', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo07h-production659-source-gate.sh',
    ));

    expect($guard)
        ->toContain('FIRST_LIVE659_BASELINE_CLOSURE')
        ->toContain('ONGOING_LIVE659_BASELINE_CI_ONLY')
        ->toContain('SEO07I_SOURCE_GATE=PASS')
        ->toContain('SEO07I_SOURCE_RULES=659')
        ->toContain('SEO07I_LIVE_BASELINE_RULES=659')
        ->toContain('SEO07I_DEPLOYMENT=WITHHELD')
        ->toContain('SEO07I_EXACT_400_PLUS_259_UNION=PASS')
        ->toContain('SEO07I_LIVE659_EVIDENCE_CONTRACT=PASS')
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    foreach ([
        '8f17139032dc076eb99b895b53c4f6aba8b224fb',
        '56bf4c205667517430cbaa2f1ce1212aba3723db',
        'dff528e44c6d27ebb55b963ddbc8fb7fca62d065',
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e',
        'dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184',
        '400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1',
        'f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde',
        '93d694d9e89c419963ab3d48a7f1dea1bc3a6f0ef211ec1f4597c62a10179d6b',
        '85723cda4b943bfd7b72ec24845365bc037b85e847d64a2191726ec1ae6dca52',
        '4616f00e7f6a0456fe6910e46d716b06a9dc498163f06e88bcd9c7b1bd624ab9',
        'sha256:a16c4f9c7459f7715925becc5fb414ec11a5cffa56e251b6ef3cd6ca8b8a70e6',
        'sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854',
    ] as $value) {
        expect($guard)->toContain($value);
    }
});

it('pins the historical thirteen-file promotion and exact six-file live-659 closure', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo07h-production659-source-gate.sh',
    ));

    foreach ([
        '.github/workflows/deploy-prod.yml',
        'docker/nginx/generated/legacy-seo-product-map.conf',
        'resources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json',
        'scripts/deploy/seo07h-production659-preflight.sh',
        'tests/Feature/Seo/Seo07Production659PreflightContractTest.php',
        'tests/Feature/Seo/SeoLegacyExactNameOnlyDecisionLedgerTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectSchemaV4Test.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectSchemaV5Test.php',
        'tests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php',
    ] as $historicalPath) {
        expect($guard)->toContain($historicalPath);
    }

    foreach ([
        'resources/seo/ortezka/review/seo-07i-20261008/live-659-baseline.json',
        'scripts/deploy/seo07h-production659-source-gate.sh',
        'scripts/deploy/seo07i-live659-baseline.sh',
        'tests/Feature/Seo/Seo07Live659BaselineContractTest.php',
        'tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php',
        'tests/Fixtures/Seo/seo07i-live659-baseline-mock.sh',
    ] as $closurePath) {
        expect($guard)->toContain($closurePath);
    }

    expect($guard)
        ->toContain('EXPECTED_PROMOTION_DIFF=')
        ->toContain('EXPECTED_CLOSURE_DIFF=')
        ->toContain('PROTECTED_TOUCHES')
        ->toContain('GITHUB_EVENT_NAME')
        ->toContain(
            'Manual dispatch and non-push events are prohibited.',
        );
});

it('preserves SEO-07H predeployment evidence after the live-659 closure', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo07h-production659-source-gate.sh',
    ));

    $preflight = (string) file_get_contents(base_path(
        'scripts/deploy/seo07h-production659-preflight.sh',
    ));

    expect($guard)
        ->toContain(
            'Historical SEO-07H predeployment evidence changed.',
        )
        ->toContain('"$PREDEPLOY"');

    expect($preflight)
        ->toContain('--candidate-659-predeploy')
        ->toContain('SEO07H_SOURCE_RULES=659')
        ->toContain('SEO07H_ACTIVE_PRODUCTION_RULES=400')
        ->toContain(
            '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        );
});

it('keeps the historical SEO-06 live-400 governance artifacts intact', function (): void {
    $historicalGate = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    $historicalPreflight = (string) file_get_contents(base_path(
        'scripts/deploy/production-release-preflight.sh',
    ));

    expect($historicalGate)
        ->toContain('SEO06_SOURCE_RULES=400')
        ->toContain('SEO06_LIVE_BASELINE_RULES=400')
        ->toContain('SEO06_EXACT_64_PLUS_336_UNION=PASS')
        ->toContain(
            '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        );

    expect($historicalPreflight)
        ->toContain('--current-live400-baseline')
        ->toContain('SEO06_ACTIVE_PRODUCTION_RULES=400')
        ->toContain(
            '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        );
});
