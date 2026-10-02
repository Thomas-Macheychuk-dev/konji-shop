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

it('does not render draft products publicly', function (): void {
    $product = Product::query()->create([
        'name' => 'Draft Product Wojdak',
        'slug' => 'draft-product-wojdak',
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertNotFound();
});

it('does not render draft products to non-admin users', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $product = Product::query()->create([
        'name' => 'Draft Product Wojdak',
        'slug' => 'draft-product-wojdak',
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->actingAs($user)
        ->get(route('products.show', $product->slug))
        ->assertNotFound();
});

it('allows admins to preview draft product storefront pages', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Bluza E2002 Wojdak',
        'slug' => 'bluza-e2002-wojdak',
        'short_description' => 'Produkt roboczy do sprawdzenia przez administratora.',
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Bluza E2002 Wojdak')
        ->assertSee('Podgląd administracyjny')
        ->assertSee('ten produkt ma status Szkic')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('includes draft size variants in admin preview without publishing them', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $product = Product::query()->create([
        'name' => 'FootWave preview sizes',
        'slug' => 'footwave-preview-sizes',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'footwave',
        'external_id' => '20795',
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'FOOTWAVE-PREVIEW-M',
        'status' => ProductVariantStatus::DRAFT,
        'price_gross_amount' => 8500,
        'price_net_amount' => 6911,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $this->get(route('products.show', $product->slug))->assertNotFound();

    $this->actingAs($admin)
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertViewHas('productPayload', fn (array $payload): bool => collect($payload['variants'] ?? [])
            ->contains(fn (array $row): bool => (int) $row['id'] === $variant->id)
        )
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});
