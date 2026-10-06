<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('retains a non-authorising SEO-06 candidate predeployment contract', function (): void {
    $path = base_path(
        'scripts/deploy/production-release-preflight.sh',
    );

    expect(is_file($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('--candidate-400-predeploy')
        ->toContain('SEO06_PREDEPLOY_CANDIDATE=PASS')
        ->toContain('SEO06_PREDEPLOY_CANDIDATE=FAIL')
        ->toContain('SEO06_PREDEPLOY_FAILURE_COUNT=')
        ->toContain(
            'SEO06_PREDEPLOY_DEPLOY_AUTHORIZED=false',
        )
        ->not->toContain(
            'SEO06_PREDEPLOY_DEPLOY_AUTHORIZED=true',
        );

    expect($source)
        ->toContain(
            '--resolve ortezka.pl:443:127.0.0.1',
        )
        ->toContain(
            '--resolve staging.ortezka.pl:443:127.0.0.1',
        )
        ->not->toContain('--insecure');

    expect(substr_count(
        $source,
        'SEO06_PREDEPLOY_DEPLOY_AUTHORIZED=false',
    ))->toBe(3);
});

it('distinguishes the approved-400 candidate source from the verified live-64 baseline', function (): void {
    $source = (string) file_get_contents(base_path(
        'scripts/deploy/production-release-preflight.sh',
    ));

    foreach ([
        'bbe0e4e1473219afa04e0e01cc82ba534cf5d71f',
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        '209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01',
        '285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa',
        'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7',
        '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802',
        'sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1',
        'sha256:e81b7c35e39b2672effb24188d3a25fc430a011b56fcac7fbe1ab8532c225616',
        'sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99',
        'konji-shop-app:seo06-pre400-bbe0e4e',
        'konji-shop-web:seo06-pre400-bbe0e4e',
    ] as $value) {
        expect($source)->toContain($value);
    }

    expect($source)
        ->toContain('SOURCE_400_RULES')
        ->toContain('ACTIVE_LIVE64_RULES')
        ->toContain('STAGING_RUNTIME_OVERLAY_OFF');
});

it('rejects unsupported predeployment modes before inspecting production', function (): void {
    $script = base_path(
        'scripts/deploy/production-release-preflight.sh',
    );

    $process = new Process(
        ['bash', $script, '--unsupported'],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(64);

    expect($process->getOutput())
        ->toContain(
            'SEO06_PREDEPLOY_DEPLOY_AUTHORIZED=false',
        );

    expect($process->getErrorOutput())
        ->toContain(
            'SEO06_PREDEPLOY_ERROR=INVALID_MODE',
        );
});

it('passes all 33 mocked SEO-06 predeployment scenarios', function (): void {
    $fixture = base_path(
        'tests/Fixtures/Seo/'
        .'production-release-preflight-mock.sh',
    );

    $process = new Process(
        ['bash', $fixture],
        base_path(),
    );

    $process->setTimeout(60);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            $process->getOutput()
            ."\n"
            .$process->getErrorOutput(),
        );
    }

    expect($process->getOutput())
        ->toContain('valid_candidate=PASS')
        ->toContain('missing_web_rollback=PASS')
        ->toContain(
            'SEO06_PREDEPLOY_CASES_PASSED=33/33',
        )
        ->toContain('SEO06_PREDEPLOY_FAILURES=0')
        ->toContain('SEO06_PREDEPLOY_SYNTHETIC=PASS');
});
