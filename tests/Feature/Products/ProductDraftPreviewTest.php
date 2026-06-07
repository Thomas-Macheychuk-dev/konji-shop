<?php

use App\Enums\ProductStatus;
use App\Models\Product;
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
