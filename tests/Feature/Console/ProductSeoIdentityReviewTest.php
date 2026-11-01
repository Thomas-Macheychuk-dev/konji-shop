<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/variant-review-20261101/VariantGuard.php';
require_once dirname(__DIR__, 3).'/scripts/seo/identity-review-20261101/IdentityRepair.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->identityReview = json_decode(file_get_contents(base_path('scripts/seo/identity-review-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->identityFixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/identity-review-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    foreach ($this->identityReview['entries'] as $entry) {
        $p = $this->identityFixtures[$entry['id']];
        Product::query()->forceCreate(['id' => $p['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + ['description' => $p['description'], 'short_description' => $p['short_description']]);
        foreach ($p['product_attributes'] as $a) {
            if (! DB::table('attributes')->where('id', $a['attribute_id'])->exists()) {
                DB::table('attributes')->insert(['id' => $a['attribute_id'], 'name' => $a['attribute'], 'slug' => 'identity-attribute-'.$a['attribute_id'], 'display_type' => 'select']);
            }
            if (! DB::table('attribute_values')->where('id', $a['value_id'])->exists()) {
                DB::table('attribute_values')->insert(['id' => $a['value_id'], 'attribute_id' => $a['attribute_id'], 'value' => $a['value'], 'slug' => 'identity-value-'.$a['value_id']]);
            }
            DB::table('product_attribute_value')->insert(['product_id' => $p['id'], 'attribute_value_id' => $a['value_id']]);
        }
        foreach ($p['variants'] as $v) {
            $options = $v['attributes'];
            unset($v['attributes']);
            DB::table('product_variants')->insert($v);
            foreach ($options as $a) {
                $attribute = DB::table('attributes')->where('name', $a['attribute'])->first();
                $attributeId = $attribute?->id ?? DB::table('attributes')->insertGetId(['name' => $a['attribute'], 'slug' => 'identity-option-'.md5($a['attribute']), 'display_type' => 'select']);
                if (! DB::table('attribute_values')->where('id', $a['value_id'])->exists()) {
                    DB::table('attribute_values')->insert(['id' => $a['value_id'], 'attribute_id' => $attributeId, 'value' => $a['value'], 'slug' => 'identity-value-'.$a['value_id']]);
                }
                DB::table('product_variant_attribute_value')->insert(['product_variant_id' => $v['id'], 'attribute_value_id' => $a['value_id']]);
            }
        }
        foreach ($p['images'] as $image) {
            DB::table('product_images')->insert($image + ['product_id' => $p['id']]);
        }
    }
});

it('corrects reviewed metadata and wrong names/captions atomically and restores all rows exactly', function (): void {
    $products = DB::table('products')->orderBy('id')->get()->all();
    $variants = DB::table('product_variants')->orderBy('id')->get()->all();
    $images = DB::table('product_images')->orderBy('id')->get()->all();
    $repair = new ProductSeoIdentityRepair($this->identityReview);
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['changed_products' => 27, 'title_updates' => 27, 'description_updates' => 27, 'name_updates' => 3, 'image_text_updates' => 9]);
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_name_corrections'])->toBe(3);
    expect($repair->verifyRenderedMetadata())->toBe(27);
    expect(DB::table('product_variants')->orderBy('id')->get()->all())->toEqual($variants);
    foreach ($this->identityReview['entries'] as $entry) {
        $row = DB::table('products')->find($entry['id']);
        expect($row->description)->toBe($this->identityFixtures[$entry['id']]['description']);
        expect($row->short_description)->toBe($this->identityFixtures[$entry['id']]['short_description']);
        expect($row->name)->toBe($entry['after_name'] ?? $entry['expected']['name']);
    }
    $repair->rollback($plan);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($products);
    expect(DB::table('product_images')->orderBy('id')->get()->all())->toEqual($images);
});

it('refuses stale identity evidence before any product or image writes', function (string $kind): void {
    $repair = new ProductSeoIdentityRepair($this->identityReview);
    $plan = $repair->plan();
    if ($kind === 'media') {
        DB::table('product_images')->where('product_id', 2828)->update(['source_url' => 'https://example.com/other-colour.jpg']);
    } elseif ($kind === 'producer') {
        DB::table('attribute_values')->where('id', 6246)->update(['value' => 'Other producer']);
    } elseif ($kind === 'variant') {
        DB::table('product_variants')->where('product_id', 13026)->update(['sku' => 'changed-sku']);
    } else {
        DB::table('products')->where('id', 2828)->update(['name' => 'Later edited name']);
    }
    $products = DB::table('products')->orderBy('id')->get()->all();
    $images = DB::table('product_images')->orderBy('id')->get()->all();
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($products);
    expect(DB::table('product_images')->orderBy('id')->get()->all())->toEqual($images);
})->with(['media', 'producer', 'variant', 'name']);

it('refuses unreviewed image fields in a tampered plan', function (): void {
    $repair = new ProductSeoIdentityRepair($this->identityReview);
    $plan = $repair->plan();
    $plan['media_operations'][0]['after']['path'] = 'unreviewed.jpg';
    $before = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Image operations differ');
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});

it('preserves later manual image edits when rollback is requested', function (): void {
    $repair = new ProductSeoIdentityRepair($this->identityReview);
    $plan = $repair->plan();
    $repair->apply($plan);
    DB::table('product_images')->where('product_id', 2828)->update(['alt_text' => 'Later manually reviewed caption']);
    $before = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class, 'Later image edits');
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});
