<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('retains a non-authorising verified live-400 baseline contract', function (): void {
    $path = base_path(
        'scripts/deploy/production-release-preflight.sh',
    );

    expect(is_file($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('--current-live400-baseline')
        ->toContain('SEO06_LIVE400_BASELINE=PASS')
        ->toContain('SEO06_LIVE400_BASELINE=FAIL')
        ->toContain('SEO06_LIVE400_BASELINE_FAILURE_COUNT=')
        ->toContain(
            'SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false',
        )
        ->not->toContain(
            'SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=true',
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
        'SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false',
    ))->toBe(3);
});

it('pins the verified live-400 production and frozen pre-400 rollback baselines', function (): void {
    $source = (string) file_get_contents(base_path(
        'scripts/deploy/production-release-preflight.sh',
    ));

    foreach ([
        '96fbe6b2cad2dfccce2c3e4e7564398263fcf5c2',
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        '285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa',
        'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7',
        '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802',
        'fc9aa7bbb02d14bfe52acfdd1cb6ba29f325ce032d967bbd838c9fc72984cf0e',
        'sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1',
        'sha256:ea6c62b7a8ce95d2d1728fa84e3fbe754b00b496b6ace4b3ac3a4c9ea684f9a4',
        'sha256:e81b7c35e39b2672effb24188d3a25fc430a011b56fcac7fbe1ab8532c225616',
        'sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99',
        'konji-shop-app:seo06-pre400-bbe0e4e',
        'konji-shop-web:seo06-pre400-bbe0e4e',
        'konji-shop-web:seo06-live400-96fbe6b',
    ] as $value) {
        expect($source)->toContain($value);
    }

    expect($source)
        ->toContain('SOURCE_400_RULES')
        ->toContain('ACTIVE_LIVE400_MAP_SHA')
        ->toContain('ACTIVE_LIVE400_RULES')
        ->toContain('LIVE400_VALIDATION_EVIDENCE_SHA')
        ->toContain('STAGING_RUNTIME_OVERLAY_OFF');
});

it('rejects unsupported live-400 baseline modes before inspecting production', function (): void {
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
            'SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false',
        );

    expect($process->getErrorOutput())
        ->toContain(
            'SEO06_LIVE400_BASELINE_ERROR=INVALID_MODE',
        );
});

it('passes all 37 mocked SEO-06 live-400 baseline scenarios', function (): void {
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
        ->toContain('valid_live400=PASS')
        ->toContain('missing_web_rollback=PASS')
        ->toContain('missing_live400_tag=PASS')
        ->toContain(
            'SEO06_LIVE400_CASES_PASSED=37/37',
        )
        ->toContain('SEO06_LIVE400_FAILURES=0')
        ->toContain('SEO06_LIVE400_SYNTHETIC=PASS');
});
