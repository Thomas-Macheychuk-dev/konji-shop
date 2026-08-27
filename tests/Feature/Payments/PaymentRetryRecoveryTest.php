<?php

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('payments.default', 'test');

    $this->retryGateway = new class implements PaymentGateway
    {
        public int $initializeCalls = 0;

        public bool $shouldFail = false;

        public function providerKey(): string
        {
            return 'test';
        }

        public function initialize(Order $order, Payment $payment): PaymentInitializationResult
        {
            $this->initializeCalls++;

            if ($this->shouldFail) {
                throw new RuntimeException('temporary provider outage');
            }

            return new PaymentInitializationResult(
                provider: 'test',
                providerReference: 'retry-payment-'.$payment->id,
                redirectUrl: 'https://payments.example.test/retry/'.$payment->id,
                payload: [
                    'status' => 'NEW',
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
    };

    $gateway = $this->retryGateway;

    app()->singleton(
        PaymentGatewayRegistry::class,
        fn (): PaymentGatewayRegistry => new PaymentGatewayRegistry([$gateway]),
    );
});

function retryableGuestOrder(): array
{
    $order = Order::factory()->guest('retry@example.test')->unpaid()->create([
        'total_amount' => 12345,
        'currency' => 'PLN',
    ]);

    $payment = Payment::factory()->forOrder($order)->create([
        'provider' => null,
        'provider_reference' => null,
        'status' => PaymentStatus::UNPAID,
        'amount' => 12345,
        'currency' => 'PLN',
        'paid_at' => null,
        'payload' => null,
    ]);

    return [$order, $payment];
}

it('retries payment initialization for the checkout session using the same local payment attempt', function (): void {
    [$order, $payment] = retryableGuestOrder();

    $response = $this
        ->withSession(['checkout.last_order_id' => $order->id])
        ->post(route('payments.retry', $order));

    $response->assertRedirect('https://payments.example.test/retry/'.$payment->id);

    $order->refresh();
    $payment->refresh();

    expect($this->retryGateway->initializeCalls)->toBe(1)
        ->and($order->payments()->count())->toBe(1)
        ->and($order->payment_status)->toBe(PaymentStatus::PENDING)
        ->and($payment->id)->toBe($order->payments()->firstOrFail()->id)
        ->and($payment->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->provider)->toBe('test')
        ->and($payment->provider_reference)->toBe('retry-payment-'.$payment->id);

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'payment_initialization_retry_requested',
    ]);

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'payment_pending',
    ]);
});

it('keeps the same payment retryable when the provider is still unavailable', function (): void {
    [$order, $payment] = retryableGuestOrder();
    $this->retryGateway->shouldFail = true;

    $this->withSession(['checkout.last_order_id' => $order->id])
        ->from(route('checkout.success'))
        ->post(route('payments.retry', $order))
        ->assertRedirect(route('checkout.success'))
        ->assertSessionHas('error');

    $order->refresh();
    $payment->refresh();

    expect($this->retryGateway->initializeCalls)->toBe(1)
        ->and($order->payments()->count())->toBe(1)
        ->and($order->payment_status)->toBe(PaymentStatus::UNPAID)
        ->and($payment->status)->toBe(PaymentStatus::UNPAID)
        ->and($payment->provider_reference)->toBeNull()
        ->and($order->canRetryPaymentInitialization())->toBeTrue();
});

it('does not initialize payment twice after a retry has already succeeded', function (): void {
    [$order] = retryableGuestOrder();
    $session = ['checkout.last_order_id' => $order->id];

    $this->withSession($session)
        ->post(route('payments.retry', $order))
        ->assertRedirect();

    $this->withSession($session)
        ->from(route('checkout.success'))
        ->post(route('payments.retry', $order))
        ->assertRedirect(route('checkout.success'))
        ->assertSessionHas('error');

    expect($this->retryGateway->initializeCalls)->toBe(1)
        ->and($order->payments()->count())->toBe(1);
});

it('does not expose another guest order to an unauthorised retry request', function (): void {
    [$order] = retryableGuestOrder();

    $this->post(route('payments.retry', $order))->assertNotFound();

    expect($this->retryGateway->initializeCalls)->toBe(0);
});

it('allows only the owning authenticated customer to retry their order', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $order = Order::factory()->forUser($owner)->unpaid()->create([
        'total_amount' => 10000,
        'currency' => 'PLN',
    ]);

    Payment::factory()->forOrder($order)->create([
        'status' => PaymentStatus::UNPAID,
        'provider_reference' => null,
    ]);

    $this->actingAs($otherUser)
        ->post(route('payments.retry', $order))
        ->assertNotFound();

    expect($this->retryGateway->initializeCalls)->toBe(0);

    $this->actingAs($owner)
        ->post(route('payments.retry', $order))
        ->assertRedirect();

    expect($this->retryGateway->initializeCalls)->toBe(1);
});

it('refuses retry when the local payment no longer matches the order total', function (): void {
    [$order, $payment] = retryableGuestOrder();

    $payment->update(['amount' => $order->total_amount - 100]);

    $this->withSession(['checkout.last_order_id' => $order->id])
        ->from(route('checkout.success'))
        ->post(route('payments.retry', $order))
        ->assertRedirect(route('checkout.success'))
        ->assertSessionHas('error');

    expect($this->retryGateway->initializeCalls)->toBe(0);
});

it('shows recovery UI when checkout has a durable order with an unpaid payment', function (): void {
    [$order] = retryableGuestOrder();

    $this->withSession(['checkout.last_order_id' => $order->id])
        ->get(route('checkout.success'))
        ->assertOk()
        ->assertSee('Płatność wymaga ponowienia')
        ->assertSee('Ponów płatność')
        ->assertSee('Zamówienie zostało zapisane, ale płatność nie została rozpoczęta.');
});

it('does not show retry UI for a paid order', function (): void {
    $order = Order::factory()->paid()->create();

    Payment::factory()->forOrder($order)->paid()->create();

    $this->actingAs(User::factory()->create())
        ->withSession(['checkout.last_order_id' => $order->id])
        ->get(route('checkout.success'))
        ->assertOk()
        ->assertDontSee('Ponów płatność');
});
