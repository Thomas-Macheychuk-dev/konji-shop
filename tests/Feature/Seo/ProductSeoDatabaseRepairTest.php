<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/DatabaseRepair.php';

uses(RefreshDatabase::class);

function databaseSeoProduct(array $attributes = []): Product
{
    static $number = 0;
    $number++;

    return Product::query()->create($attributes + [
        'name' => 'Produkt model '.$number,
        'slug' => 'database-seo-product-'.$number,
        'status' => ProductStatus::ACTIVE,
    ]);
}

it('plans without database writes then persists complete SEO fields and preserves other product data', function (): void {
    $missing = databaseSeoProduct(['name' => 'Plaster bawełniany 25 mm × 5 m']);
    $valid = databaseSeoProduct(['seo_title' => 'Własny tytuł modelu', 'seo_description' => 'Opis przygotowany przez sklep z dokładnymi informacjami o tym modelu.']);
    $tainted = databaseSeoProduct(['seo_title' => 'Model X - GaleriaZdrowia.pl', 'seo_description' => 'Kup model za 7,53 zł | Darmowa dostawa od 119 zł - już w 24h']);
    $draft = databaseSeoProduct(['status' => ProductStatus::DRAFT]);
    $deleted = databaseSeoProduct();
    $deleted->delete();
    $before = DB::table('products')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $repair = new ProductSeoDatabaseRepair([]);
    $plan = $repair->plan();
    expect($plan['stats']['active_products'])->toBe(3);
    expect($plan['stats']['changed_products'])->toBe(2);
    expect(DB::table('products')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($before);

    $repair->apply($plan);
    expect($repair->verify($plan))->toBe(['active_products' => 3, 'blank_titles' => 0, 'blank_descriptions' => 0]);
    expect($repair->verifyRenderedMetadata())->toBe(3);
    expect($missing->fresh()->seo_title)->toBe('Plaster bawełniany 25 mm × 5 m');
    expect($missing->fresh()->seo_description)->toContain('Plaster bawełniany 25 mm × 5 m');
    expect($valid->fresh()->seo_title)->toBe('Własny tytuł modelu');
    expect($valid->fresh()->seo_description)->toBe('Opis przygotowany przez sklep z dokładnymi informacjami o tym modelu.');
    expect($tainted->fresh()->seo_title)->toBe('Model X');
    expect($tainted->fresh()->seo_description)->not->toContain('7,53', '119', 'Darmowa');
    expect($draft->fresh()->seo_title)->toBeNull();
    expect($draft->fresh()->seo_description)->toBeNull();
    expect(Product::withTrashed()->findOrFail($deleted->id)->seo_title)->toBeNull();
    foreach ($before as $row) {
        $after = (array) DB::table('products')->find($row['id']);
        foreach (array_diff(array_keys($row), ['seo_title', 'seo_description', 'updated_at']) as $field) {
            expect($after[$field])->toBe($row[$field]);
        }
    }
    expect($repair->plan()['operations'])->toBe([]);
    $repair->rollback($plan);
    expect(DB::table('products')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($before);
});

it('refuses a stale or tampered plan before updating any metadata', function (): void {
    $product = databaseSeoProduct();
    $repair = new ProductSeoDatabaseRepair([]);
    $plan = $repair->plan();
    $product->update(['seo_title' => 'Nowszy tytuł redaktora']);
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Source changed');
    expect($product->fresh()->seo_title)->toBe('Nowszy tytuł redaktora');
    expect($product->fresh()->seo_description)->toBeNull();
    $plan = $repair->plan();
    $plan['operations'][0]['after']['seo_title'] = 'Niepochodzący z planera tytuł';
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'repair algorithm');
    expect($product->fresh()->seo_description)->toBeNull();
});

it('rolls back the entire database transaction when an update fails', function (): void {
    $first = databaseSeoProduct();
    $second = databaseSeoProduct();
    $repair = new ProductSeoDatabaseRepair([]);
    $plan = $repair->plan();
    DB::listen(function ($query): void {
        if (str_starts_with(strtolower($query->sql), 'update "products"') || str_starts_with(strtolower($query->sql), 'update `products`')) {
            throw new RuntimeException('Simulated update failure');
        }
    });
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Simulated update failure');
    expect($first->fresh()->seo_title)->toBeNull();
    expect($second->fresh()->seo_title)->toBeNull();
});

it('refuses to roll back over later editor changes', function (): void {
    $first = databaseSeoProduct();
    $second = databaseSeoProduct();
    $repair = new ProductSeoDatabaseRepair([]);
    $plan = $repair->plan();
    $repair->apply($plan);
    $first->update(['seo_description' => 'Nowy opis redaktora']);
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class, 'rollback refused');
    expect($first->fresh()->seo_description)->toBe('Nowy opis redaktora');
    expect($second->fresh()->seo_title)->not->toBeNull();
});

it('persists all reviewed titles and then uses database metadata without rendered overrides', function (): void {
    $reviewed = require base_path('scripts/seo/product-database-20261031/reviewed-titles.php');
    foreach ($reviewed as $id => $entry) {
        $attributes = $entry;
        unset($attributes['title']);
        $product = new Product;
        $product->forceFill($attributes + ['id' => $id, 'status' => ProductStatus::ACTIVE])->save();
    }
    $repair = new ProductSeoDatabaseRepair($reviewed);
    $plan = $repair->plan();
    expect($plan['reasons']['persist_reviewed_title'])->toBe(72);
    $repair->apply($plan);
    expect($repair->verifyRenderedMetadata())->toBe(72);
    foreach ($reviewed as $id => $entry) {
        expect(Product::query()->findOrFail($id)->seo_title)->toBe($entry['title']);
    }
    expect($repair->plan()['operations'])->toBe([]);
});

it('preserves a good long description and renders the full stored value', function (): void {
    $description = str_repeat('Rzetelny opis modelu i jego parametrów. ', 8);
    $description = trim($description);
    $product = databaseSeoProduct(['seo_title' => 'Własny tytuł', 'seo_description' => $description]);
    $repair = new ProductSeoDatabaseRepair([]);
    expect($repair->plan()['operations'])->toBe([]);
    $response = $this->get(route('products.show', $product->slug))->assertOk();
    expect($response->viewData('seoDescription'))->toBe($description);
    $response->assertSee('<meta name="description" content="'.$description.'">', false);
});
