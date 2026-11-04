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

it('shows VAT breakdown and the legally required order button before placing an order', function (): void {
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
        ->assertSee('Kasa')
        ->assertSee('Podsumowanie zamówienia')
        ->assertSee('Produkty brutto')
        ->assertSee(number_format($itemsGross / 100, 2, ',', ' ').' PLN')
        ->assertSee('Produkty netto')
        ->assertSee(number_format($itemsNet / 100, 2, ',', ' ').' PLN')
        ->assertSee('VAT od produktów')
        ->assertSee(number_format($itemsTax / 100, 2, ',', ' ').' PLN')
        ->assertSee('VAT razem')
        ->assertSee('Razem brutto')
        ->assertSee('Zapoznałem/am się z')
        ->assertSee('Regulaminem sklepu internetowego')
        ->assertSee('Zamówienie z obowiązkiem zapłaty')
        ->assertDontSee('Złóż zamówienie');
});

it('renders the checkout form in Polish and waits for a delivery price', function (): void {
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
        'quantity' => 1,
        'unit_price' => $variant->grossPriceAmount(),
        'currency' => Currency::PLN->value,
        'meta' => null,
    ]);

    $this
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $guestToken)
        ->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('<title>Kasa', false)
        ->assertSee('type="tel"', false)
        ->assertSeeInOrder(['Telefon', 'Imię', 'Nazwisko', 'Miejscowość', 'Kod pocztowy', 'Kraj', 'Uwagi'])
        ->assertSeeInOrder(['Polska', 'Albania', 'Niemcy', 'Włochy'])
        ->assertSee('wybierz metodę dostawy')
        ->assertSee('checkout-shipping-quote.window', false)
        ->assertDontSee('>Phone<', false)
        ->assertDontSee('Postcode')
        ->assertDontSee('Germany');

    $this
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $guestToken)
        ->get(route('cart.show'))
        ->assertOk()
        ->assertSee('obliczana w kasie');
});

it('redirects to the cart when the checkout cart is empty', function (): void {
    $this
        ->get(route('checkout.show'))
        ->assertRedirect(route('cart.show'))
        ->assertSessionHas('error', 'Twój koszyk jest pusty.');
});

/** @return array{0: Product, 1: ProductVariant} */
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
