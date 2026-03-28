<?php

use App\Enums\CartStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartGuestTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('guest checkout sends an order confirmation email', function () {
    Mail::fake();

    $product = Product::query()->create([
        'name' => 'Test Product',
        'slug' => 'test-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'TEST-SKU-001',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

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

    $csrfToken = 'test-csrf-token';

    $response = $this
        ->withSession(['_token' => $csrfToken])
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $guestToken)
        ->post(route('checkout.place'), [
            '_token' => $csrfToken,
            'email' => 'guest@gmail.com',
            'phone' => '123456789',

            'shipping_first_name' => 'Jan',
            'shipping_last_name' => 'Kowalski',
            'shipping_company' => null,
            'shipping_address_line_1' => 'Test Street 1',
            'shipping_address_line_2' => null,
            'shipping_city' => 'Gdansk',
            'shipping_postcode' => '80-001',
            'shipping_country_code' => 'PL',

            'billing_same_as_shipping' => true,

            'notes' => 'Please leave at the door.',
            'terms_accepted' => true,
        ]);

    $response->assertSessionHasNoErrors();

    $order = Order::query()->first();

    expect($order)->not->toBeNull();
    expect($order->guest_email)->toBe('guest@gmail.com');

    $response->assertRedirect(route('checkout.success', $order));

    Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order) {
        return $mail->hasTo('guest@gmail.com')
            && $mail->order->is($order);
    });

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'guest_email' => 'guest@gmail.com',
    ]);

    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Test Product',
        'sku_snapshot' => 'TEST-SKU-001',
        'quantity' => 2,
    ]);

    $this->assertDatabaseHas('order_addresses', [
        'order_id' => $order->id,
        'type' => 'shipping',
        'email' => 'guest@gmail.com',
        'postcode' => '80-001',
    ]);

    $this->assertDatabaseHas('carts', [
        'id' => $cart->id,
        'status' => CartStatus::Converted->value,
    ]);
});

test('authenticated checkout sends the order confirmation email to the user email', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'owner@gmail.com',
    ]);

    $product = Product::query()->create([
        'name' => 'Second Product',
        'slug' => 'second-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'TEST-SKU-002',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 5000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $cart = Cart::query()->create([
        'user_id' => $user->id,
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

    $csrfToken = 'test-csrf-token';

    $response = $this
        ->actingAs($user)
        ->withSession(['_token' => $csrfToken])
        ->post(route('checkout.place'), [
            '_token' => $csrfToken,
            'email' => 'customer@gmail.com',
            'phone' => '987654321',

            'shipping_first_name' => 'Tomasz',
            'shipping_last_name' => 'Nowak',
            'shipping_company' => null,
            'shipping_address_line_1' => 'Example Street 2',
            'shipping_address_line_2' => null,
            'shipping_city' => 'Warsaw',
            'shipping_postcode' => '00-001',
            'shipping_country_code' => 'PL',

            'billing_same_as_shipping' => true,

            'notes' => null,
            'terms_accepted' => true,
        ]);

    $response->assertSessionHasNoErrors();

    $order = Order::query()->latest('id')->first();

    expect($order)->not->toBeNull();
    expect($order->user_id)->toBe($user->id);
    expect($order->guest_email)->toBeNull();

    $response->assertRedirect(route('checkout.success', $order));

    Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($user, $order) {
        return $mail->hasTo($user->email)
            && ! $mail->hasTo('customer@gmail.com')
            && $mail->order->is($order);
    });
});
