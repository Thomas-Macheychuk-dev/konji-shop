<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Attribute as ProductAttribute;
use App\Models\AttributeValue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/source-cleanup-20261101/SourceRepair.php';

uses(RefreshDatabase::class);

function sourceCleanupFixture(): array
{
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/source-cleanup-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/source-cleanup-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    foreach ($manifest['entries'] as $entry) {
        // Original HTML formatting is intentionally different from the normalized
        // export: backup/rollback must preserve it byte for byte.
        $source = $fixtures[$entry['id']];
        Product::query()->forceCreate(['id' => $entry['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + [
            'description' => '<div><strong>'.htmlspecialchars($source['description'], ENT_NOQUOTES, 'UTF-8').'</strong></div>',
            'short_description' => '<p>'.htmlspecialchars($source['short_description'], ENT_NOQUOTES, 'UTF-8').'</p>',
        ]);
    }

    ProductAttribute::query()->forceCreate(['id' => 498, 'name' => 'Indeks', 'slug' => 'source-cleanup-indeks', 'display_type' => 'text']);
    foreach ($manifest['detach_attribute_values'] as $link) {
        AttributeValue::query()->forceCreate(['id' => $link['attribute_value_id'], 'attribute_id' => 498, 'value' => '<span>'.htmlspecialchars($link['expected_value'], ENT_NOQUOTES, 'UTF-8').'</span>', 'slug' => 'source-cleanup-'.$link['attribute_value_id']]);
        DB::table('product_attribute_value')->insert(['product_id' => $link['product_id'], 'attribute_value_id' => $link['attribute_value_id'], 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$manifest, new ProductSourceCleanup($manifest), DB::table('products')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all()];
}

it('repairs thirteen source conflicts while preserving SEO and restores original HTML on rollback', function (): void {
    [$manifest, $repair, $before] = sourceCleanupFixture();
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['changed_products' => 13, 'description_updates' => 10, 'short_description_updates' => 3, 'seo_updates' => 0, 'attribute_detaches' => 2]);
    $pivots = DB::table('product_attribute_value')->orderBy('product_id')->get()->all();
    $values = DB::table('attribute_values')->orderBy('id')->get()->all();
    $repair->apply($plan);
    expect(DB::table('product_attribute_value')->count())->toBe(0);
    expect(DB::table('attribute_values')->orderBy('id')->get()->all())->toEqual($values);
    expect($repair->verify($plan)['verified_source_products'])->toBe(13);
    expect($repair->verifyRenderedMetadata())->toBe(13);
    foreach ($manifest['entries'] as $entry) {
        $row = DB::table('products')->find($entry['id']);
        expect($row->seo_title)->toBe($entry['expected']['seo_title']);
        expect($row->seo_description)->toBe($entry['expected']['seo_description']);
        foreach ($entry['after'] as $field => $value) {
            expect($row->{$field})->toBe($value);
        }
    }
    $repair->rollback($plan);
    expect(DB::table('product_attribute_value')->orderBy('product_id')->get()->all())->toEqual($pivots);
    expect(DB::table('products')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all())->toBe($before);
});

it('keeps dry run read only and rejects a concurrent raw HTML edit before any writes', function (): void {
    [, $repair] = sourceCleanupFixture();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void { $queries[] = strtolower(ltrim($query->sql)); });
    $plan = $repair->plan();
    foreach ($queries as $query) {
        expect(str_starts_with($query, 'select'))->toBeTrue();
    }
    $row = DB::table('products')->find(14229);
    DB::table('products')->where('id', 14229)->update(['description' => '<section>'.$row->description.'</section>']);
    $snapshot = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Source changed since dry run');
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($snapshot);
});

it('rejects stale source evidence and newer SEO edits', function (): void {
    [, $repair] = sourceCleanupFixture();
    $row = DB::table('products')->find(179);
    DB::table('products')->where('id', 179)->update(['short_description' => 'A newer source description.']);
    expect(fn () => $repair->plan())->toThrow(RuntimeException::class, 'Source evidence changed');
    DB::table('products')->where('id', 179)->update(['short_description' => $row->short_description, 'seo_title' => 'New editor title']);
    expect(fn () => $repair->plan())->toThrow(RuntimeException::class, 'Identity or SEO changed');
});

it('rejects extra writable fields and tampered backup values', function (): void {
    [$manifest, $repair, $before] = sourceCleanupFixture();
    $bad = $manifest;
    $bad['entries'][0]['after']['seo_title'] = 'Must never be written';
    expect(fn () => new ProductSourceCleanup($bad))->toThrow(RuntimeException::class, 'Only source description fields');
    $plan = $repair->plan();
    $plan['operations'][0]['before']['short_description'] = 'Not the original HTML';
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Tampered');
    expect(DB::table('products')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all())->toBe($before);
});

it('refuses rollback atomically after a later source edit', function (): void {
    [, $repair] = sourceCleanupFixture();
    $plan = $repair->plan();
    $repair->apply($plan);
    DB::table('products')->where('id', 14229)->update(['description' => 'A later human correction.']);
    $snapshot = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class, 'Later edit');
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($snapshot);
});

it('rolls back every earlier update when a database write fails', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Failure injection uses a SQLite trigger.');
    }
    [, $repair, $before] = sourceCleanupFixture();
    $plan = $repair->plan();
    DB::unprepared("CREATE TRIGGER source_cleanup_test_failure BEFORE UPDATE OF description ON products WHEN NEW.id = 14229 BEGIN SELECT RAISE(ABORT, 'source cleanup test failure'); END");
    try {
        expect(fn () => $repair->apply($plan))->toThrow(QueryException::class);
        expect(DB::table('products')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all())->toBe($before);
    } finally {
        DB::unprepared('DROP TRIGGER source_cleanup_test_failure');
    }
});


it('detaches only the reviewed product links and rejects changed attribute evidence', function (): void {
    [$manifest, $repair] = sourceCleanupFixture();
    $other = Product::query()->forceCreate(['name' => 'Unrelated product', 'slug' => 'source-cleanup-unrelated', 'status' => ProductStatus::ACTIVE, 'seo_title' => 'Unrelated product', 'seo_description' => 'An unrelated product description.']);
    $valueId = $manifest['detach_attribute_values'][0]['attribute_value_id'];
    DB::table('product_attribute_value')->insert(['product_id' => $other->id, 'attribute_value_id' => $valueId, 'created_at' => now(), 'updated_at' => now()]);
    $plan = $repair->plan();
    $repair->apply($plan);
    expect(DB::table('product_attribute_value')->where('product_id', $other->id)->where('attribute_value_id', $valueId)->exists())->toBeTrue();
    $repair->rollback($plan);
    DB::table('attribute_values')->where('id', $valueId)->update(['value' => 'A newer attribute correction.']);
    expect(fn () => $repair->plan())->toThrow(RuntimeException::class, 'Attribute evidence');
});
