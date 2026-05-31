<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks operational storefront pages as noindex', function (): void {
    $this
        ->get(route('cart.show'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false);

    $this
        ->get(route('guest.orders.track.show'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
});

it('does not mark public product pages as noindex', function (): void {
    $product = Product::query()->create([
        'name' => 'Indexable Product',
        'slug' => 'indexable-product',
        'short_description' => 'Public product page.',
        'status' => ProductStatus::ACTIVE,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertDontSee('<meta name="robots" content="noindex, follow">', false);
});
