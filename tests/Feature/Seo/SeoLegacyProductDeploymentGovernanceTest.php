<?php

declare(strict_types=1);

it('retains two independent production deployment locks', function (): void {
    $workflow = (string) file_get_contents(base_path(
        '.github/workflows/deploy-prod.yml',
    ));

    expect($workflow)
        ->toContain('name: Guard SEO-03B production HOLD')
        ->toContain('run: bash scripts/deploy/seo03b-p6s-source-gate.sh');

    foreach ([
        'Configure AWS credentials',
        'Deploy over SSM',
    ] as $step) {
        $pattern = '/^      - name: '
            .preg_quote($step, '/')
            .'\R        if: \$\{\{ false \}\}\R/m';

        expect(preg_match($pattern, $workflow))->toBe(1);
    }

    expect(substr_count($workflow, 'if: ${{ false }}'))
        ->toBe(2);

    expect($workflow)->not->toContain(
        "if: steps.seo03b_gate.outputs.deploy == 'true'",
    );
});

it('accepts the exact approved-64 source while withholding deployment', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    expect($guard)
        ->toContain('FIRST_APPROVED64_SOURCE_PROMOTION')
        ->toContain('ONGOING_APPROVED64_CI_ONLY')
        ->toContain('SEO05_SOURCE_GATE=PASS')
        ->toContain('SEO05_SOURCE_RULES=64')
        ->toContain('SEO05_DEPLOYMENT=WITHHELD')
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    expect($guard)
        ->toContain(
            '62c22a995aeff956c1f7e10dab94534da222426c',
        )
        ->toContain(
            '209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01',
        )
        ->toContain(
            'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7',
        )
        ->toContain(
            '6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d',
        );

    expect($guard)
        ->toContain('git diff --quiet "$FIRST" "$HEAD" --')
        ->toContain('git log')
        ->toContain('PROTECTED_TOUCHES')
        ->toContain('"$WORKFLOW"')
        ->toContain('"$GATE"')
        ->toContain('"$MAP"')
        ->toContain('"$MANIFEST"')
        ->toContain('"$HISTORICAL_MANIFEST"');
});

it('preserves the exact six-file approved-64 transition boundary', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    expect($guard)
        ->toContain('EXPECTED_DIFF=')
        ->toContain(
            'docker/nginx/generated/legacy-seo-product-map.conf',
        )
        ->toContain(
            'scripts/deploy/seo03b-p6s-source-gate.sh',
        )
        ->toContain(
            'tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php',
        )
        ->toContain(
            'tests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php',
        )
        ->toContain(
            'tests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php',
        )
        ->toContain(
            'tests/Feature/Seo/SeoProductionReleasePreflightContractTest.php',
        );

    expect($guard)
        ->toContain('GITHUB_EVENT_NAME')
        ->toContain(
            'Manual dispatch and non-push events are prohibited.',
        );
});
