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
            .'seo06-staging-candidate-full.conf'
        )
    );
});

it('keeps the 400-rule SEO-06 candidate isolated to staging while production remains exactly approved-64', function (): void {
    $manifest =
        'resources/seo/ortezka/review/'
        .'seo-06c-20261006/'
        .'approved-400-manifest.json';

    $temporary =
        'storage/framework/testing/'
        .'seo06-staging-candidate-full.conf';

    expect(
        Artisan::call(
            'seo:generate-legacy-product-redirect-map',
            [
                '--manifest' => $manifest,
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

    $productionRules =
        seo06StagingMapRules($productionMap);

    $extraRules =
        seo06StagingMapRules($stagingExtraMap);

    $candidateRules =
        seo06StagingMapRules($fullCandidate);

    expect($productionRules)
        ->toHaveCount(64)
        ->and(hash(
            'sha256',
            $productionMap,
        ))
        ->toBe(
            '209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01'
        )
        ->and($extraRules)
        ->toHaveCount(336)
        ->and($candidateRules)
        ->toHaveCount(400);

    foreach ($productionRules as $source => $target) {
        expect($candidateRules[$source] ?? null)
            ->toBe($target)
            ->and($extraRules)
            ->not->toHaveKey($source);
    }

    $combined = $productionRules + $extraRules;

    ksort($combined, SORT_STRING);
    ksort($candidateRules, SORT_STRING);

    expect($combined)
        ->toBe($candidateRules);

    $nginx = (string) file_get_contents(
        base_path(
            'docker/nginx/production.conf'
        )
    );

    $blocks = seo06NginxServerBlocks($nginx);

    $stagingBlocks = array_values(
        array_filter(
            $blocks,
            static fn (string $block): bool =>
                str_contains(
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
