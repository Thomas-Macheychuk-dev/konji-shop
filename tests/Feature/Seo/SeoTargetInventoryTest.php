<?php

declare(strict_types=1);

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('exports a read-only SEO target inventory with exact storefront reachability and matching keys', function (): void {
    $disk = Storage::disk('local');
    $jsonPath = $disk->path('scrapers/seo/ortezka/test-target-inventory.json');
    $productsCsvPath = $disk->path('scrapers/seo/ortezka/test-target-products.csv');
    $categoriesCsvPath = $disk->path('scrapers/seo/ortezka/test-target-categories.csv');

    @unlink($jsonPath);
    @unlink($productsCsvPath);
    @unlink($categoriesCsvPath);

    $activeCategory = Category::query()->create([
        'name' => 'Ortezy',
        'slug' => 'ortezy',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $archivedCategory = Category::query()->create([
        'name' => 'Archiwum',
        'slug' => 'archiwum',
        'status' => CategoryStatus::ARCHIVED,
    ]);

    $activeProduct = Product::query()->create([
        'name' => 'Active product',
        'slug' => 'active-product',
        'status' => ProductStatus::ACTIVE,
        'published_at' => null,
        'external_source' => 'test-source',
        'external_id' => 'EXT-123',
        'external_parent_sku' => ' Parent SKU 123 ',
    ]);

    $activeProduct->categories()->attach($activeCategory->id, ['is_primary' => true]);
    $activeProduct->categories()->attach($archivedCategory->id, ['is_primary' => false]);

    ProductVariant::query()->create([
        'product_id' => $activeProduct->id,
        'sku' => ' SKU  123 ',
        'status' => ProductVariantStatus::ACTIVE,
        'is_default' => true,
    ]);

    ProductVariant::query()->create([
        'product_id' => $activeProduct->id,
        'sku' => 'SKU-ARCHIVED',
        'status' => ProductVariantStatus::ARCHIVED,
        'is_default' => false,
    ]);

    $draftProduct = Product::query()->create([
        'name' => 'Draft product',
        'slug' => 'draft-product',
        'status' => ProductStatus::DRAFT,
    ]);

    $deletedProduct = Product::query()->create([
        'name' => 'Deleted product',
        'slug' => 'deleted-product',
        'status' => ProductStatus::ACTIVE,
    ]);
    $deletedProduct->delete();

    $before = [
        'products' => Product::withTrashed()->count(),
        'variants' => ProductVariant::withTrashed()->count(),
        'categories' => Category::withTrashed()->count(),
    ];

    $this->artisan('seo:target-inventory', [
        '--save' => 'scrapers/seo/ortezka/test-target-inventory.json',
        '--products-csv' => 'scrapers/seo/ortezka/test-target-products.csv',
        '--categories-csv' => 'scrapers/seo/ortezka/test-target-categories.csv',
    ])->assertSuccessful();

    expect(is_file($jsonPath))->toBeTrue()
        ->and(is_file($productsCsvPath))->toBeTrue()
        ->and(is_file($categoriesCsvPath))->toBeTrue();

    $inventory = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);
    $products = collect($inventory['products'])->keyBy('slug');
    $categories = collect($inventory['categories'])->keyBy('slug');

    expect($inventory['database_writes'])->toBeFalse()
        ->and($inventory['summary']['products'])->toBe(3)
        ->and($inventory['summary']['storefront_reachable_products'])->toBe(1)
        ->and($inventory['summary']['soft_deleted_products'])->toBe(1)
        ->and($inventory['summary']['variants'])->toBe(2)
        ->and($inventory['summary']['variants_with_sku'])->toBe(2)
        ->and($inventory['summary']['distinct_normalized_skus'])->toBe(2)
        ->and($inventory['summary']['categories'])->toBe(2)
        ->and($inventory['summary']['storefront_reachable_categories'])->toBe(1)
        ->and($products['active-product']['target_path'])->toBe('/products/active-product')
        ->and($products['active-product']['storefront_reachable'])->toBeTrue()
        ->and($products['active-product']['published_at'])->toBeNull()
        ->and($products['active-product']['external_parent_sku_matching_key'])->toBe('parent sku 123')
        ->and($products['active-product']['variants'][0]['matching_key'])->toBe('sku 123')
        ->and($products['draft-product']['storefront_reachable'])->toBeFalse()
        ->and($products['deleted-product']['storefront_reachable'])->toBeFalse()
        ->and($categories['ortezy']['target_path'])->toBe('/categories/ortezy')
        ->and($categories['ortezy']['storefront_reachable'])->toBeTrue()
        ->and($categories['archiwum']['storefront_reachable'])->toBeFalse();

    $after = [
        'products' => Product::withTrashed()->count(),
        'variants' => ProductVariant::withTrashed()->count(),
        'categories' => Category::withTrashed()->count(),
    ];

    expect($after)->toBe($before)
        ->and((string) file_get_contents($productsCsvPath))->toContain('variant_sku_matching_key')
        ->and((string) file_get_contents($productsCsvPath))->toContain('/products/active-product')
        ->and((string) file_get_contents($categoriesCsvPath))->toContain('/categories/ortezy');

    @unlink($jsonPath);
    @unlink($productsCsvPath);
    @unlink($categoriesCsvPath);
});

it('refuses unsafe target inventory output paths', function (): void {
    $this->artisan('seo:target-inventory', [
        '--save' => '../unsafe.json',
    ])
        ->expectsOutputToContain('Option --save must be a safe relative local-disk path.')
        ->assertFailed();
});
