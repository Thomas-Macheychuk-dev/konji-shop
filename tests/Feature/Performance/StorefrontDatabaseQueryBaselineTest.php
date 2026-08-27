<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Storefront\ActiveCategorySubtree;
use App\Services\Storefront\ProductPageCacheVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('builds the complete product-page cache version in one database query', function (): void {
    $product = Product::query()->create([
        'name' => 'Query-efficient product',
        'slug' => 'query-efficient-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $version = app(ProductPageCacheVersion::class)->for($product);

    expect($queries)->toBe(1)
        ->and($version)->toHaveKeys([
            'product',
            'product_deleted',
            'images',
            'attribute_value_images',
            'product_attribute_value_pivot',
            'product_attribute_values',
            'product_attributes',
            'variants',
            'variant_values',
            'attribute_values',
            'attributes',
            'category_product',
            'categories',
        ]);
});

it('changes the consolidated product-page cache version when related media changes', function (): void {
    $product = Product::query()->create([
        'name' => 'Versioned product',
        'slug' => 'versioned-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $before = app(ProductPageCacheVersion::class)->for($product);

    ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/versioned-product/main.webp',
        'sort_order' => 0,
        'is_main' => true,
    ]);

    $after = app(ProductPageCacheVersion::class)->for($product);

    expect($before['images'])->toBeNull()
        ->and($after['images'])->not->toBeNull()
        ->and($after)->not->toBe($before);
});

it('resolves active category descendants in one query regardless of depth', function (): void {
    $root = Category::query()->create([
        'name' => 'Root',
        'slug' => 'root',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $child = Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Child',
        'slug' => 'child',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $grandchild = Category::query()->create([
        'parent_id' => $child->id,
        'name' => 'Grandchild',
        'slug' => 'grandchild',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $archived = Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Archived',
        'slug' => 'archived',
        'status' => CategoryStatus::ARCHIVED,
    ]);

    Category::query()->create([
        'parent_id' => $archived->id,
        'name' => 'Hidden descendant',
        'slug' => 'hidden-descendant',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $ids = app(ActiveCategorySubtree::class)->ids($root);
    sort($ids);

    $expected = [$root->id, $child->id, $grandchild->id];
    sort($expected);

    expect($queries)->toBe(1)
        ->and($ids)->toBe($expected);
});

it('uses already-loaded product images without querying a redundant main-image relation', function (): void {
    $product = Product::query()->create([
        'name' => 'Loaded image product',
        'slug' => 'loaded-image-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $secondary = ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/loaded-image-product/secondary.webp',
        'sort_order' => 0,
        'is_main' => false,
    ]);

    $main = ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/loaded-image-product/main.webp',
        'sort_order' => 1,
        'is_main' => true,
    ]);

    $product = Product::query()
        ->with(['images', 'attributeValueImages'])
        ->findOrFail($product->id);

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $selected = $product->selectedDefaultImage();

    expect($queries)->toBe(0)
        ->and($selected?->id)->toBe($main->id)
        ->and($selected?->id)->not->toBe($secondary->id);
});

it('installs the composite storefront indexes used by the hot catalogue queries', function (): void {
    $indexNames = function (string $table): array {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    };

    expect($indexNames('categories'))
        ->toContain('categories_storefront_tree_idx')
        ->toContain('categories_storefront_nav_idx')
        ->and($indexNames('products'))
        ->toContain('products_storefront_listing_idx')
        ->toContain('products_storefront_featured_idx')
        ->and($indexNames('product_variants'))
        ->toContain('product_variants_storefront_idx')
        ->and($indexNames('product_images'))
        ->toContain('product_images_storefront_idx')
        ->and($indexNames('product_attribute_value_images'))
        ->toContain('paiv_storefront_idx');
});
