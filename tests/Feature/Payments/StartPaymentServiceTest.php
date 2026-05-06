<?php

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\StartPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->singleton(PaymentGatewayRegistry::class, function (): PaymentGatewayRegistry {
        return new PaymentGatewayRegistry([
            new class implements PaymentGateway
            {
                public function providerKey(): string
                {
                    return 'test';
                }

                public function initialize(Order $order, Payment $payment): PaymentInitializationResult
                {
                    return new PaymentInitializationResult(
                        provider: 'test',
                        providerReference: 'fake-payment-'.$payment->id,
                        redirectUrl: 'https://payments.example.test/redirect/'.$payment->id,
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
});

it('starts a payment and updates payment and order state', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'total_amount' => 12345,
        'currency' => 'PLN',
    ]);

    $payment = Payment::factory()->forOrder($order)->create([
        'provider' => null,
        'provider_reference' => null,
        'status' => PaymentStatus::UNPAID,
        'payload' => null,
        'paid_at' => null,
    ]);

    /** @var StartPaymentService $service */
    $service = app(StartPaymentService::class);

    $result = $service->start($order, $payment, 'test');

    $payment->refresh();
    $order->refresh();

    expect($result->provider)->toBe('test')
        ->and($result->providerReference)->toBe('fake-payment-'.$payment->id)
        ->and($result->redirectUrl)->toBe('https://payments.example.test/redirect/'.$payment->id);

    expect($payment->provider)->toBe('test')
        ->and($payment->provider_reference)->toBe('fake-payment-'.$payment->id)
        ->and($payment->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->payload)->toBeArray();

    expect($order->payment_status)->toBe(PaymentStatus::PENDING);
});

it('throws when the payment does not belong to the given order', function (): void {
    $order = Order::factory()->create();
    $otherOrder = Order::factory()->create();

    $payment = Payment::factory()->forOrder($otherOrder)->create();

    /** @var StartPaymentService $service */
    $service = app(StartPaymentService::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('The payment does not belong to the given order.');

    $service->start($order, $payment, 'test');
});
