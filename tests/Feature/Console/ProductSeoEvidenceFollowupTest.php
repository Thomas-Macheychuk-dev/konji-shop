<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';

uses(RefreshDatabase::class);

it('applies the evidence followup without changing source fields and can restore the old metadata', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/evidence-followup-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/evidence-followup-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    foreach ($manifest['entries'] as $entry) {
        Product::query()->forceCreate(['id' => $entry['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + [
            'description' => $fixtures[$entry['id']]['description'], 'short_description' => $fixtures[$entry['id']]['short_description'],
        ]);
    }
    $before = DB::table('products')->orderBy('id')->get()->all();
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['changed_products' => 23, 'description_updates' => 23, 'title_updates' => 5]);
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_editorial_products'])->toBe(23);
    expect($repair->verifyRenderedMetadata())->toBe(23);
    foreach ($manifest['entries'] as $entry) {
        $row = DB::table('products')->find($entry['id']);
        expect($row->description)->toBe($fixtures[$entry['id']]['description']);
        expect($row->short_description)->toBe($fixtures[$entry['id']]['short_description']);
    }
    $repair->rollback($plan);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});
