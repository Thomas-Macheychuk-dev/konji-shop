<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('defines a read-only non-authorising live-659 baseline contract', function (): void {
    $path = base_path(
        'scripts/deploy/seo07i-live659-baseline.sh',
    );

    expect(is_file($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('--current-live659-baseline')
        ->toContain('SEO07I_LIVE659_BASELINE=PASS')
        ->toContain('SEO07I_LIVE659_BASELINE=FAIL')
        ->toContain('SEO07I_LIVE659_BASELINE_FAILURE_COUNT=')
        ->toContain('SEO07I_SOURCE_RULES=659')
        ->toContain('SEO07I_ACTIVE_PRODUCTION_RULES=659')
        ->toContain('ACTIVE_LIVE659_MAP_SHA')
        ->toContain('ACTIVE_LIVE659_RULES')
        ->toContain('STAGING_RUNTIME_OVERLAY_OFF')
        ->toContain(
            'SEO07I_LIVE659_BASELINE_DEPLOY_AUTHORIZED=false',
        )
        ->not->toContain(
            'SEO07I_LIVE659_BASELINE_DEPLOY_AUTHORIZED=true',
        );

    expect($source)
        ->toContain(
            '--resolve ortezka.pl:443:127.0.0.1',
        )
        ->toContain(
            '--resolve staging.ortezka.pl:443:127.0.0.1',
        )
        ->not->toContain('--insecure');
});

it('pins exact live-659 image, map, evidence and rollback state', function (): void {
    $source = (string) file_get_contents(base_path(
        'scripts/deploy/seo07i-live659-baseline.sh',
    ));

    foreach ([
        '56bf4c205667517430cbaa2f1ce1212aba3723db',
        '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e',
        '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802',
        'dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184',
        '400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1',
        'f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde',
        '93d694d9e89c419963ab3d48a7f1dea1bc3a6f0ef211ec1f4597c62a10179d6b',
        '85723cda4b943bfd7b72ec24845365bc037b85e847d64a2191726ec1ae6dca52',
        '4616f00e7f6a0456fe6910e46d716b06a9dc498163f06e88bcd9c7b1bd624ab9',
        'sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1',
        'sha256:a16c4f9c7459f7715925becc5fb414ec11a5cffa56e251b6ef3cd6ca8b8a70e6',
        'sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854',
        'sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99',
        'konji-shop-web:seo07h-live659-56bf4c2',
        'konji-shop-web:seo07h-pre659-1cb8e60',
    ] as $value) {
        expect($source)->toContain($value);
    }

    expect($source)
        ->toContain('LIVE659_TARGET_REPORT_SHA')
        ->toContain('LIVE659_RUNTIME_REPORT_SHA')
        ->toContain('WEB_PRE659_ROLLBACK')
        ->toContain('WEB_LIVE659_IMMUTABLE');
});

it('has valid live-659 baseline shell syntax', function (): void {
    $process = new Process(
        [
            'bash',
            '-n',
            base_path(
                'scripts/deploy/'
                .'seo07i-live659-baseline.sh',
            ),
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(0);
});

it('rejects unsupported live-659 baseline modes before inspecting production', function (): void {
    $script = base_path(
        'scripts/deploy/seo07i-live659-baseline.sh',
    );

    $process = new Process(
        ['bash', $script, '--unsupported'],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(64);

    expect($process->getOutput())
        ->toContain(
            'SEO07I_LIVE659_BASELINE_DEPLOY_AUTHORIZED=false',
        );

    expect($process->getErrorOutput())
        ->toContain(
            'SEO07I_LIVE659_BASELINE_ERROR=INVALID_MODE',
        );
});

it('passes all 39 mocked live-659 baseline scenarios', function (): void {
    $fixture = base_path(
        'tests/Fixtures/Seo/'
        .'seo07i-live659-baseline-mock.sh',
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
        ->toContain('valid_live659=PASS')
        ->toContain('missing_pre659_rollback=PASS')
        ->toContain('missing_live659_tag=PASS')
        ->toContain(
            'SEO07I_LIVE659_CASES_PASSED=39/39',
        )
        ->toContain('SEO07I_LIVE659_FAILURES=0')
        ->toContain('SEO07I_LIVE659_SYNTHETIC=PASS');
});
