<?php

declare(strict_types=1);

use App\Enums\CartStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Checkout\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a stale zero-priced FootWave cart item before creating an order', function (): void {
    $product = Product::query()->create([
        'name' => 'FootWave CS6',
        'slug' => 'footwave-cs6-test',
        'external_source' => 'footwave',
        'external_id' => '20795',
        'status' => ProductStatus::ACTIVE,
    ]);

    // Deliberately active/in-stock to prove checkout has its own final guard.
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'FOOTWAVE-20804',
        'status' => ProductVariantStatus::ACTIVE,
        'price_gross_amount' => 0,
        'price_net_amount' => 0,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => false,
    ]);

    $cart = Cart::query()->create([
        'guest_token' => (string) str()->uuid(),
        'status' => CartStatus::Active,
        'currency' => Currency::PLN->value,
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 0,
        'currency' => Currency::PLN->value,
        'meta' => null,
    ]);

    expect(fn () => app(CheckoutService::class)->placeOrder($cart, [], null))
        ->toThrow(RuntimeException::class, 'has an invalid price.');

    $this->assertDatabaseCount('orders', 0);
});
