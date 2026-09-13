<?php

use Illuminate\Support\Facades\Storage;

it('prepares review cohorts without approving redirects', function () {
    Storage::fake('local');

    $evidence = [
        'schema_version' => 1,
        'redirects_approved' => 0,
        'matches' => [
            [
                'legacy_id' => '1', 'legacy_url' => 'https://ortezka.pl/a-id-1', 'legacy_path' => '/a-id-1',
                'legacy_h1' => 'Exact Brace ABC-1', 'legacy_index' => 'ABC-1', 'classification' => 'exact_identifier_and_name',
                'candidate_product_id' => '10', 'candidate_product_name' => 'Exact Brace ABC-1', 'candidate_target_path' => '/products/exact-brace',
                'candidate_product_status' => 'active', 'candidate_storefront_reachable' => true, 'candidate_active_variant_count' => 1,
                'candidate_external_source' => 'supplier', 'candidate_external_parent_sku' => 'ABC-1',
                'variant_candidate_product_id' => '10', 'variant_candidate_variant_id' => '100', 'variant_candidate_variant_sku' => 'ABC-1',
                'variant_candidate_variant_status' => 'active', 'parent_candidate_product_id' => '10', 'name_candidate_product_id' => '10',
            ],
            [
                'legacy_id' => '2', 'legacy_url' => 'https://ortezka.pl/b-id-2', 'legacy_path' => '/b-id-2',
                'legacy_h1' => 'Orteza kolana AT2', 'legacy_index' => 'AT2', 'classification' => 'identifier_agreement',
                'candidate_product_id' => '20', 'candidate_product_name' => 'Stabilizator kolana AT2', 'candidate_target_path' => '/products/at2',
                'candidate_product_status' => 'active', 'candidate_storefront_reachable' => true, 'candidate_active_variant_count' => 1,
                'candidate_external_source' => 'supplier', 'candidate_external_parent_sku' => 'AT2',
                'variant_candidate_product_id' => '20', 'variant_candidate_variant_id' => '200', 'variant_candidate_variant_sku' => 'AT2',
                'variant_candidate_variant_status' => 'active', 'parent_candidate_product_id' => '20', 'name_candidate_product_id' => null,
            ],
            [
                'legacy_id' => '3', 'legacy_url' => 'https://ortezka.pl/c-id-3', 'legacy_path' => '/c-id-3',
                'legacy_h1' => 'Aparat na staw skokowy 4009', 'legacy_index' => '4009', 'classification' => 'identifier_agreement',
                'candidate_product_id' => '30', 'candidate_product_name' => 'Biustonosz LANA 4009', 'candidate_target_path' => '/products/lana-4009',
                'candidate_product_status' => 'active', 'candidate_storefront_reachable' => true, 'candidate_active_variant_count' => 1,
                'candidate_external_source' => 'supplier', 'candidate_external_parent_sku' => '4009',
                'variant_candidate_product_id' => '30', 'variant_candidate_variant_id' => '300', 'variant_candidate_variant_sku' => '4009',
                'variant_candidate_variant_status' => 'active', 'parent_candidate_product_id' => '30', 'name_candidate_product_id' => null,
            ],
            [
                'legacy_id' => '4', 'legacy_url' => 'https://ortezka.pl/d-id-4', 'legacy_path' => '/d-id-4',
                'legacy_h1' => 'Draft Exact', 'legacy_index' => 'D-4', 'classification' => 'exact_identifier_and_name',
                'candidate_product_id' => '40', 'candidate_product_name' => 'Draft Exact', 'candidate_target_path' => '/products/draft-exact',
                'candidate_product_status' => 'draft', 'candidate_storefront_reachable' => false, 'candidate_active_variant_count' => 0,
                'candidate_external_source' => 'supplier', 'candidate_external_parent_sku' => 'D-4',
                'variant_candidate_product_id' => '40', 'variant_candidate_variant_id' => '400', 'variant_candidate_variant_sku' => 'D-4',
                'variant_candidate_variant_status' => 'draft', 'parent_candidate_product_id' => '40', 'name_candidate_product_id' => '40',
            ],
            [
                'legacy_id' => '5', 'legacy_url' => 'https://ortezka.pl/e-id-5', 'legacy_path' => '/e-id-5',
                'legacy_h1' => 'Conflict', 'legacy_index' => 'C-5', 'classification' => 'identifier_conflict',
                'candidate_product_id' => null, 'candidate_product_name' => null, 'candidate_target_path' => null,
                'candidate_product_status' => null, 'candidate_storefront_reachable' => null, 'candidate_active_variant_count' => null,
                'candidate_external_source' => null, 'candidate_external_parent_sku' => null,
                'variant_candidate_product_id' => '50', 'variant_candidate_variant_id' => '500', 'variant_candidate_variant_sku' => 'C-5',
                'variant_candidate_variant_status' => 'active', 'parent_candidate_product_id' => '51', 'name_candidate_product_id' => null,
            ],
        ],
    ];

    $legacy = <<<'CSV'
url,path,query,type,legacy_id,fetched,status,redirect_target,canonical,title,h1,meta_robots,index,brand,breadcrumbs,discovery_methods,discovered_from,internal_link_count,fetch_error
https://ortezka.pl/a-id-1,/a-id-1,,product,1,1,200,,,,Exact Brace ABC-1,,ABC-1,,,,,0,
https://ortezka.pl/a-old-id-1,/a-old-id-1,,product,1,1,301,https://ortezka.pl/a-id-1,,,,,,,,,,0,
https://ortezka.pl/b-id-2,/b-id-2,,product,2,1,200,,,,Orteza kolana AT2,,AT2,,,,,0,
https://ortezka.pl/c-id-3,/c-id-3,,product,3,1,200,,,,Aparat na staw skokowy 4009,,4009,,,,,0,
https://ortezka.pl/d-id-4,/d-id-4,,product,4,1,200,,,,Draft Exact,,D-4,,,,,0,
https://ortezka.pl/e-id-5,/e-id-5,,product,5,1,200,,,,Conflict,,C-5,,,,,0,
CSV;

    Storage::disk('local')->put('matching/evidence.json', json_encode($evidence, JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('matching/legacy.csv', $legacy);

    $this->artisan('seo:prepare-legacy-product-approval-review', [
        '--evidence' => 'matching/evidence.json',
        '--legacy-csv' => 'matching/legacy.csv',
        '--save' => 'matching/review.json',
        '--csv' => 'matching/review.csv',
    ])->assertSuccessful();

    $report = json_decode(Storage::disk('local')->get('matching/review.json'), true, flags: JSON_THROW_ON_ERROR);
    $byId = collect($report['records'])->keyBy('legacy_id');

    expect($report['redirects_approved'])->toBe(0)
        ->and($report['summary']['approval_candidates'])->toBe(1)
        ->and($report['summary']['approval_candidate_source_paths'])->toBe(2)
        ->and($byId['1']['review_class'])->toBe('approval_candidate_exact_identifier_and_name')
        ->and($byId['1']['legacy_source_path_count'])->toBe(2)
        ->and($byId['2']['review_class'])->toBe('review_identifier_agreement_semantic_support')
        ->and($byId['3']['review_class'])->toBe('review_identifier_agreement_name_divergence')
        ->and($byId['4']['review_class'])->toBe('blocked_target_draft_strong')
        ->and($byId['5']['review_class'])->toBe('identifier_conflict')
        ->and(collect($report['records'])->every(fn (array $record): bool => $record['redirect_approved'] === false))->toBeTrue();
});

it('refuses unsafe approval-review paths', function () {
    $this->artisan('seo:prepare-legacy-product-approval-review', [
        '--evidence' => '../evidence.json',
        '--legacy-csv' => 'matching/legacy.csv',
    ])->assertFailed();
});
