<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/variant-review-20261101/VariantGuard.php';
require_once dirname(__DIR__, 3).'/scripts/seo/variant-review-20261101/ReferenceAudit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seoManifest = json_decode(file_get_contents(base_path('scripts/seo/variant-review-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->seoFixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/variant-review-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    $attributes = [];
    foreach ($this->seoManifest['entries'] as $entry) {
        $fixture = $this->seoFixtures[$entry['id']];
        Product::query()->forceCreate(['id' => $entry['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + [
            'description' => $fixture['description'], 'short_description' => $fixture['short_description'],
        ]);
        foreach ($fixture['variants'] as $variant) {
            $options = $variant['attributes'];
            unset($variant['attributes']);
            DB::table('product_variants')->insert($variant);
            foreach ($options as $option) {
                $name = $option['attribute'];
                if (! isset($attributes[$name])) {
                    $attributes[$name] = DB::table('attributes')->insertGetId(['name' => $name, 'slug' => 'seo-'.count($attributes), 'display_type' => 'select', 'created_at' => now(), 'updated_at' => now()]);
                }
                if (! DB::table('attribute_values')->where('id', $option['value_id'])->exists()) {
                    DB::table('attribute_values')->insert(['id' => $option['value_id'], 'attribute_id' => $attributes[$name], 'value' => $option['value'], 'slug' => 'seo-value-'.$option['value_id'], 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('product_variant_attribute_value')->insert(['product_variant_id' => $variant['id'], 'attribute_value_id' => $option['value_id']]);
            }
        }
    }
});

it('applies twelve reviewed titles and ten descriptions and restores all catalogue rows exactly', function (): void {
    $before = DB::table('products')->orderBy('id')->get()->all();
    $variants = DB::table('product_variants')->orderBy('id')->get()->all();
    $repair = new ProductSeoVariantEditorialRepair($this->seoManifest);
    $plan = $repair->plan();
    expect($plan['stats'])->toMatchArray(['changed_products' => 12, 'title_updates' => 12, 'description_updates' => 10]);
    $repair->apply($plan);
    expect($repair->verify($plan)['verified_editorial_products'])->toBe(12);
    expect($repair->verifyRenderedMetadata())->toBe(12);
    expect(DB::table('product_variants')->orderBy('id')->get()->all())->toEqual($variants);
    foreach ($this->seoFixtures as $id => $fixture) {
        $row = DB::table('products')->find($id);
        expect($row->description)->toBe($fixture['description']);
        expect($row->short_description)->toBe($fixture['short_description']);
    }
    $repair->rollback($plan);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});

it('refuses changed variant evidence before any SEO writes', function (string $change): void {
    $repair = new ProductSeoVariantEditorialRepair($this->seoManifest);
    $plan = $repair->plan();
    $variant = $this->seoFixtures[11925]['variants'][0];
    if ($change === 'sku') {
        DB::table('product_variants')->where('id', $variant['id'])->update(['sku' => 'later-sku']);
    } elseif ($change === 'option') {
        DB::table('attribute_values')->where('id', $variant['attributes'][0]['value_id'])->update(['value' => 'Later colour']);
    } else {
        DB::table('product_variants')->insert(['product_id' => 11925, 'sku' => 'new-option', 'status' => 'active']);
    }
    $before = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->apply($plan))->toThrow(RuntimeException::class, 'Reviewed variant configuration changed');
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
})->with(['sku', 'option', 'new_variant']);

it('refuses rollback after a later variant configuration change', function (): void {
    $repair = new ProductSeoVariantEditorialRepair($this->seoManifest);
    $plan = $repair->plan();
    $repair->apply($plan);
    DB::table('product_variants')->where('product_id', 11925)->update(['status' => 'draft']);
    $before = DB::table('products')->orderBy('id')->get()->all();
    expect(fn () => $repair->rollback($plan))->toThrow(RuntimeException::class);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($before);
});

it('exports aggregate references including custom foreign keys and polymorphic rows without writes or private payloads', function (): void {
    Schema::create('seo_reference_probe', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('owner')->constrained('products');
        $table->string('private_payload');
        $table->softDeletes();
    });
    Schema::create('seo_morph_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('subject_type');
        $table->unsignedBigInteger('subject_id');
    });
    DB::table('seo_reference_probe')->insert(['owner' => 11925, 'private_payload' => 'CUSTOMER_SECRET', 'deleted_at' => now()]);
    $variantId = $this->seoFixtures[11925]['variants'][0]['id'];
    DB::table('seo_morph_probe')->insert([
        ['subject_type' => Product::class, 'subject_id' => 11925],
        ['subject_type' => ProductVariant::class, 'subject_id' => $variantId],
        ['subject_type' => 'OtherModel', 'subject_id' => 11925],
    ]);
    $capture = true;
    $writes = [];
    DB::listen(function ($query) use (&$capture, &$writes): void {
        if ($capture && preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $audit = (new ProductSeoReferenceAudit)->build(array_values($this->seoFixtures));
    $capture = false;
    $rows = array_column($audit['references'], null, 'product_id');
    expect($writes)->toBe([]);
    expect($rows[11925]['references']['seo_reference_probe.owner'])->toBe(1);
    expect($rows[11925]['references']['seo_morph_probe.subject_id:products'])->toBe(1);
    expect($rows[11925]['references']['seo_morph_probe.subject_id:product_variants'])->toBe(1);
    expect($rows[11925]['references']['product_variants.product_id'])->toBe(1);
    expect(json_encode($audit))->not->toContain('CUSTOMER_SECRET');
    expect($audit)->not->toHaveKey('merge_eligible');
    Schema::dropIfExists('seo_morph_probe');
    Schema::dropIfExists('seo_reference_probe');
});
