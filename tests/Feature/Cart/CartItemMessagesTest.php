<?php

use App\Enums\CartStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('confirms cart updates and removals in Polish', function (): void {
    $user = User::factory()->create();
    $item = cartMessagesItem($user);

    $this->actingAs($user)
        ->patch(route('cart.items.update', $item), ['quantity' => 2])
        ->assertRedirect(route('cart.show'))
        ->assertSessionHas('success', 'Koszyk zaktualizowany.');

    $this->actingAs($user)
        ->delete(route('cart.items.destroy', $item))
        ->assertRedirect(route('cart.show'))
        ->assertSessionHas('success', 'Produkt usunięty z koszyka.');

    $this->actingAs($user)
        ->deleteJson(route('cart.items.destroy', cartMessagesItem($user)))
        ->assertJson(['message' => 'Produkt usunięty z koszyka.']);
});

function cartMessagesItem(User $user): CartItem
{
    $product = Product::query()->create([
        'name' => 'Orteza',
        'slug' => 'orteza-'.Product::query()->count(),
        'status' => ProductStatus::ACTIVE,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'SKU-'.$product->id,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 9000,
        'price_gross_amount' => 9720,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);
    $cart = Cart::query()->firstOrCreate(
        ['user_id' => $user->id],
        ['status' => CartStatus::Active, 'currency' => 'PLN'],
    );

    return CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'configuration_hash' => sha1((string) $variant->id),
        'quantity' => 1,
        'unit_price' => 9720,
        'currency' => 'PLN',
    ]);
}
