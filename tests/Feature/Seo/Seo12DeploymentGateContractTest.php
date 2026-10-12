<?php

declare(strict_types=1);

it('requires the SEO-12 image integrity gate before container activation and migrations', function (): void {
    $script = (string) file_get_contents(
        base_path('scripts/deploy/aws-production.sh'),
    );

    $build = strpos($script, '${COMPOSE} build --pull app web');
    $gate = strpos($script, 'bash scripts/seo/verify-seo12-web-image.sh "$WEB_IMAGE_ID"');
    $activation = strpos($script, '${COMPOSE} up -d redis app');
    $migration = strpos($script, '${COMPOSE} exec -T app php artisan migrate --force');
    $webActivation = strpos($script, '${COMPOSE} up -d --remove-orphans web queue scheduler');

    expect($build)->not->toBeFalse()
        ->and($gate)->not->toBeFalse()
        ->and($activation)->not->toBeFalse()
        ->and($migration)->not->toBeFalse()
        ->and($webActivation)->not->toBeFalse();

    expect($build)->toBeLessThan($gate)
        ->and($gate)->toBeLessThan($activation)
        ->and($gate)->toBeLessThan($migration)
        ->and($gate)->toBeLessThan($webActivation);

    expect($script)
        ->toContain('SEO12_DEPLOYMENT_GATE=PASS')
        ->toContain('set -euo pipefail');
});

it('keeps automatic production deployment disabled during SEO-12 recovery', function (): void {
    $workflow = (string) file_get_contents(
        base_path('.github/workflows/deploy-prod.yml'),
    );

    expect($workflow)
        ->toContain('name: Verify SEO-12 recovery source integrity')
        ->toContain('python3 scripts/seo/verify-seo12-recovery.py');

    foreach ([
        'Configure AWS credentials',
        'Deploy over SSM',
    ] as $step) {
        $pattern = '/^      - name: '
            .preg_quote($step, '/')
            .'\\R        if: \\$\\{\\{ false \\}\\}\\R/m';

        expect(preg_match($pattern, $workflow))->toBe(1);
    }

    expect(substr_count($workflow, 'if: ${{ false }}'))->toBe(2);
});
