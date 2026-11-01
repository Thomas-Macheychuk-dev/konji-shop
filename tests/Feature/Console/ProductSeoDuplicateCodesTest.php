<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';

uses(RefreshDatabase::class);

it('applies the duplicate code review without changing source fields and can restore the old metadata', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/duplicate-codes-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/duplicate-codes-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    foreach ($manifest['entries'] as $entry) {
        Product::query()->forceCreate(['id' => $entry['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + [
            'description' => $fixtures[$entry['id']]['description'], 'short_description' => $fixtures[$entry['id']]['short_description'],
        ]);
    }
    $before = DB::table('products')->orderBy('id')->get()->all();
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['changed_products' => 38, 'description_updates' => 0, 'title_updates' => 38]);
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_editorial_products'])->toBe(38);
    expect($repair->verifyRenderedMetadata())->toBe(38);
    foreach ($manifest['entries'] as $entry) {
        $row = DB::table('products')->find($entry['id']);
        expect($row->seo_description)->toBe($entry['expected']['seo_description']);
        expect($row->description)->toBe($fixtures[$entry['id']]['description']);
        expect($row->short_description)->toBe($fixtures[$entry['id']]['short_description']);
    }
    $repair->rollback($plan);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});


it('refuses a stale description before apply and preserves a later edit on rollback', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/duplicate-codes-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/duplicate-codes-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    foreach ($manifest['entries'] as $entry) {
        Product::query()->forceCreate(['id' => $entry['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + [
            'description' => $fixtures[$entry['id']]['description'], 'short_description' => $fixtures[$entry['id']]['short_description'],
        ]);
    }
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    $id = $manifest['entries'][0]['id'];
    DB::table('products')->where('id', $id)->update(['description' => 'Later source correction']);
    $before = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
    DB::table('products')->where('id', $id)->update(['description' => $fixtures[$id]['description']]);
    $plan = $repair->plan();
    $repair->apply($plan);
    DB::table('products')->where('id', $id)->update(['seo_title' => 'Later manual title']);
    $before = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});
