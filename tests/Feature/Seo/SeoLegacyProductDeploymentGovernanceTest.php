<?php

declare(strict_types=1);

it('keeps the first P6S transition source-only and independently disables both production deployment steps', function (): void {
    $workflow = (string) file_get_contents(base_path(
        '.github/workflows/deploy-prod.yml',
    ));

    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    // The workflow must execute the dedicated P6S transition guard.
    expect($workflow)
        ->toContain('name: Guard SEO-03B P6S source-only transition')
        ->toContain('run: bash scripts/deploy/seo03b-p6s-source-gate.sh');

    // Verify both locks individually, not merely their combined count.
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

    // The previous conditional deployment mechanism must be absent.
    expect($workflow)->not->toContain(
        "if: steps.seo03b_gate.outputs.deploy == 'true'",
    );

    // P6S-A must not contain an authorised deployment path.
    expect($guard)
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    // The exact previously released source is the transition boundary.
    expect($guard)
        ->toContain('df2e1fe0cd25188ae642f65c324c97911304f623')
        ->toContain('ece3d1558b317f2e7517e0ac6397e18f936e55a2ae258c51ffd28ef7db10c196')
        ->toContain('6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d');

    // The transition must include its own regression contract.
    expect($guard)->toContain(
        'SeoLegacyProductDeploymentGovernanceTest.php',
    );
});
