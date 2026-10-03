<?php

declare(strict_types=1);

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use RuntimeException;

it('loads exactly the frozen 22-product parent SKU approval candidates', function (): void {
    $records = app(ParentProductRedirectApprovalPolicy::class)->reviewedRecords();

    expect($records)->toHaveCount(22);

    $sources = [];

    foreach ($records as $record) {
        expect($record['approved'])->toBeFalse()
            ->and($record['decision'])->toBe('PENDING_HUMAN_APPROVAL')
            ->and($record['approval_basis'])
            ->toBe(ParentProductRedirectApprovalPolicy::APPROVAL_BASIS)
            ->and($record['active_variant_count'])->toBeGreaterThan(0);

        foreach ($record['source_paths'] as $source) {
            $sources[] = $source;
        }
    }

    expect($sources)->toHaveCount(28)
        ->and(array_unique($sources))->toHaveCount(28);
});

it('accepts only an explicitly approved record with unchanged matching evidence', function (): void {
    $policy = app(ParentProductRedirectApprovalPolicy::class);

    $frozen = array_values($policy->reviewedRecords())[0];

    $approved = $frozen;
    $approved['approved'] = true;
    $approved['decision'] = 'APPROVE_301';
    $approved['approved_by'] = 'synthetic-test-reviewer';
    $approved['approved_at'] = '2026-10-03T16:00:00+02:00';
    $approved['approval_reference'] = 'SEO-03B-SYNTHETIC-TEST-ONLY';

    expect(fn () => $policy->assertApprovedRecord($approved, $frozen))
        ->not->toThrow(RuntimeException::class);

    $pending = $frozen;

    expect(fn () => $policy->assertApprovedRecord($pending, $frozen))
        ->toThrow(RuntimeException::class);

    $missingReviewer = $approved;
    unset($missingReviewer['approved_by']);

    expect(fn () => $policy->assertApprovedRecord($missingReviewer, $frozen))
        ->toThrow(RuntimeException::class);

    $wrongTarget = $approved;
    $wrongTarget['target_path'] = '/products/unrelated-product';

    expect(fn () => $policy->assertApprovedRecord($wrongTarget, $frozen))
        ->toThrow(RuntimeException::class);

    $changedIdentifier = $approved;
    $changedIdentifier['legacy_index'] = 'DIFFERENT-SKU';

    expect(fn () => $policy->assertApprovedRecord($changedIdentifier, $frozen))
        ->toThrow(RuntimeException::class);

    $changedAvailability = $approved;
    $changedAvailability['active_variant_count'] = 0;

    expect(fn () => $policy->assertApprovedRecord($changedAvailability, $frozen))
        ->toThrow(RuntimeException::class);

    $changedSources = $approved;
    $changedSources['source_paths'][] = '/unreviewed-extra-source';

    expect(fn () => $policy->assertApprovedRecord($changedSources, $frozen))
        ->toThrow(RuntimeException::class);
});
