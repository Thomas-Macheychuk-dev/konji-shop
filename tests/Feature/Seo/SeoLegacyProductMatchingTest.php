<?php

use Illuminate\Support\Facades\Storage;

it('builds product matching evidence without approving redirects', function () {
    Storage::fake('local');

    $legacy = <<<'CSV'
url,path,query,type,legacy_id,fetched,status,redirect_target,canonical,title,h1,meta_robots,index,brand,breadcrumbs,discovery_methods,discovered_from,internal_link_count,fetch_error
https://ortezka.pl/a-id-1,/a-id-1,,product,1,1,200,,https://ortezka.pl/a-id-1,,Exact Brace,,ABC-1,,,,,0,
https://ortezka.pl/b-id-2,/b-id-2,,product,2,1,200,,https://ortezka.pl/b-id-2,,Legacy Medical Product,,9158,,,,,0,
https://ortezka.pl/c-id-3,/c-id-3,,product,3,1,200,,https://ortezka.pl/c-id-3,,Conflict Product,,C-1,,,,,0,
CSV;

    $target = <<<'CSV'
product_id,product_name,product_slug,target_path,product_status,storefront_reachable,published_at,product_deleted_at,external_source,external_id,external_parent_sku,external_parent_sku_matching_key,variant_id,variant_sku,variant_sku_matching_key,variant_status,variant_is_default,variant_deleted_at,primary_category_id,primary_category_name,primary_category_slug,category_ids,category_slugs
10,Exact Brace,exact-brace,/products/exact-brace,active,1,,,supplier,x,ABC-1,abc-1,100,ABC-1,abc-1,active,1,,,,,,,
20,Unrelated Wig,unrelated-wig,/products/unrelated-wig,active,1,,,peruka,y,PERUKA-X,peruka-x,200,9158,9158,active,1,,,,,,,
30,Variant Conflict,variant-conflict,/products/variant-conflict,active,1,,,supplier,z,OTHER,other,300,C-1,c-1,active,1,,,,,,,
31,Parent Conflict,parent-conflict,/products/parent-conflict,active,1,,,supplier,w,C-1,c-1,301,PARENT-V,parent-v,active,1,,,,,,,
CSV;

    Storage::disk('local')->put('matching/legacy.csv', $legacy);
    Storage::disk('local')->put('matching/target.csv', $target);

    $this->artisan('seo:match-legacy-products', [
        '--legacy-csv' => 'matching/legacy.csv',
        '--target-products-csv' => 'matching/target.csv',
        '--save' => 'matching/report.json',
        '--csv' => 'matching/report.csv',
    ])->assertSuccessful();

    $report = json_decode(Storage::disk('local')->get('matching/report.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($report['redirects_approved'])->toBe(0)
        ->and($report['summary']['live_canonical_legacy_products'])->toBe(3)
        ->and($report['summary']['classifications']['exact_identifier_and_name'])->toBe(1)
        ->and($report['summary']['classifications']['variant_candidate'])->toBe(1)
        ->and($report['summary']['classifications']['identifier_conflict'])->toBe(1)
        ->and(collect($report['matches'])->every(fn (array $match): bool => $match['redirect_approved'] === false))->toBeTrue();
});

it('refuses unsafe matching paths', function () {
    $this->artisan('seo:match-legacy-products', [
        '--legacy-csv' => '../legacy.csv',
        '--target-products-csv' => 'matching/target.csv',
    ])->assertFailed();
});
