<?php

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\HandlePaymentNotificationService;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeGatewayForNotification(
    string $externalStatus,
    bool $isVerified = true,
    string $providerReference = 'pay_123',
): PaymentGateway {
    return new class($externalStatus, $isVerified, $providerReference) implements PaymentGateway {
        public function __construct(
            private readonly string $externalStatus,
            private readonly bool $isVerified,
            private readonly string $providerReference,
        ) {}

        public function providerKey(): string
        {
            return 'test_gateway';
        }

        public function initialize(Order $order, Payment $payment): PaymentInitializationResult
        {
            throw new RuntimeException('Not needed for this test.');
        }

        public function parseNotification(array $payload): PaymentNotificationData
        {
            return new PaymentNotificationData(
                providerReference: $this->providerReference,
                isSuccessful: $this->externalStatus === 'CONFIRMED',
                externalStatus: $this->externalStatus,
                payload: $payload,
            );
        }

        public function verifyNotification(Payment $payment, array $payload, ?string $rawBody = null): bool
        {
            return $this->isVerified;
        }
    };
}

it('marks payment and order as paid when notification is confirmed', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::PENDING,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::PENDING,
    ]);

    $registry = new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED'),
    ]);

    $service = new HandlePaymentNotificationService($registry);

    $service->handle('test_gateway', [
        'paymentId' => 'pay_123',
        'externalId' => $order->id,
    ], '{"paymentId":"pay_123"}');

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::PAID)
        ->external_status->toBe('CONFIRMED')
        ->paid_at->not->toBeNull();

    expect($order->refresh())
        ->status->toBe(OrderStatus::CONFIRMED)
        ->payment_status->toBe(PaymentStatus::PAID);
});

it('marks payment as failed when notification is rejected', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::PENDING,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::PENDING,
    ]);

    $registry = new PaymentGatewayRegistry([
        fakeGatewayForNotification('REJECTED'),
    ]);

    $service = new HandlePaymentNotificationService($registry);

    $service->handle('test_gateway', [
        'paymentId' => 'pay_123',
        'externalId' => $order->id,
    ], '{"paymentId":"pay_123"}');

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::FAILED)
        ->external_status->toBe('REJECTED');

    expect($order->refresh())
        ->status->toBe(OrderStatus::PENDING_PAYMENT)
        ->payment_status->toBe(PaymentStatus::PENDING);
});

it('throws when notification signature is invalid', function (): void {
    $order = Order::factory()->create([
        'payment_status' => PaymentStatus::PENDING,
    ]);

    Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::PENDING,
    ]);

    $registry = new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED', isVerified: false),
    ]);

    $service = new HandlePaymentNotificationService($registry);

    $service->handle('test_gateway', [
        'paymentId' => 'pay_123',
        'externalId' => $order->id,
    ], '{"paymentId":"pay_123"}');
})->throws(RuntimeException::class, 'Invalid Paynow signature');
