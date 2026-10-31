<?php

use App\Enums\AttributeDisplayType;
use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/DatabaseRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-review-20261031/Exporter.php';

uses(RefreshDatabase::class);

it('exports sparse descriptions and duplicate identities with factual evidence using select queries only', function (): void {
    $basic = Product::query()->create(['name' => 'Model ABC', 'slug' => 'export-basic-abc', 'status' => ProductStatus::ACTIVE, 'description' => '<p>Materiał i konstrukcja modelu ABC.</p>']);
    $duplicates = [];
    foreach (['first', 'second'] as $suffix) {
        $duplicates[] = Product::query()->create([
            'name' => 'Model '.$suffix, 'slug' => 'export-duplicate-'.$suffix, 'status' => ProductStatus::ACTIVE,
            'seo_title' => 'Powielony tytuł', 'seo_description' => 'Własny opis produktu przygotowany przez sklep.',
        ]);
    }
    $repair = new ProductSeoDatabaseRepair([]);
    $plan = $repair->plan();
    $repair->apply($plan);
    $root = Category::query()->create(['name' => 'Rodzaj produktów', 'slug' => 'export-root', 'status' => CategoryStatus::ACTIVE]);
    $leaf = Category::query()->create(['name' => 'Podkategoria', 'slug' => 'export-leaf', 'parent_id' => $root->id, 'status' => CategoryStatus::ACTIVE]);
    $basic->categories()->attach($leaf->id, ['is_primary' => true]);
    $attribute = Attribute::query()->create(['name' => 'Kolor', 'slug' => 'export-colour', 'display_type' => AttributeDisplayType::SELECT]);
    $value = AttributeValue::query()->create(['attribute_id' => $attribute->id, 'value' => 'granatowy', 'slug' => 'export-navy']);
    $basic->attributeValues()->attach($value->id);
    foreach (['A', 'B'] as $suffix) {
        $variant = ProductVariant::query()->create(['product_id' => $basic->id, 'sku' => 'EXPORT-'.$suffix, 'is_default' => $suffix === 'A', 'status' => ProductVariantStatus::ACTIVE]);
        $variant->attributeValues()->attach($value->id);
    }
    ProductVariant::query()->create(['product_id' => $basic->id, 'sku' => 'ARCHIVED-SKU', 'status' => ProductVariantStatus::ARCHIVED]);
    ProductImage::query()->create(['product_id' => $basic->id, 'disk' => 'public', 'path' => 'products/model-abc.webp', 'source_url' => 'https://manufacturer.example/model-abc.webp']);
    $before = DB::table('products')->orderBy('id')->get()->toJson();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower(ltrim($query->sql));
    });
    $export = (new ProductSeoEditorialExport([]))->build($plan);
    foreach ($queries as $query) {
        expect(str_starts_with($query, 'select'))->toBeTrue();
    }
    expect(DB::table('products')->orderBy('id')->get()->toJson())->toBe($before);
    expect($export['database_writes'])->toBeFalse();
    expect($export['summary']['current_unchanged_basic_descriptions'])->toBe(1);
    expect($export['summary']['duplicate_title_groups'])->toBe(1);
    expect($export['summary']['exported_products'])->toBe(3);
    expect($export['summary']['missing_metadata_product_ids'])->toBe([]);
    $row = collect($export['products'])->firstWhere('id', $basic->id);
    expect($row['description'])->toBe('Materiał i konstrukcja modelu ABC.');
    expect($row['active_variant_count'])->toBe(2);
    expect($row['active_variant_attribute_values'])->toHaveCount(1);
    expect($row['active_variant_attribute_values'][0]['value'])->toBe('granatowy');
    expect($row['default_variant_identifiers'])->toBe([['sku' => 'EXPORT-A', 'external_variant_id' => null]]);
    expect($row['categories'][0]['path'])->toBe(['Rodzaj produktów', 'Podkategoria']);
    expect($row['images'][0]['source_url'])->toBe('https://manufacturer.example/model-abc.webp');
    $encoded = json_encode($export, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('ARCHIVED-SKU', 'password', 'customer_email');
});

it('does not treat a later authored description as the original sparse fallback', function (): void {
    $product = Product::query()->create(['name' => 'Model XYZ', 'slug' => 'export-edited-xyz', 'status' => ProductStatus::ACTIVE]);
    $repair = new ProductSeoDatabaseRepair([]);
    $plan = $repair->plan();
    $repair->apply($plan);
    $product->update(['seo_description' => 'Opis poprawiony przez redaktora sklepu.']);
    $export = (new ProductSeoEditorialExport([]))->build($plan);
    expect($export['summary']['current_unchanged_basic_descriptions'])->toBe(0);
    expect($export['summary']['changed_since_repair_product_ids'])->toBe([$product->id]);
    expect($export['products'])->toBe([]);
});
