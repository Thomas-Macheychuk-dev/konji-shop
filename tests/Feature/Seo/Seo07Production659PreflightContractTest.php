<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('defines a read-only authorised source-659 live-400 production preflight', function (): void {
    $path = base_path(
        'scripts/deploy/seo07h-production659-preflight.sh',
    );

    expect(is_file($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('--candidate-659-predeploy')
        ->toContain('SEO07H_PREDEPLOY_CANDIDATE=PASS')
        ->toContain('SEO07H_PREDEPLOY_CANDIDATE=FAIL')
        ->toContain('SEO07H_PREDEPLOY_FAILURE_COUNT=')
        ->toContain('SEO07H_SOURCE_RULES=659')
        ->toContain('SEO07H_ACTIVE_PRODUCTION_RULES=400')
        ->toContain('SOURCE_659_MAP_SHA')
        ->toContain('ACTIVE_LIVE400_MAP_SHA')
        ->toContain('ACTIVE_LIVE400_RULES')
        ->toContain('STAGING_RUNTIME_OVERLAY_OFF')
        ->toContain('SEO07H_PREDEPLOY_DEPLOY_AUTHORIZED=true')
        ->toContain('SEO07H_PREDEPLOY_DEPLOY_AUTHORIZED=false');

    foreach ([
        '8f17139032dc076eb99b895b53c4f6aba8b224fb',
        '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e',
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        'dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184',
        '400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1',
        'f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde',
        '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802',
        'sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1',
        'sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854',
        'sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99',
        'konji-shop-web:maphash-live-cb0a34d',
    ] as $value) {
        expect($source)->toContain($value);
    }

    expect($source)
        ->toContain(
            '--resolve ortezka.pl:443:127.0.0.1',
        )
        ->toContain(
            '--resolve staging.ortezka.pl:443:127.0.0.1',
        )
        ->not->toContain('--insecure');
});

it('has valid shell syntax', function (): void {
    $process = new Process(
        [
            'bash',
            '-n',
            base_path(
                'scripts/deploy/'
                .'seo07h-production659-preflight.sh',
            ),
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(0);
});

it('rejects unsupported modes before inspecting production', function (): void {
    $script = base_path(
        'scripts/deploy/seo07h-production659-preflight.sh',
    );

    $process = new Process(
        ['bash', $script, '--unsupported'],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(64);

    expect($process->getOutput())
        ->toContain(
            'SEO07H_PREDEPLOY_DEPLOY_AUTHORIZED=false',
        );

    expect($process->getErrorOutput())
        ->toContain(
            'SEO07H_PREDEPLOY_ERROR=INVALID_MODE',
        );
});
