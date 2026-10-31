<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';

uses(RefreshDatabase::class);

function editorialFixture(): array
{
    $products = [];
    $entries = [];
    foreach (['ABC', 'DEF'] as $index => $model) {
        $product = Product::query()->create([
            'name' => 'Bluza '.$model, 'slug' => 'editorial-fixture-'.strtolower($model), 'status' => ProductStatus::ACTIVE,
            'seo_title' => 'Bluza '.$model, 'seo_description' => 'Bluza '.$model.'. Sprawdź opis i parametry produktu w Ortezka.pl.',
            'description' => '<p>Materiał: 50% bawełna, 50% poliester.</p>', 'short_description' => 'Bluza z kieszeniami.',
            'published_at' => null,
        ]);
        $row = (array) DB::table('products')->where('id', $product->id)->first();
        $products[] = $product;
        $entries[] = [
            'id' => $product->id, 'expected' => array_intersect_key($row, array_flip(['name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description'])),
            'source_description_sha256' => hash('sha256', ProductSeoMetadata::plain($row['description'])),
            'source_short_description_sha256' => hash('sha256', ProductSeoMetadata::plain($row['short_description'])),
            'after' => ['seo_title' => 'Bluza '.$model, 'seo_description' => 'Bluza '.$model.'. Skład: 50% bawełna, 50% poliester.'],
        ];
    }

    return [$products, ['repair' => 'product-seo-editorial-20261031-v1', 'entries' => $entries]];
}

it('plans without writes and applies only reviewed SEO fields with a reversible backup', function (): void {
    [$products, $manifest] = editorialFixture();
    $repair = new ProductSeoEditorialRepair($manifest);
    $before = DB::table('products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $plan = $repair->plan();
    expect(DB::table('products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_editorial_products'])->toBe(2);
    $after = DB::table('products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    foreach ($before as $index => $row) {
        foreach (array_diff(array_keys($row), ['seo_title', 'seo_description', 'updated_at']) as $field) {
            expect($after[$index][$field])->toBe($row[$field]);
        }
    }
    $repair->rollback($plan);
    expect(DB::table('products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});

it('rejects changed source evidence before changing any metadata', function (): void {
    [$products, $manifest] = editorialFixture();
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    $products[1]->update(['description' => 'Nowy skład produktu.']);
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Editorial evidence changed');
    expect($products[0]->fresh()->seo_description)->toBe($manifest['entries'][0]['expected']['seo_description']);
});

it('rejects tampered planned values and later editor changes on rollback', function (): void {
    [$products, $manifest] = editorialFixture();
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    $tampered = $plan;
    $tampered['operations'][0]['after']['seo_description'] = 'Injected arbitrary metadata.';
    expect(fn () => $repair->apply($tampered))->toThrow(RuntimeException::class, 'Tampered');
    $repair->apply($plan);
    $products[1]->update(['seo_description' => 'Opis zmieniony później przez redaktora.']);
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class, 'Later edit');
    expect($products[0]->fresh()->seo_description)->toBe($manifest['entries'][0]['after']['seo_description']);
});

it('rolls back the transaction when a later product write fails', function (): void {
    [$products, $manifest] = editorialFixture();
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    DB::statement("CREATE TRIGGER fail_editorial_second BEFORE UPDATE ON products WHEN NEW.id = ".(int) $products[1]->id." BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    expect(fn () => $repair->apply($plan))->toThrow(\Illuminate\Database\QueryException::class);
    expect($products[0]->fresh()->seo_description)->toBe($manifest['entries'][0]['expected']['seo_description']);
    DB::statement('DROP TRIGGER fail_editorial_second');
});

it('rejects attempts to write non SEO fields from the manifest', function (): void {
    [, $manifest] = editorialFixture();
    $manifest['entries'][0]['after']['status'] = 'archived';
    expect(fn () => new ProductSeoEditorialRepair($manifest))->toThrow(RuntimeException::class, 'Only SEO');
});

it('accepts the frozen reviewed copy and preserves explicit holds for conflicting source records', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/editorial-repair-20261031/review.json')), true, flags: JSON_THROW_ON_ERROR);
    new ProductSeoEditorialRepair($manifest);
    $held = collect($manifest['held_descriptions'])->keyBy('id');
    expect($held->has(13274))->toBeTrue();
    expect($held->has(14177))->toBeTrue();
    expect($held->get(12116)['reason'])->toContain('non-sterile');
    $entries = collect($manifest['entries'])->keyBy('id');
    expect($entries->get(13811)['after']['seo_description'])->not->toContain('cynku');
    expect($entries->get(13985)['after']['seo_description'])->not->toContain('cynku');
    expect($entries->get(14060)['after']['seo_description'])->not->toContain('cynku');
});
