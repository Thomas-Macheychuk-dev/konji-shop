<?php

declare(strict_types=1);

it('retains two independent production deployment locks', function (): void {
    $workflow = (string) file_get_contents(base_path(
        '.github/workflows/deploy-prod.yml',
    ));

    expect($workflow)
        ->toContain(
            'name: Guard SEO-06 400-rule production source HOLD',
        )
        ->toContain(
            'run: bash scripts/deploy/seo03b-p6s-source-gate.sh',
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

    expect($workflow)->not->toContain(
        "if: steps.seo03b_gate.outputs.deploy == 'true'",
    );
});

it('accepts only the approved-400 source and governed live-400 baseline while withholding deployment', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    expect($guard)
        ->toContain('FIRST_LIVE400_BASELINE_CLOSURE')
        ->toContain('ONGOING_LIVE400_BASELINE_CI_ONLY')
        ->toContain('SEO06_SOURCE_GATE=PASS')
        ->toContain('SEO06_SOURCE_RULES=400')
        ->toContain('SEO06_LIVE_BASELINE_RULES=400')
        ->toContain('SEO06_DEPLOYMENT=WITHHELD')
        ->toContain('SEO06_EXACT_64_PLUS_336_UNION=PASS')
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    foreach ([
        'bbe0e4e1473219afa04e0e01cc82ba534cf5d71f',
        '96fbe6b2cad2dfccce2c3e4e7564398263fcf5c2',
        '97fb5df68e6ff6e539cd85b4cba913d44bfec9eb',
        '209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01',
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        '285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa',
        'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7',
        '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802',
        'sha256:ea6c62b7a8ce95d2d1728fa84e3fbe754b00b496b6ace4b3ac3a4c9ea684f9a4',
    ] as $value) {
        expect($guard)->toContain($value);
    }

    expect($guard)
        ->toContain('git diff --quiet "$CLOSURE" "$HEAD" --')
        ->toContain('PROTECTED_TOUCHES')
        ->toContain('"$WORKFLOW"')
        ->toContain('"$GATE"')
        ->toContain('"$PREFLIGHT"')
        ->toContain('"$MAP"')
        ->toContain('"$EXTRA_MAP"')
        ->toContain('"$MANIFEST"')
        ->toContain('"$BASE_MANIFEST"');
});

it('preserves the historical eleven-file promotion and exact five-file live-400 closure boundaries', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    foreach ([
        '.github/workflows/deploy-prod.yml',
        'docker/nginx/generated/legacy-seo-product-map.conf',
        'tests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectSchemaV4Test.php',
        'tests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php',
    ] as $historicalPath) {
        expect($guard)->toContain($historicalPath);
    }

    foreach ([
        'scripts/deploy/production-release-preflight.sh',
        'scripts/deploy/seo03b-p6s-source-gate.sh',
        'tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php',
        'tests/Feature/Seo/SeoProductionReleasePreflightContractTest.php',
        'tests/Fixtures/Seo/production-release-preflight-mock.sh',
    ] as $closurePath) {
        expect($guard)->toContain($closurePath);
    }

    expect($guard)
        ->toContain('EXPECTED_PROMOTION_DIFF=')
        ->toContain('EXPECTED_CLOSURE_DIFF=')
        ->toContain('GITHUB_EVENT_NAME')
        ->toContain(
            'Manual dispatch and non-push events are prohibited.',
        );
});
