<?php

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\CartStatus;
use App\Enums\Currency;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('payments.default', 'test');

    app()->singleton(PaymentGatewayRegistry::class, function (): PaymentGatewayRegistry {
        return new PaymentGatewayRegistry([
            new class implements PaymentGateway {
                public function providerKey(): string
                {
                    return 'test';
                }

                public function initialize(Order $order, Payment $payment): PaymentInitializationResult
                {
                    return new PaymentInitializationResult(
                        provider: 'test',
                        providerReference: 'fake-payment-'.$payment->id,
                        redirectUrl: route('checkout.success'),
                        payload: [
                            'mode' => 'test',
                            'order_id' => $order->id,
                            'payment_id' => $payment->id,
                        ],
                    );
                }

                public function parseNotification(array $payload): PaymentNotificationData
                {
                    return new PaymentNotificationData(
                        providerReference: $payload['paymentId'] ?? '',
                        isSuccessful: true,
                        externalStatus: 'CONFIRMED',
                        payload: $payload,
                    );
                }

                public function verifyNotification(Payment $payment, array $payload, ?string $rawBody = null): bool
                {
                    return true;
                }
            },
        ]);
    });

    Mail::fake();
});

function createTestProductAndVariant(string $productName = 'Test Product', string $sku = 'TEST-SKU-001'): array
{
    $product = Product::query()->create([
        'name' => $productName,
        'slug' => str($productName)->slug()->toString(),
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    return [$product, $variant];
}

function validCheckoutPayload(string $email = 'guest@gmail.com'): array
{
    return [
        '_token' => 'test-csrf-token',
        'email' => $email,
        'phone' => '123456789',

        'shipping_first_name' => 'Jan',
        'shipping_last_name' => 'Kowalski',
        'shipping_company' => null,
        'shipping_address_line_1' => 'Test Street 1',
        'shipping_address_line_2' => null,
        'shipping_city' => 'Gdansk',
        'shipping_postcode' => '80-001',
        'shipping_country_code' => 'PL',

        'delivery_provider' => DeliveryProvider::INPOST->value,
        'delivery_service' => 'parcel_locker',
        'delivery_locker_code' => 'WAW01A',

        'billing_same_as_shipping' => true,

        'notes' => 'Please leave at the door.',
        'terms_accepted' => true,
    ];
}

test('guest checkout creates a pending payment and redirects through the payment layer', function () {
    [$product, $variant] = createTestProductAndVariant();

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

    $response = $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $guestToken)
        ->post(route('checkout.place'), validCheckoutPayload());

    $response->assertSessionHasNoErrors();

    $order = Order::query()
        ->with('payments')
        ->first();

    expect($order)->not->toBeNull();
    expect($order->guest_email)->toBe('guest@gmail.com');
    expect($order->status)->toBe(OrderStatus::PENDING_PAYMENT);
    expect($order->payment_status)->toBe(PaymentStatus::PENDING);
    expect($order->fulfilment_status)->toBe(FulfilmentStatus::UNFULFILLED);

    expect($order)
        ->delivery_provider->toBe(DeliveryProvider::INPOST)
        ->delivery_service->toBe('parcel_locker')
        ->delivery_locker_code->toBe('WAW01A');

    $payment = $order->payments->first();

    expect($payment)->not->toBeNull();
    expect($payment->provider)->toBe('test');
    expect($payment->provider_reference)->toBe('fake-payment-'.$payment->id);
    expect($payment->status)->toBe(PaymentStatus::PENDING);
    expect($payment->amount)->toBe($order->total_amount);
    expect($payment->currency)->toBe($order->currency);
    expect($payment->payload)->toBeArray();

    $response->assertRedirect(route('checkout.success'));

    Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order) {
        return $mail->hasTo('guest@gmail.com')
            && $mail->order->is($order);
    });

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'guest_email' => 'guest@gmail.com',
        'status' => OrderStatus::PENDING_PAYMENT->value,
        'payment_status' => PaymentStatus::PENDING->value,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED->value,
        'delivery_provider' => DeliveryProvider::INPOST->value,
        'delivery_service' => 'parcel_locker',
        'delivery_locker_code' => 'WAW01A',
    ]);

    $this->assertDatabaseHas('payments', [
        'order_id' => $order->id,
        'provider' => 'test',
        'provider_reference' => 'fake-payment-'.$payment->id,
        'status' => PaymentStatus::PENDING->value,
        'amount' => $order->total_amount,
        'currency' => $order->currency,
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

test('guest checkout sends an order confirmation email', function () {
    [$product, $variant] = createTestProductAndVariant();

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

    $response = $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $guestToken)
        ->post(route('checkout.place'), validCheckoutPayload());

    $response->assertSessionHasNoErrors();

    $order = Order::query()->first();

    expect($order)->not->toBeNull();
    expect($order->guest_email)->toBe('guest@gmail.com');

    $response->assertRedirect(route('checkout.success'));

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

test('authenticated checkout creates a pending payment and sends the order confirmation email to the user email', function () {
    $user = User::factory()->create([
        'email' => 'owner@gmail.com',
        'phone_number' => '987654321',
    ]);

    [$product, $variant] = createTestProductAndVariant('Second Product', 'TEST-SKU-002');

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

    $response = $this
        ->actingAs($user)
        ->withSession(['_token' => 'test-csrf-token'])
        ->post(route('checkout.place'), validCheckoutPayload('customer@gmail.com'));

    $response->assertSessionHasNoErrors();

    $order = Order::query()
        ->with('payments')
        ->latest('id')
        ->first();

    expect($order)->not->toBeNull();
    expect($order->user_id)->toBe($user->id);
    expect($order->guest_email)->toBeNull();
    expect($order->status)->toBe(OrderStatus::PENDING_PAYMENT);
    expect($order->payment_status)->toBe(PaymentStatus::PENDING);
    expect($order->fulfilment_status)->toBe(FulfilmentStatus::UNFULFILLED);

    $payment = $order->payments->first();

    expect($payment)->not->toBeNull();
    expect($payment->provider)->toBe('test');
    expect($payment->provider_reference)->toBe('fake-payment-'.$payment->id);
    expect($payment->status)->toBe(PaymentStatus::PENDING);

    $response->assertRedirect(route('checkout.success'));

    Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($user, $order) {
        return $mail->hasTo($user->email)
            && ! $mail->hasTo('customer@gmail.com')
            && $mail->order->is($order);
    });

    $this->assertDatabaseHas('payments', [
        'order_id' => $order->id,
        'provider' => 'test',
        'provider_reference' => 'fake-payment-'.$payment->id,
        'status' => PaymentStatus::PENDING->value,
    ]);
});

test('authenticated checkout sends the order confirmation email to the user email', function () {
    $user = User::factory()->create([
        'email' => 'owner@gmail.com',
        'phone_number' => '987654321',
    ]);

    [$product, $variant] = createTestProductAndVariant('Second Product', 'TEST-SKU-002');

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

    $response = $this
        ->actingAs($user)
        ->withSession(['_token' => 'test-csrf-token'])
        ->post(route('checkout.place'), validCheckoutPayload('customer@gmail.com'));

    $response->assertSessionHasNoErrors();

    $order = Order::query()->latest('id')->first();

    expect($order)->not->toBeNull();
    expect($order->user_id)->toBe($user->id);
    expect($order->guest_email)->toBeNull();

    $response->assertRedirect(route('checkout.success'));

    Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($user, $order) {
        return $mail->hasTo($user->email)
            && ! $mail->hasTo('customer@gmail.com')
            && $mail->order->is($order);
    });
});
