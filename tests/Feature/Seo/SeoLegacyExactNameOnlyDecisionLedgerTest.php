<?php

declare(strict_types=1);

use App\Support\Seo\ExactNameOnlyRedirectDecisionLedger;

it('accepts exactly the frozen SEO-07 owner-approved exact-name-only cohort', function (): void {
    $records = app(
        ExactNameOnlyRedirectDecisionLedger::class,
    )->approvedRecords();

    expect($records)->toHaveCount(149);

    $ids = [];
    $sources = [];
    $targets = [];
    $zeroVariants = 0;

    foreach ($records as $record) {
        $id = (string) $record['legacy_id'];

        expect(isset($ids[$id]))
            ->toBeFalse()
            ->and($record['classification'])
            ->toBe('exact_name_only')
            ->and($record['decision'])
            ->toBe('APPROVE_301')
            ->and($record['approved'])
            ->toBeTrue()
            ->and($record['redirect_approved'])
            ->toBeTrue()
            ->and($record['approval_basis'])
            ->toBe(
                ExactNameOnlyRedirectDecisionLedger::APPROVAL_BASIS,
            )
            ->and($record['approved_by'])
            ->toBe('Tomasz Maciejczuk')
            ->and($record['approval_reference'])
            ->toBe(
                ExactNameOnlyRedirectDecisionLedger::DECISION_REFERENCE,
            )
            ->and($record['target_product_status'])
            ->toBe('active')
            ->and($record['target_storefront_reachable'])
            ->toBeTrue()
            ->and($record['staging_target_validation'])
            ->toBe('PASS');

        $ids[$id] = true;

        $target = $record['target_path'];

        expect(isset($targets[$target]))
            ->toBeFalse();

        $targets[$target] = true;

        if (
            $record['target_active_variant_count']
            === 0
        ) {
            $zeroVariants++;
        }

        foreach ($record['source_paths'] as $source) {
            expect(isset($sources[$source]))
                ->toBeFalse()
                ->and($source)
                ->not->toBe($target);

            $sources[$source] = $target;
        }
    }

    expect($ids)->toHaveCount(149)
        ->and($targets)->toHaveCount(149)
        ->and($sources)->toHaveCount(259)
        ->and($zeroVariants)->toBe(75)
        ->and(array_intersect(
            array_keys($sources),
            array_keys($targets),
        ))->toBe([]);
});

it('pins every immutable SEO-07 approval dependency', function (): void {
    $files = [
        'resources/seo/ortezka/review/seo-07b-20261007/'
            .'exact-name-only-candidates-149.json'
            => ExactNameOnlyRedirectDecisionLedger::SOURCE_MANIFEST_SHA256,

        'resources/seo/ortezka/review/seo-07d-20261007/'
            .'review-safe-149.json'
            => ExactNameOnlyRedirectDecisionLedger::REVIEW_SHA256,

        'resources/seo/ortezka/review/seo-07e-20261007/'
            .'owner-decision-149.json'
            => ExactNameOnlyRedirectDecisionLedger::DECISION_SHA256,

        'resources/seo/ortezka/review/seo-06c-20261006/'
            .'approved-400-manifest.json'
            => ExactNameOnlyRedirectDecisionLedger::BASE_MANIFEST_SHA256,
    ];

    foreach ($files as $path => $expected) {
        expect(hash_file(
            'sha256',
            base_path($path),
        ))->toBe($expected);
    }

    expect(hash_file(
        'sha256',
        base_path(
            'docker/nginx/generated/'
            .'legacy-seo-product-map.conf',
        ),
    ))->toBe(
        ExactNameOnlyRedirectDecisionLedger::PRODUCTION_MAP_SHA256,
    );
});
