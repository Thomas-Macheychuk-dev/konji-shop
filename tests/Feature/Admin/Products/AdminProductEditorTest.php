<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProductForAdminProductEditorTest(): Product
{
    $product = Product::query()->create([
        'name' => 'Editable Product',
        'slug' => 'editable-product',
        'status' => ProductStatus::ACTIVE,
        'external_source' => 'test',
        'external_id' => 'external-123',
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'EDIT-MISSING',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'EDIT-COMPLETE',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 2000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => false,
        'package_weight_grams' => 500,
        'package_length_mm' => 300,
        'package_width_mm' => 200,
        'package_height_mm' => 100,
    ]);

    return $product;
}

it('shows the admin product index', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertSee('Products')
        ->assertSee('Editable Product')
        ->assertSee('1 missing weight/dimensions');
});

it('shows the admin product edit page with variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertSee('Product name')
        ->assertSee('Save product')
        ->assertSee('Apply package data to all variants')
        ->assertSee('EDIT-MISSING')
        ->assertSee('EDIT-COMPLETE')
        ->assertSee('Product status')
        ->assertSee(ProductStatus::DRAFT->label())
        ->assertSee(ProductStatus::ACTIVE->label())
        ->assertSee(ProductStatus::ARCHIVED->label());
});

it('allows an admin to apply package data to all product variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.package-dimensions.update', $product), [
            'package_weight_grams' => 750,
            'package_length_mm' => 310,
            'package_width_mm' => 210,
            'package_height_mm' => 110,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ($product->variants()->get() as $variant) {
        expect($variant)
            ->package_weight_grams->toBe(750)
            ->package_length_mm->toBe(310)
            ->package_width_mm->toBe(210)
            ->package_height_mm->toBe(110);
    }
});

it('allows an admin to update variant package data separately', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $variants = $product->variants()->orderBy('id')->get();

    $first = $variants[0];
    $second = $variants[1];

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.package-dimensions.update', $product), [
            'variants' => [
                $first->id => [
                    'package_weight_grams' => 100,
                    'package_length_mm' => 110,
                    'package_width_mm' => 120,
                    'package_height_mm' => 130,
                ],
                $second->id => [
                    'package_weight_grams' => 200,
                    'package_length_mm' => 210,
                    'package_width_mm' => 220,
                    'package_height_mm' => 230,
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($first->refresh())
        ->package_weight_grams->toBe(100)
        ->package_length_mm->toBe(110)
        ->package_width_mm->toBe(120)
        ->package_height_mm->toBe(130);

    expect($second->refresh())
        ->package_weight_grams->toBe(200)
        ->package_length_mm->toBe(210)
        ->package_width_mm->toBe(220)
        ->package_height_mm->toBe(230);
});

it('does not allow guests to view admin products', function (): void {
    $this
        ->get(route('admin.products.index'))
        ->assertRedirect();
});

it('does not allow non-admin users to view admin products', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertForbidden();
});

it('filters products by product name', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    Product::query()->create([
        'name' => 'Other Product',
        'slug' => 'other-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index', ['search' => 'Editable']))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertDontSee('Other Product');
});

it('filters products by variant sku', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    Product::query()->create([
        'name' => 'Other Product',
        'slug' => 'other-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index', ['search' => 'EDIT-MISSING']))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertDontSee('Other Product');
});

it('filters products by missing package data', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    $completeProduct = Product::query()->create([
        'name' => 'Complete Package Product',
        'slug' => 'complete-package-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $completeProduct->id,
        'sku' => 'COMPLETE-ONLY',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
        'package_weight_grams' => 500,
        'package_length_mm' => 300,
        'package_width_mm' => 200,
        'package_height_mm' => 100,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index', ['missing_package_data' => 1]))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertSee('missing weight/dimensions')
        ->assertDontSee('Complete Package Product');
});

it('allows an admin to update the product name', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => 'Updated Product Name',
            'status' => ProductStatus::ACTIVE->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh())
        ->name->toBe('Updated Product Name')
        ->status->toBe(ProductStatus::ACTIVE);
});

it('allows an admin to change a product status from draft to active', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $product->update([
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => $product->name,
            'status' => ProductStatus::ACTIVE->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh()->status)->toBe(ProductStatus::ACTIVE);
});

it('validates product status when updating product details', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => 'Updated Product Name',
            'status' => 'ACTIVE',
        ])
        ->assertSessionHasErrors(['status']);

    expect($product->refresh()->status)->toBe(ProductStatus::ACTIVE);
});

