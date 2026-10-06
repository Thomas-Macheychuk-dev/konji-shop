<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('retains a non-authorising production baseline contract', function (): void {
    $path = base_path(
        'scripts/deploy/production-release-preflight.sh',
    );

    expect(is_file($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('--current-baseline')
        ->toContain('P6S_CB5_CURRENT_BASELINE=PASS')
        ->toContain('P6S_CB5_CURRENT_BASELINE=FAIL')
        ->toContain('P6S_CB5_FAILURE_COUNT=')
        ->toContain('P6S_CB5_DEPLOY_AUTHORIZED=false')
        ->not->toContain('P6S_CB5_DEPLOY_AUTHORIZED=true');

    // Production HTTPS readiness must validate the TLS certificate.
    expect($source)
        ->toContain('--resolve ortezka.pl:443:127.0.0.1')
        ->not->toContain('--insecure');

    // Success and failure must both explicitly withhold deployment.
    expect(substr_count(
        $source,
        'P6S_CB5_DEPLOY_AUTHORIZED=false',
    ))->toBe(3);
});

it('pins the verified live approved-64 production baseline', function (): void {
    $source = (string) file_get_contents(base_path(
        'scripts/deploy/production-release-preflight.sh',
    ));

    foreach ([
        '1514ab8520798514fdb6292a12b81dc180ea5032',
        'sha256:fbb0fd2eab198faea10907f18784d8a3116f357814dc828e0830a37fa4b1caaf',
        'sha256:cf71d25e36389e330f6a1364d49d603a3507f9e5e82f42493dd3dc23849f004f',
        'sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99',
        'sha256:769c0d75699d09872f8e779993b05accd64a2cf68e2428ff3badc2a801783ed5',
        '209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01',
        'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7',
    ] as $value) {
        expect($source)->toContain($value);
    }

    expect(hash_file(
        'sha256',
        base_path('docker/nginx/generated/legacy-seo-product-map.conf'),
    ))->toBe('209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01');

    expect(hash_file(
        'sha256',
        base_path(
            'resources/seo/ortezka/review/'
            .'seo-05h-20261006/approved-64-manifest.json',
        ),
    ))->toBe('cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7');

    expect(hash_file(
        'sha256',
        base_path(
            'resources/seo/ortezka/review/'
            .'seo-03b-p4-20261003/approved-58-manifest.json',
        ),
    ))->toBe('6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d');
});

it('rejects unsupported modes before inspecting production', function (): void {
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
        ->toContain('P6S_CB5_DEPLOY_AUTHORIZED=false');

    expect($process->getErrorOutput())
        ->toContain('P6S_CB5_ERROR=INVALID_MODE');
});

it('passes all 26 mocked production baseline scenarios', function (): void {
    $fixture = base_path(
        'tests/Fixtures/Seo/production-release-preflight-mock.sh',
    );

    $process = new Process(
        ['bash', $fixture],
        base_path(),
    );

    $process->setTimeout(60);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            $process->getOutput()."\n".$process->getErrorOutput(),
        );
    }

    expect($process->getOutput())
        ->toContain('valid_baseline=PASS')
        ->toContain('missing_rollback=PASS')
        ->toContain('P6S_CB6_CASES_PASSED=26/26')
        ->toContain('P6S_CB6_FAILURES=0')
        ->toContain('P6S_CB6_SYNTHETIC_BASELINE=PASS');
});
