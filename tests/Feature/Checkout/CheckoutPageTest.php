<?php

use App\Enums\CartStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartGuestTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows VAT breakdown on the checkout page before placing an order', function (): void {
    [$product, $variant] = checkoutPageTestProductAndVariant();

    $guestToken = (string) str()->uuid();

    $cart = Cart::query()->create([
        'guest_token' => $guestToken,
        'status' => CartStatus::Active,
        'currency' => Currency::PLN->value,
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
        'unit_price' => $variant->grossPriceAmount(),
        'currency' => Currency::PLN->value,
        'meta' => null,
    ]);

    $unitGross = $variant->grossPriceAmount();
    $itemsGross = $unitGross * 2;
    $itemsNet = VatRate::VAT_23->netFromGross($itemsGross);
    $itemsTax = $itemsGross - $itemsNet;

    $this
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $guestToken)
        ->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('Checkout')
        ->assertSee('Order summary')
        ->assertSee('Items gross')
        ->assertSee(number_format($itemsGross / 100, 2, ',', ' ').' PLN')
        ->assertSee('Items net')
        ->assertSee(number_format($itemsNet / 100, 2, ',', ' ').' PLN')
        ->assertSee('Items VAT')
        ->assertSee(number_format($itemsTax / 100, 2, ',', ' ').' PLN')
        ->assertSee('Total VAT')
        ->assertSee('Total gross');
});

it('redirects to the cart when the checkout cart is empty', function (): void {
    $this
        ->get(route('checkout.show'))
        ->assertRedirect(route('cart.show'))
        ->assertSessionHas('error', 'Your cart is empty.');
});

/**
 * @return array{0: Product, 1: ProductVariant}
 */
function checkoutPageTestProductAndVariant(): array
{
    $product = Product::query()->create([
        'name' => 'Checkout VAT Product',
        'slug' => 'checkout-vat-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'CHECKOUT-VAT-SKU',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    return [$product, $variant];
}
