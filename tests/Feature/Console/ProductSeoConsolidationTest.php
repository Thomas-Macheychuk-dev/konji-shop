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
require_once dirname(__DIR__, 3).'/scripts/seo/consolidation-20261101/ConsolidationRepair.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->consolidation = json_decode(file_get_contents(base_path('scripts/seo/consolidation-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/Seo/consolidation-20261101.json')), true, flags: JSON_THROW_ON_ERROR);
    $attributes = [];
    foreach ($fixtures as $fixture) {
        Product::query()->forceCreate(array_intersect_key($fixture, array_flip(['id', 'name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description', 'description', 'short_description', 'published_at'])) + ['status' => ProductStatus::ACTIVE]);
        foreach ($fixture['categories'] as $category) {
            if (! Category::query()->whereKey($category['category_id'])->exists()) {
                Category::query()->forceCreate(['id' => $category['category_id'], 'name' => 'Category '.$category['category_id'], 'slug' => 'consolidation-'.$category['category_id'], 'status' => 'active']);
            }
            DB::table('category_product')->insert($category + ['product_id' => $fixture['id'], 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($fixture['variants'] as $variant) {
            unset($variant['attributes']);
            DB::table('product_variants')->insert($variant);
        }
        foreach ($fixture['product_attributes'] as $value) {
            if (! isset($attributes[$value['attribute_id']])) {
                DB::table('attributes')->insert(['id' => $value['attribute_id'], 'name' => $value['attribute'], 'slug' => 'merge-attribute-'.$value['attribute_id'], 'display_type' => 'select']);
                $attributes[$value['attribute_id']] = true;
            }
            if (! DB::table('attribute_values')->where('id', $value['value_id'])->exists()) {
                DB::table('attribute_values')->insert(['id' => $value['value_id'], 'attribute_id' => $value['attribute_id'], 'value' => $value['value'], 'slug' => 'merge-value-'.$value['value_id']]);
            }
            DB::table('product_attribute_value')->insert(['product_id' => $fixture['id'], 'attribute_value_id' => $value['value_id']]);
        }
        // Only sampled image URLs were exported originally. Synthetic repeated
        // samples exercise live full-multiset checks at the audited row counts.
        $expected = null;
        foreach ($this->consolidation['pairs'] as $pair) {
            foreach (['target', 'source'] as $role) {
                if ($pair[$role]['id'] === $fixture['id']) {
                    $expected = $pair[$role];
                }
            }
        }
        foreach (['product_images' => 'image_evidence', 'product_attribute_value_images' => 'attribute_image_evidence'] as $table => $key) {
            $count = $expected['references'][$table.'.product_id'] ?? 0;
            for ($i = 0; $i < $count; $i++) {
                $sample = $fixture[$key][$i % count($fixture[$key])];
                $sample['product_id'] = $fixture['id'];
                $sample['path'] = 'test/consolidation/'.$fixture['id'].'/'.$table.'/'.$i.'.jpg';
                DB::table($table)->insert($sample);
            }
        }
    }
});

it('archives only six duplicate records preserves all variants and media and restores the exact snapshot', function (): void {
    $repair = new ProductSeoConsolidationRepair($this->consolidation);
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['archive_products' => 6, 'new_category_links' => 6, 'deleted_rows' => 0, 'changed_variants' => 0]);
    $variants = DB::table('product_variants')->orderBy('id')->get()->all();
    $images = DB::table('product_images')->orderBy('id')->get()->all();
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_redirects'])->toBe(6);
    expect(Product::query()->where('status', 'active')->count())->toBe(6);
    expect(Product::query()->count())->toBe(12);
    expect($repair->verifyRenderedMetadata())->toBe(6);
    expect(DB::table('product_variants')->orderBy('id')->get()->all())->toEqual($variants);
    expect(DB::table('product_images')->orderBy('id')->get()->all())->toEqual($images);
    foreach ($this->consolidation['pairs'] as $pair) {
        expect(DB::table('category_product')->where('product_id', $pair['target']['id'])->where('is_primary', true)->count())->toBe(1);
        foreach (['staging.ortezka.pl', 'ortezka.pl'] as $host) {
            $this->get('https://'.$host.'/products/'.$pair['source']['slug'])->assertStatus(301)->assertRedirect('https://'.$host.'/products/'.$pair['target']['slug']);
            $sitemap = $this->get('https://'.$host.'/sitemap.xml')->assertOk()->getContent();
            expect($sitemap)->not->toContain('/products/'.$pair['source']['slug'].'</loc>');
            expect($sitemap)->toContain('/products/'.$pair['target']['slug'].'</loc>');
        }
    }
    $repair->rollback($plan);
    expect(Product::query()->where('status', 'active')->count())->toBe(12);
    expect(app(ReviewedProductAliases::class)->destination(Product::findOrFail(12427)))->toBeNull();
});

it('refuses drift in the manufacturer images before any archive writes', function (): void {
    $repair = new ProductSeoConsolidationRepair($this->consolidation);
    $plan = $repair->plan();
    DB::table('product_images')->where('product_id', 12427)->limit(1)->update(['source_url' => 'https://example.com/different-model.jpg']);
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Manufacturer image sets differ');
    expect(Product::query()->where('status', 'active')->count())->toBe(12);
});

it('refuses a new commercial reference without exporting or moving the order payload', function (): void {
    $repair = new ProductSeoConsolidationRepair($this->consolidation);
    $plan = $repair->plan();
    $order = \App\Models\Order::factory()->create();
    $variant = DB::table('product_variants')->where('product_id', 12427)->first();
    DB::table('order_items')->insert(['order_id' => $order->id, 'product_id' => 12427, 'product_variant_id' => $variant->id, 'product_name_snapshot' => 'Original name', 'unit_price_amount' => 100, 'quantity' => 1, 'line_total_amount' => 100]);
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Commercial or unreviewed reference');
    expect(Product::query()->where('status', 'active')->count())->toBe(12);
    expect(DB::table('order_items')->where('order_id', $order->id)->value('product_name_snapshot'))->toBe('Original name');
});

it('refuses to overwrite a later edit during rollback and refuses an unavailable alias target', function (): void {
    $repair = new ProductSeoConsolidationRepair($this->consolidation);
    $plan = $repair->plan();
    $repair->apply($plan);
    Product::query()->whereKey(12424)->update(['seo_title' => 'Later manual title']);
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class, 'Later edits');
    expect(Product::findOrFail(12427)->status)->toBe(ProductStatus::ARCHIVED);
    Product::query()->whereKey(12424)->update(['status' => ProductStatus::DRAFT]);
    expect(app(ReviewedProductAliases::class)->destination(Product::findOrFail(12427)))->toBeNull();
    $this->get('/products/v500-light-321fc813')->assertNotFound();
});
