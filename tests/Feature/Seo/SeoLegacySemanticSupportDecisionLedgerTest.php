<?php

declare(strict_types=1);

use App\Support\Seo\SemanticSupportRedirectDecisionLedger;

it('accepts exactly the frozen SEO-06 owner-approved semantic-support cohort', function (): void {
    $records = app(
        SemanticSupportRedirectDecisionLedger::class,
    )->approvedRecords();

    expect($records)->toHaveCount(221);

    $sources = [];
    $targets = [];
    $ids = [];

    foreach ($records as $record) {
        $id = (string) $record['legacy_id'];

        expect(isset($ids[$id]))->toBeFalse()
            ->and($record['approved'])->toBeTrue()
            ->and($record['decision'])->toBe('APPROVE_301')
            ->and($record['classification'])->toBe('identifier_agreement')
            ->and($record['review_class'])
            ->toBe('review_identifier_agreement_semantic_support')
            ->and($record['target_product_status'])->toBe('active')
            ->and($record['target_storefront_reachable'])->toBeTrue()
            ->and((int) $record['shared_semantic_token_count'])
            ->toBeGreaterThanOrEqual(1);

        $ids[$id] = true;
        $target = $record['target_path'];

        expect(isset($targets[$target]))->toBeFalse();

        $targets[$target] = true;

        foreach ($record['active_variant_counts'] as $count) {
            expect($count)->toBeGreaterThanOrEqual(1);
        }

        foreach ($record['source_paths'] as $source) {
            expect(isset($sources[$source]))->toBeFalse()
                ->and($source)->not->toBe($target);

            $sources[$source] = $target;
        }
    }

    expect($ids)->toHaveCount(221)
        ->and($targets)->toHaveCount(221)
        ->and($sources)->toHaveCount(336)
        ->and(array_intersect(
            array_keys($sources),
            array_keys($targets),
        ))->toBe([]);
});

it('pins the immutable SEO-06 review and owner-decision hashes', function (): void {
    expect(hash_file(
        'sha256',
        base_path(
            'resources/seo/ortezka/review/seo-06b-20261006/'
            .'semantic-support-approved-221.json',
        ),
    ))->toBe(
        SemanticSupportRedirectDecisionLedger::REVIEW_SHA256,
    );

    expect(hash_file(
        'sha256',
        base_path(
            'resources/seo/ortezka/review/seo-06b-20261006/'
            .'owner-decision-221.json',
        ),
    ))->toBe(
        SemanticSupportRedirectDecisionLedger::DECISION_SHA256,
    );

    expect(hash_file(
        'sha256',
        base_path(
            'resources/seo/ortezka/review/seo-05h-20261006/'
            .'approved-64-manifest.json',
        ),
    ))->toBe(
        SemanticSupportRedirectDecisionLedger::BASE_MANIFEST_SHA256,
    );
});
