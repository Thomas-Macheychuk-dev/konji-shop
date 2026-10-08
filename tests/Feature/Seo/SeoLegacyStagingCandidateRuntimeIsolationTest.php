<?php

use Illuminate\Support\Facades\Artisan;

function seo06StagingMapRules(string $contents): array
{
    preg_match_all(
        '/^\s*"([^"]+)"\s+"([^"]+)";\s*$/m',
        $contents,
        $matches,
        PREG_SET_ORDER,
    );

    $rules = [];

    foreach ($matches as $match) {
        expect($rules)->not->toHaveKey($match[1]);

        $rules[$match[1]] = $match[2];
    }

    ksort($rules, SORT_STRING);

    return $rules;
}

function seo06NginxServerBlocks(string $contents): array
{
    $blocks = [];
    $offset = 0;

    while (($start = strpos(
        $contents,
        'server {',
        $offset,
    )) !== false) {
        $brace = strpos(
            $contents,
            '{',
            $start,
        );

        expect($brace)->not->toBeFalse();

        $depth = 0;
        $end = null;
        $length = strlen($contents);

        for ($i = $brace; $i < $length; $i++) {
            if ($contents[$i] === '{') {
                $depth++;
            } elseif ($contents[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    $end = $i + 1;

                    break;
                }
            }
        }

        expect($end)->not->toBeNull();

        $blocks[] = substr(
            $contents,
            $start,
            $end - $start,
        );

        $offset = $end;
    }

    return $blocks;
}

afterEach(function (): void {
    @unlink(
        base_path(
            'storage/framework/testing/'
            .'seo07-staging-candidate-full.conf'
        )
    );
});

it('keeps the staging overlay isolated while the committed production source is the exact approved-659 cohort', function (): void {
    $manifest659 =
        'resources/seo/ortezka/review/'
        .'seo-07f-20261007/'
        .'approved-659-manifest.json';

    $manifest64 =
        'resources/seo/ortezka/review/'
        .'seo-05h-20261006/'
        .'approved-64-manifest.json';

    $temporary =
        'storage/framework/testing/'
        .'seo07-staging-candidate-full.conf';

    expect(
        Artisan::call(
            'seo:generate-legacy-product-redirect-map',
            [
                '--manifest' => $manifest659,
                '--output' => $temporary,
            ],
        )
    )->toBe(0);

    $productionMap = (string) file_get_contents(
        base_path(
            'docker/nginx/generated/'
            .'legacy-seo-product-map.conf'
        )
    );

    $stagingExtraMap = (string) file_get_contents(
        base_path(
            'docker/nginx/generated/'
            .'legacy-seo-staging-extra-map.conf'
        )
    );

    $fullCandidate = (string) file_get_contents(
        base_path($temporary)
    );

    $baseManifest = json_decode(
        (string) file_get_contents(
            base_path($manifest64)
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $baseRules = [];

    foreach ($baseManifest['records'] as $record) {
        foreach ($record['source_paths'] as $source) {
            expect($baseRules)
                ->not->toHaveKey($source);

            $baseRules[$source] =
                $record['target_path'];
        }
    }

    ksort($baseRules, SORT_STRING);

    $productionRules =
        seo06StagingMapRules($productionMap);

    $extraRules =
        seo06StagingMapRules($stagingExtraMap);

    $candidateRules =
        seo06StagingMapRules($fullCandidate);

    expect($baseRules)
        ->toHaveCount(64)
        ->and($productionRules)
        ->toHaveCount(659)
        ->and(hash(
            'sha256',
            $productionMap,
        ))
        ->toBe(
            '2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e'
        )
        ->and($extraRules)
        ->toHaveCount(336)
        ->and(hash(
            'sha256',
            $stagingExtraMap,
        ))
        ->toBe(
            '35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802'
        )
        ->and($candidateRules)
        ->toHaveCount(659);

    foreach ($baseRules as $source => $target) {
        expect($productionRules[$source] ?? null)
            ->toBe($target)
            ->and($extraRules)
            ->not->toHaveKey($source);
    }

    foreach ($extraRules as $source => $target) {
        expect($productionRules[$source] ?? null)
            ->toBe($target)
            ->and($baseRules)
            ->not->toHaveKey($source);
    }

    $historical400 = $baseRules + $extraRules;

    expect($historical400)->toHaveCount(400);

    foreach ($historical400 as $source => $target) {
        expect($productionRules[$source] ?? null)
            ->toBe($target);
    }

    ksort($productionRules, SORT_STRING);
    ksort($candidateRules, SORT_STRING);

    expect($candidateRules)
        ->toBe($productionRules);

    $nginx = (string) file_get_contents(
        base_path(
            'docker/nginx/production.conf'
        )
    );

    $blocks = seo06NginxServerBlocks($nginx);

    $stagingBlocks = array_values(
        array_filter(
            $blocks,
            static fn (string $block): bool => str_contains(
                $block,
                'server_name staging.ortezka.pl;',
            ),
        ),
    );

    expect($stagingBlocks)->toHaveCount(2);

    foreach ($blocks as $block) {
        $isStaging = str_contains(
            $block,
            'server_name staging.ortezka.pl;',
        );

        if ($isStaging) {
            expect($block)
                ->toContain(
                    'include /etc/nginx/legacy-seo/runtime/'
                    .'20-staging-candidate-redirects.conf;'
                )
                ->toContain(
                    'include /etc/nginx/legacy-seo/runtime/'
                    .'10-product-redirects.conf;'
                );

            continue;
        }

        expect($block)->not->toContain(
            '20-staging-candidate-redirects.conf'
        );
    }

    expect(
        substr_count(
            $nginx,
            'include /etc/nginx/legacy-seo/runtime/'
            .'20-staging-candidate-redirects.conf;',
        )
    )->toBe(2);

    $available = (string) file_get_contents(
        base_path(
            'docker/nginx/legacy-seo/available/'
            .'20-staging-candidate-redirects-enabled.conf'
        )
    );

    expect($available)
        ->toContain(
            '$legacy_seo_staging_extra_redirect_target'
        )
        ->toContain(
            'https://staging.ortezka.pl'
        )
        ->not->toContain(
            'https://ortezka.pl'
        );

    $startup = (string) file_get_contents(
        base_path(
            'docker/nginx/start-production.sh'
        )
    );

    expect($startup)
        ->toContain(
            'LEGACY_SEO_STAGING_CANDIDATE_ENABLED'
        )
        ->toContain(
            '20-staging-candidate-redirects.conf'
        );

    $compose = (string) file_get_contents(
        base_path('docker-compose.prod.yml')
    );

    expect($compose)->toContain(
        'LEGACY_SEO_STAGING_CANDIDATE_ENABLED: '
        .'"${LEGACY_SEO_STAGING_CANDIDATE_ENABLED:-false}"'
    );

    $dockerfile = (string) file_get_contents(
        base_path('Dockerfile')
    );

    expect($dockerfile)
        ->toContain(
            'legacy-seo-staging-extra-map.conf'
        )
        ->toContain(
            '20-staging-candidate-redirects-enabled.conf'
        );
});
