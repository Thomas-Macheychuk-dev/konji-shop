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

it('enforces a permanent CI-only production HOLD', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    expect($guard)
        ->toContain('FIRST_SOURCE_ONLY_PROMOTION')
        ->toContain('ONGOING_CI_ONLY')
        ->toContain('P6S_C_PRODUCTION_HOLD=PASS')
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    // The first transition must originate from the reconciled release.
    expect($guard)->toContain(
        '6a32ed2a932c0c82af6a176904a6edb5087778ee',
    );

    // The previously approved SEO release must remain immutable.
    expect($guard)
        ->toContain('ece3d1558b317f2e7517e0ac6397e18f936e55a2ae258c51ffd28ef7db10c196')
        ->toContain('6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d');

    // Subsequent pushes must not change protected release files.
    expect($guard)
        ->toContain('git diff --quiet "$FIRST" "$HEAD" --')
        ->toContain('git log --full-history -m --format= --name-only')
        ->toContain('PROTECTED_TOUCHES')
        ->toContain('"$WORKFLOW" "$GATE" "$MAP" "$MANIFEST"');
});

it('preserves the exact three-file first transition boundary', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    expect($guard)
        ->toContain('EXPECTED_DIFF=')
        ->toContain('.github/workflows/deploy-prod.yml')
        ->toContain('scripts/deploy/seo03b-p6s-source-gate.sh')
        ->toContain('tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php');

    // Manual workflow dispatch must never bypass the production HOLD.
    expect($guard)
        ->toContain('GITHUB_EVENT_NAME')
        ->toContain('Manual dispatch and non-push events are prohibited.');
});
