<?php

declare(strict_types=1);

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use App\Support\Seo\ParentProductRedirectDecisionLedger;

it('limits the accepted parent-product decision to the exact 18/22 cohort and preserves four HOLDs', function (): void {
    $reviewed = app(ParentProductRedirectApprovalPolicy::class)->reviewedRecords();
    $approved = app(ParentProductRedirectDecisionLedger::class)->approvedRecords($reviewed);

    expect($reviewed)->toHaveCount(22)
        ->and($approved)->toHaveCount(18)
        ->and(array_sum(array_map(
            static fn (array $record): int => count($record['source_paths']),
            $approved,
        )))->toBe(22)
        ->and(array_map('strval', array_keys($approved)))->not->toContain('6632', '6646', '6664', '6687');

    $held = array_map('strval', array_values(array_diff(array_keys($reviewed), array_keys($approved))));
    sort($held);

    expect($held)->toBe(['6632', '6646', '6664', '6687']);

    $decision = json_decode(
        (string) file_get_contents(base_path(
            'resources/seo/ortezka/review/parent-product-decisions-20261003.json',
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($decision['deployment_authorized'])->toBeFalse()
        ->and($decision['redirects_installed_by_this_record'])->toBe(0)
        ->and($decision['counts']['combined_with_original_approved_products'])->toBe(41)
        ->and($decision['counts']['combined_with_original_source_paths'])->toBe(58);
});
