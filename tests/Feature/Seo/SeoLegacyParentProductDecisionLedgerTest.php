<?php

declare(strict_types=1);

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use App\Support\Seo\ParentProductRedirectDecisionLedger;

it('accepts the complete 22-product parent cohort while deployment remains locked', function (): void {
    $reviewed = app(
        ParentProductRedirectApprovalPolicy::class,
    )->reviewedRecords();

    $approved = app(
        ParentProductRedirectDecisionLedger::class,
    )->approvedRecords($reviewed);

    expect($reviewed)->toHaveCount(22)
        ->and($approved)->toHaveCount(22)
        ->and(array_sum(array_map(
            static fn (array $record): int => count($record['source_paths']),
            $approved,
        )))->toBe(28);

    $approvedIds = array_map(
        'strval',
        array_keys($approved),
    );

    sort($approvedIds);

    foreach (['6632', '6646', '6664', '6687'] as $legacyId) {
        expect($approvedIds)->toContain($legacyId);
    }

    $held = array_map(
        'strval',
        array_values(
            array_diff(
                array_keys($reviewed),
                array_keys($approved),
            ),
        ),
    );

    expect($held)->toBe([]);

    $decision = json_decode(
        (string) file_get_contents(base_path(
            'resources/seo/ortezka/review/parent-product-decisions-20261006.json',
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($decision['decision_record_date'])->toBe('2026-10-06')
        ->and($decision['decision_source'])->toBe('User message: Approved')
        ->and($decision['deployment_authorized'])->toBeFalse()
        ->and($decision['redirects_installed_by_this_record'])->toBe(0)
        ->and($decision['counts']['approved_products'])->toBe(22)
        ->and($decision['counts']['approved_source_paths'])->toBe(28)
        ->and($decision['counts']['held_products'])->toBe(0)
        ->and($decision['counts']['held_source_paths'])->toBe(0)
        ->and($decision['counts']['combined_with_original_approved_products'])->toBe(45)
        ->and($decision['counts']['combined_with_original_source_paths'])->toBe(64);
});
