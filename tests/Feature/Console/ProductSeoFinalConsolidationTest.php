<?php

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Support\Seo\ReviewedProductAliases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/variant-review-20261101/ReferenceAudit.php';
require_once dirname(__DIR__, 3).'/scripts/seo/consolidation-final-20261101/FinalConsolidationRepair.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->review = json_decode(file_get_contents(base_path('scripts/seo/consolidation-final-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Seo/consolidation-final-20261101.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixture['categories'] as $row) {
        Category::query()->forceCreate(['id' => $row['id'], 'name' => 'Test category '.$row['id'], 'slug' => 'final-consolidation-'.$row['id'], 'status' => 'active']);
    }
    foreach ($fixture['categories'] as $row) {
        if ($row['parent_id'] !== null && ! DB::table('categories')->where('id', $row['parent_id'])->exists()) {
            Category::query()->forceCreate(['id' => $row['parent_id'], 'name' => 'Ancestor '.$row['parent_id'], 'slug' => 'final-ancestor-'.$row['parent_id'], 'status' => 'active']);
        }
        DB::table('categories')->where('id', $row['id'])->update($row);
    }
    DB::table('products')->insert($fixture['products']);
    foreach (['attributes', 'attribute_values', 'product_variants', 'category_product', 'product_attribute_value', 'product_images'] as $table) {
        DB::table($table)->insert($fixture[$table]);
    }
    // Also exercise every previous redirect without changing the reviewed ten rows.
    $this->oldAliases = array_slice(json_decode(file_get_contents(resource_path('seo/ortezka/reviewed-product-aliases-20261101.json')), true, flags: JSON_THROW_ON_ERROR)['pairs'], 0, 6);
    foreach ($this->oldAliases as $pair) {
        foreach (['source', 'target'] as $role) {
            $row = $pair[$role];
            Product::query()->forceCreate($row + ['name' => 'Previous '.$row['id'], 'status' => $role === 'source' ? ProductStatus::ARCHIVED : ProductStatus::ACTIVE, 'seo_title' => 'Previous '.$row['id'], 'seo_description' => 'Previous product.']);
        }
    }
    $this->review['expected_active_products'] = 16;
    $this->review['expected_after_active_products'] = 13;
    $this->review['expected_duplicate_title_groups'] = 5;
    $this->review['expected_after_duplicate_title_groups'] = 0;
});

it('consolidates only three Vicair pairs preserves four colour offers media manuals and exact rollback', function (): void {
    $repair = new ProductSeoFinalConsolidationRepair($this->review);
    $before = [];
    foreach (['products', 'product_variants', 'product_images', 'category_product'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['archive_products' => 3, 'colour_offers_preserved' => 4, 'new_category_links' => 3, 'gallery_rows_removed_to_backup' => 5, 'files_deleted' => 0, 'changed_variants' => 0]);
    expect($plan['duplicate_title_product_ids'])->toBe([]);
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_redirects'])->toBe(3);
    expect($repair->verifyRenderedMetadata())->toBe(13);
    expect(DB::table('product_variants')->orderBy('id')->get()->all())->toEqual($before['product_variants']);
    expect(DB::table('product_images')->count())->toBe(68);
    expect(DB::table('product_images')->where('product_id', 10104)->pluck('id')->all())->toBe([59708, 59709, 59710]);
    expect(Product::findOrFail(9983)->seo_title)->toContain('czarna');
    expect(Product::findOrFail(9986)->seo_title)->toContain('biała');
    expect(Product::findOrFail(10104)->seo_title)->toContain('czarna');
    expect(Product::findOrFail(10107)->seo_title)->toContain('brązowa');
    expect(Product::findOrFail(12583)->description)->toContain('oEjiz2rIyJs', 'itS-NJZhgXc', 'quick-installation-guide-d3e29d793d.pdf');
    expect(Product::findOrFail(12587)->description)->toContain('Wybierz największy rozmiar', 'mY6aM3FmWk8', 'YcUgijz9nGU', 'user-manual-multi-version-c9bd7a8eaf.pdf');
    expect(Product::findOrFail(12590)->description)->toContain('prezi.com', '18pdf-abfaac7b66.pdf');
    foreach ($this->review['pairs'] as $pair) {
        $sourceBefore = array_values(array_filter($before['product_images'], fn ($row): bool => $row->product_id === $pair['source']['id']));
        expect(DB::table('product_images')->where('product_id', $pair['source']['id'])->orderBy('id')->get()->all())->toEqual($sourceBefore);
        expect(DB::table('category_product')->where('product_id', $pair['target']['id'])->where('is_primary', true)->count())->toBe(1);
    }
    foreach (['staging.ortezka.pl', 'ortezka.pl'] as $host) {
        foreach (array_merge($this->review['pairs'], $this->oldAliases) as $pair) {
            $this->get('https://'.$host.'/products/'.$pair['source']['slug'])->assertStatus(301)->assertRedirect('https://'.$host.'/products/'.$pair['target']['slug']);
        }
        $sitemap = $this->get('https://'.$host.'/sitemap.xml')->assertOk()->getContent();
        foreach ($this->review['pairs'] as $pair) {
            expect($sitemap)->not->toContain('/products/'.$pair['source']['slug'].'</loc>');
            expect($sitemap)->toContain('/products/'.$pair['target']['slug'].'</loc>');
        }
        foreach ([9983, 9986, 10104, 10107] as $id) {
            expect(app(ReviewedProductAliases::class)->destination(Product::findOrFail($id)))->toBeNull();
            expect($sitemap)->toContain('/products/'.Product::findOrFail($id)->slug.'</loc>');
        }
    }
    $repair->rollback($plan);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    foreach ($this->review['pairs'] as $pair) {
        expect(app(ReviewedProductAliases::class)->destination(Product::findOrFail($pair['source']['id'])))->toBeNull();
    }
});

it('refuses catalogue evidence drift before writing any reviewed changes', function (string $drift): void {
    $repair = new ProductSeoFinalConsolidationRepair($this->review);
    $plan = $repair->plan();
    match ($drift) {
        'image' => DB::table('product_images')->where('id', 69384)->update(['alt_text' => 'Later image caption']),
        'variant' => DB::table('product_variants')->where('product_id', 12619)->update(['price_gross_amount' => 100]),
        'body' => DB::table('products')->where('id', 12587)->update(['description' => 'Later sizing edit']),
        'category' => DB::table('category_product')->where('product_id', 12623)->update(['is_primary' => 0]),
        'deleted_image' => DB::table('product_images')->where('id', 59711)->delete(),
    };
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Frozen catalogue evidence changed');
    expect(Product::findOrFail(12619)->status)->toBe(ProductStatus::ACTIVE);
    expect(Product::findOrFail(9983)->name)->not->toContain('– czarna');
})->with(['image', 'variant', 'body', 'category', 'deleted_image']);

it('blocks new commercial references without reading or moving their payload', function (): void {
    $repair = new ProductSeoFinalConsolidationRepair($this->review);
    $plan = $repair->plan();
    $order = \App\Models\Order::factory()->create();
    DB::table('order_items')->insert(['order_id' => $order->id, 'product_id' => 12619, 'product_variant_id' => 50665, 'product_name_snapshot' => 'Preserve order payload', 'unit_price_amount' => 100, 'quantity' => 1, 'line_total_amount' => 100]);
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Commercial or unreviewed reference');
    expect(Product::findOrFail(12619)->status)->toBe(ProductStatus::ACTIVE);
    expect(DB::table('order_items')->where('order_id', $order->id)->value('product_name_snapshot'))->toBe('Preserve order payload');
});

it('rejects plan tampering and refuses to overwrite later edits on rollback', function (): void {
    $repair = new ProductSeoFinalConsolidationRepair($this->review);
    $plan = $repair->plan();
    $changed = $plan;
    $changed['product_updates'][0]['after']['slug'] = 'unreviewed';
    expect(fn () => $repair->apply($changed))->toThrow(RuntimeException::class, 'Plan operations differ');
    $repair->apply($plan);
    Product::query()->whereKey(12583)->update(['seo_title' => 'Later title']);
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class, 'Later edits');
    expect(Product::findOrFail(12619)->status)->toBe(ProductStatus::ARCHIVED);
});

it('rolls back all writes if a target is unavailable for the installed alias', function (): void {
    $repair = new ProductSeoFinalConsolidationRepair($this->review);
    $plan = $repair->plan();
    $original = resource_path('seo/ortezka/reviewed-product-aliases-20261101.json');
    $aliases = file_get_contents($original);
    $altered = json_decode($aliases, true, flags: JSON_THROW_ON_ERROR);
    $altered['pairs'] = array_slice($altered['pairs'], 0, 6);
    try {
        file_put_contents($original, json_encode($altered, JSON_THROW_ON_ERROR));
        expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Reviewed redirect is not installed');
        expect(Product::findOrFail(12619)->status)->toBe(ProductStatus::ACTIVE);
        expect(DB::table('product_images')->count())->toBe(73);
        expect(Product::findOrFail(9983)->name)->not->toContain('– czarna');
    } finally {
        file_put_contents($original, $aliases);
    }
});

it('refuses to orphan any other products explicitly selected default image', function (): void {
    $repair = new ProductSeoFinalConsolidationRepair($this->review);
    $plan = $repair->plan();
    Product::query()->forceCreate(['name' => 'Other image consumer', 'slug' => 'other-image-consumer', 'status' => ProductStatus::ACTIVE, 'default_image_type' => \App\Models\ProductImage::class, 'default_image_id' => 59711]);
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Default image reference blocks');
    expect(Product::findOrFail(12619)->status)->toBe(ProductStatus::ACTIVE);
    expect(DB::table('product_images')->where('id', 59711)->exists())->toBeTrue();
});
