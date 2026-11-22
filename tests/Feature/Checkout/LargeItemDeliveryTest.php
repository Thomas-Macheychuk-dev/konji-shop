<?php

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\CartStatus;
use App\Enums\Currency;
use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Delivery\LargeItemDelivery;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('payments.default', 'test');
    Config::set('delivery.providers.polkurier.valuation.enabled', false);
    Config::set('delivery.providers.polkurier.valuation.fallback_prices.ups.courier', 1599);
    Http::fake();
    Mail::fake();

    app()->singleton(PaymentGatewayRegistry::class, fn (): PaymentGatewayRegistry => new PaymentGatewayRegistry([
        new class implements PaymentGateway
        {
            public function providerKey(): string
            {
                return 'test';
            }

            public function initialize(Order $order, Payment $payment): PaymentInitializationResult
            {
                return new PaymentInitializationResult(provider: 'test', providerReference: 'fake-'.$payment->id, redirectUrl: route('checkout.success'), payload: []);
            }

            public function parseNotification(array $payload): PaymentNotificationData
            {
                return new PaymentNotificationData(providerReference: '', isSuccessful: true, externalStatus: 'CONFIRMED', payload: $payload);
            }

            public function verifyNotification(Payment $payment, array $payload, ?string $rawBody = null): bool
            {
                return true;
            }
        },
    ]));
});

it('quotes the flat large-item price for courier delivery, the highest in the cart once', function (): void {
    $token = largeItemCart([['Kozetka lekarska', 8000], ['Parawan', 7200], ['Uchwyt', null]]);

    $this->withCredentials()->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->postJson(route('checkout.shipping-quote'), largeItemQuotePayload(DeliveryCarrier::UPS, 'courier'))
        ->assertOk()
        ->assertJson(['amount' => 8000, 'formatted' => '80,00 PLN', 'source' => LargeItemDelivery::SOURCE]);

    Http::assertNothingSent();
});

it('refuses a parcel locker for a large item and keeps local pickup free', function (): void {
    $token = largeItemCart([['Parawan', 7200]]);

    $this->withCredentials()->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->postJson(route('checkout.shipping-quote'), largeItemQuotePayload(DeliveryCarrier::INPOST, 'parcel_locker'))
        ->assertUnprocessable()
        ->assertJson(['message' => LargeItemDelivery::LOCKER_MESSAGE]);

    $this->withCredentials()->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->postJson(route('checkout.shipping-quote'), largeItemQuotePayload(DeliveryCarrier::LOCAL_PICKUP, 'local_pickup'))
        ->assertOk()
        ->assertJson(['amount' => 0]);
});

it('keeps the usual quote for a cart without large items', function (): void {
    $token = largeItemCart([['Uchwyt', null]]);

    $this->withCredentials()->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->postJson(route('checkout.shipping-quote'), largeItemQuotePayload(DeliveryCarrier::UPS, 'courier'))
        ->assertOk()
        ->assertJson(['amount' => 1599, 'source' => 'fallback']);
});

it('charges the large-item price on the order and refuses a parcel locker', function (): void {
    $token = largeItemCart([['Stojak na kroplówki', 7200]]);

    $this->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->post(route('checkout.place'), largeItemOrderPayload(DeliveryCarrier::INPOST, 'parcel_locker', 'WAW01A'))
        ->assertSessionHasErrors(['delivery_service' => LargeItemDelivery::LOCKER_MESSAGE]);

    expect(Order::query()->count())->toBe(0);

    $this->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->post(route('checkout.place'), largeItemOrderPayload(DeliveryCarrier::DPD, 'courier'))
        ->assertSessionHasNoErrors();

    $order = Order::query()->firstOrFail();

    expect($order->shipping_gross_amount)->toBe(7200)
        ->and($order->total_amount)->toBe($order->items_gross_amount + 7200);
});

it('hides the parcel locker on the checkout page for a large item', function (): void {
    $token = largeItemCart([['Kozetka lekarska', 8000]]);

    $this->withCookie(CartGuestTokenResolver::COOKIE_NAME, $token)
        ->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('data-large-item-delivery="8000"', false);
});

/** @param  list<array{0: string, 1: int|null}>  $items */
function largeItemCart(array $items): string
{
    $token = (string) str()->uuid();
    $cart = Cart::query()->create(['guest_token' => $token, 'status' => CartStatus::Active, 'currency' => Currency::PLN->value]);

    foreach ($items as [$name, $amount]) {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'status' => ProductStatus::ACTIVE,
            'large_item_delivery_amount' => $amount,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'LARGE-'.$product->id,
            'status' => ProductVariantStatus::ACTIVE,
            'price_net_amount' => 10000,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::VAT_23,
            'stock_status' => StockStatus::IN_STOCK,
            'is_default' => true,
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => $variant->grossPriceAmount(),
            'currency' => Currency::PLN->value,
            'meta' => null,
        ]);
    }

    return $token;
}

/** @return array<string, string> */
function largeItemQuotePayload(DeliveryCarrier $carrier, string $service): array
{
    return [
        'delivery_provider' => DeliveryProvider::POLKURIER->value,
        'delivery_carrier' => $carrier->value,
        'delivery_service' => $service,
        'delivery_locker_code' => $service === 'parcel_locker' ? 'WAW01A' : '',
        'shipping_postcode' => '60-820',
        'shipping_country_code' => 'PL',
        'currency' => 'PLN',
    ];
}

/** @return array<string, mixed> */
function largeItemOrderPayload(DeliveryCarrier $carrier, string $service, ?string $locker = null): array
{
    return [
        '_token' => 'test-csrf-token',
        'email' => 'guest@gmail.com',
        'phone' => '123456789',
        'shipping_first_name' => 'Jan',
        'shipping_last_name' => 'Kowalski',
        'shipping_address_line_1' => 'Test Street 1',
        'shipping_city' => 'Poznań',
        'shipping_postcode' => '60-820',
        'shipping_country_code' => 'PL',
        'delivery_provider' => DeliveryProvider::POLKURIER->value,
        'delivery_carrier' => $carrier->value,
        'delivery_service' => $service,
        'delivery_locker_code' => $locker,
        'billing_same_as_shipping' => true,
        'terms_accepted' => true,
    ];
}
