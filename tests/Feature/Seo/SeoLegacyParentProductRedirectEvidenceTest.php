<?php

declare(strict_types=1);

it('locks the SEO-03B parent-product candidate cohort without authorising redirects', function (): void {
    $directory = base_path('resources/seo/ortezka/review');

    $reviewPath = $directory.'/parent-product-redirect-review-20261003.json';
    $validationPath = $directory.'/parent-target-validation-20261003.json';
    $originalPath = base_path('resources/seo/ortezka/product-redirect-approvals.json');

    expect(hash_file('sha256', $reviewPath))
        ->toBe('f124143b75f74deb3034b9d5544c1696b64254ebfab751dc0e86aaff33e96edc');

    expect(hash_file('sha256', $validationPath))
        ->toBe('811248a3708f7d3be504a330762fa04e4e7838062c72ca8050c198ffcafe69e3');

    $review = json_decode(
        (string) file_get_contents($reviewPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $validation = json_decode(
        (string) file_get_contents($validationPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $original = json_decode(
        (string) file_get_contents($originalPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($original['schema_version'])->toBe(1)
        ->and($original['approved_product_count'])->toBe(23)
        ->and($original['approved_source_path_count'])->toBe(36)
        ->and($review['candidate_product_count'])->toBe(22)
        ->and($review['candidate_source_path_count'])->toBe(28)
        ->and($review['approved_product_count'])->toBe(0)
        ->and($review['redirects_installed'])->toBe(0)
        ->and($review['validation_only'])->toBeTrue()
        ->and($validation['summary']['passed'])->toBe(22)
        ->and($validation['summary']['failed'])->toBe(0);

    $originalSources = [];
    $originalTargets = [];

    foreach ($original['records'] as $record) {
        expect($record['approved'])->toBeTrue()
            ->and($record['decision'])->toBe('APPROVE_301')
            ->and($record['matched_variant_status'])->toBe('active');

        $originalTargets[] = $record['target_path'];

        foreach ($record['source_paths'] as $path) {
            $originalSources[] = $path;
        }
    }

    $validated = [];

    foreach ($validation['records'] as $record) {
        $validated[(string) $record['legacy_id']] = $record;
    }

    $newSources = [];
    $newTargets = [];

    foreach ($review['records'] as $record) {
        $legacyId = (string) $record['legacy_id'];

        expect($record['approved'])->toBeFalse()
            ->and($record['decision'])->toBe('PENDING_HUMAN_APPROVAL')
            ->and($record['approval_basis'])
            ->toBe('exact_parent_sku_and_name_with_active_variants')
            ->and($record['target_validation'])->toBe('PASS')
            ->and($record['active_variant_count'])->toBeGreaterThan(0);

        expect(
            strtolower(trim((string) $record['legacy_index']))
        )->toBe(
            strtolower(trim((string) $record['target_parent_sku']))
        );

        expect(isset($validated[$legacyId]))->toBeTrue();

        $http = $validated[$legacyId];

        expect($http['failures'])->toBe([])
            ->and($http['http_status'])->toBe('200')
            ->and($http['target_path'])->toBe($record['target_path'])
            ->and($http['canonical'])->toBe(
                'https://ortezka.pl'.$record['target_path']
            );

        $reviewPaths = $record['source_paths'];
        $validatedPaths = $http['source_paths'];

        sort($reviewPaths);
        sort($validatedPaths);

        expect($reviewPaths)->toBe($validatedPaths);

        $newTargets[] = $record['target_path'];

        foreach ($record['source_paths'] as $path) {
            $newSources[] = $path;
        }
    }

    expect($originalSources)->toHaveCount(36)
        ->and($newSources)->toHaveCount(28)
        ->and(array_unique($newSources))->toHaveCount(28)
        ->and(array_unique($newTargets))->toHaveCount(22)
        ->and(array_intersect($newSources, $originalSources))->toBe([])
        ->and(array_intersect($newSources, $originalTargets))->toBe([])
        ->and(array_intersect($newSources, $newTargets))->toBe([]);
});
