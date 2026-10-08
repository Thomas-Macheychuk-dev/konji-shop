<?php

declare(strict_types=1);

it('retains two independent production deployment locks while routing through the SEO-07H source gate', function (): void {
    $workflow = (string) file_get_contents(base_path(
        '.github/workflows/deploy-prod.yml',
    ));

    expect($workflow)
        ->toContain(
            'name: Guard SEO-07 659-rule production source HOLD',
        )
        ->toContain(
            'run: bash scripts/deploy/seo07h-production659-source-gate.sh',
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

    expect($workflow)
        ->not->toContain(
            "if: steps.seo07h_gate.outputs.deploy == 'true'",
        )
        ->not->toContain(
            "if: steps.seo03b_gate.outputs.deploy == 'true'",
        );
});

it('accepts only the authorised exact 659 source while withholding automatic deployment', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo07h-production659-source-gate.sh',
    ));

    expect($guard)
        ->toContain('FIRST_AUTHORIZED659_SOURCE_PROMOTION')
        ->toContain('ONGOING_AUTHORIZED659_CI_ONLY')
        ->toContain('SEO07H_SOURCE_GATE=PASS')
        ->toContain('SEO07H_SOURCE_RULES=659')
        ->toContain('SEO07H_LIVE_BASELINE_RULES=400')
        ->toContain('SEO07H_DEPLOYMENT=WITHHELD')
        ->toContain('SEO07H_EXACT_400_PLUS_259_UNION=PASS')
        ->toContain('SEO07H_AUTHORIZATION_CONTRACT=PASS')
        ->toContain('deploy=false')
        ->not->toContain('deploy=true');

    foreach ([
        '8f17139032dc076eb99b895b53c4f6aba8b224fb',
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e',
        'dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184',
        '400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1',
        'f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde',
        '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802',
    ] as $value) {
        expect($guard)->toContain($value);
    }

    expect($guard)
        ->toContain('EXPECTED_PROMOTION_DIFF=')
        ->toContain('PROTECTED_TOUCHES')
        ->toContain('"$WORKFLOW"')
        ->toContain('"$GATE"')
        ->toContain('"$PREFLIGHT"')
        ->toContain('"$MAP"')
        ->toContain('"$EXTRA_MAP"')
        ->toContain('"$MANIFEST"')
        ->toContain('"$PROOF"')
        ->toContain('"$AUTH"')
        ->toContain('"$HISTORICAL_GATE"')
        ->toContain('"$HISTORICAL_PREFLIGHT"');
});

it('pins the exact SEO-07H first-transition source boundary', function (): void {
    $guard = (string) file_get_contents(base_path(
        'scripts/deploy/seo07h-production659-source-gate.sh',
    ));

    foreach ([
        '.github/workflows/deploy-prod.yml',
        'docker/nginx/generated/legacy-seo-product-map.conf',
        'resources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json',
        'scripts/deploy/seo07h-production659-preflight.sh',
        'scripts/deploy/seo07h-production659-source-gate.sh',
        'tests/Feature/Seo/Seo07Production659PreflightContractTest.php',
        'tests/Feature/Seo/SeoLegacyExactNameOnlyDecisionLedgerTest.php',
        'tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectSchemaV4Test.php',
        'tests/Feature/Seo/SeoLegacyProductRedirectSchemaV5Test.php',
        'tests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php',
    ] as $path) {
        expect($guard)->toContain($path);
    }

    expect($guard)
        ->toContain('GITHUB_EVENT_NAME')
        ->toContain(
            'Manual dispatch and non-push events are prohibited.',
        );
});

it('keeps the historical SEO-06 live-400 governance artifacts intact', function (): void {
    $historicalGate = (string) file_get_contents(base_path(
        'scripts/deploy/seo03b-p6s-source-gate.sh',
    ));

    $historicalPreflight = (string) file_get_contents(base_path(
        'scripts/deploy/production-release-preflight.sh',
    ));

    expect($historicalGate)
        ->toContain('SEO06_SOURCE_RULES=400')
        ->toContain('SEO06_LIVE_BASELINE_RULES=400')
        ->toContain('SEO06_EXACT_64_PLUS_336_UNION=PASS')
        ->toContain(
            '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        );

    expect($historicalPreflight)
        ->toContain('--current-live400-baseline')
        ->toContain('SEO06_ACTIVE_PRODUCTION_RULES=400')
        ->toContain(
            '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009',
        );
});
