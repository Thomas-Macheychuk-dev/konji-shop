<?php

it('locks the first human-approved legacy product redirect cohort', function () {
    $path = base_path('resources/seo/ortezka/product-redirect-approvals.json');

    expect(is_file($path))->toBeTrue();

    $manifest = json_decode(
        (string) file_get_contents($path),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['schema_version'])->toBe(1)
        ->and($manifest['approved_product_count'])->toBe(23)
        ->and($manifest['approved_source_path_count'])->toBe(36)
        ->and($manifest['redirects_installed'])->toBe(0)
        ->and($manifest['records'])->toHaveCount(23);

    $sourcePaths = [];
    $targetPaths = [];

    foreach ($manifest['records'] as $record) {
        expect($record['decision'])->toBe('APPROVE_301')
            ->and($record['approved'])->toBeTrue()
            ->and($record['target_product_status'])->toBe('active')
            ->and($record['matched_variant_status'])->toBe('active')
            ->and($record['legacy_index'])->toBe($record['matched_variant_sku'])
            ->and($record['legacy_index'])->toBe($record['target_external_parent_sku'])
            ->and($record['target_path'])->toStartWith('/products/')
            ->and($record['source_paths'])->not->toBeEmpty();

        $targetPaths[] = $record['target_path'];

        foreach ($record['source_paths'] as $sourcePath) {
            expect($sourcePath)->toStartWith('/')
                ->and(str_contains($sourcePath, '?'))->toBeFalse();

            $sourcePaths[] = $sourcePath;
        }
    }

    expect($sourcePaths)->toHaveCount(36)
        ->and(array_unique($sourcePaths))->toHaveCount(36)
        ->and($targetPaths)->toHaveCount(23)
        ->and(array_unique($targetPaths))->toHaveCount(23);
});
