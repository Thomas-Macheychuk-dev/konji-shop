<?php

declare(strict_types=1);

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

it('rejects an active variant whose price is unavailable', function (): void {
    $product = Product::query()->create([
        'name' => 'Informacyjny produkt bez ceny',
        'slug' => 'informacyjny-produkt-bez-ceny',
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'NO-PRICE-001',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => null,
        'price_gross_amount' => null,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('products.show', $product->slug))
        ->post(route('cart.items.store'), [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])
        ->assertRedirect(route('products.show', $product->slug))
        ->assertSessionHasErrors('product_variant_id');

    $this->assertDatabaseCount('cart_items', 0);
});
