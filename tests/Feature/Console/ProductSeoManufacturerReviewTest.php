<?php

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';

it('validates manufacturer reviewed metadata as a disjoint third repair with both prior receipts', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/manufacturer-review-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    new ProductSeoEditorialRepair($manifest);
    $ids = array_column($manifest['entries'], 'id');
    expect($ids)->toHaveCount(30);
    expect($manifest['previous_batches'])->toHaveCount(2);
    foreach ($manifest['previous_batches'] as $batch) {
        $previous = json_decode(file_get_contents(base_path('scripts/seo/'.$batch['manifest'])), true, flags: JSON_THROW_ON_ERROR);
        expect(array_intersect($ids, array_column($previous['entries'], 'id')))->toBe([]);
    }
    $entries = array_column($manifest['entries'], null, 'id');
    expect($entries[13721]['after']['seo_title'])->toBe('Medisorb A – opatrunek z alginianu wapnia');
    expect($entries[13961]['after']['seo_description'])->not->toContain('hydrożel');
    expect(array_keys($entries[13961]['after']))->toBe(['seo_title', 'seo_description']);
    expect($manifest['previous_batches'][1]['plan_sha256'])->toBe('882ffe06d2236e163b9b1c6a64d65708fd569e331f6a36d3c4b4050f4d7f9857');
});
