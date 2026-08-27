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
    return new class($externalStatus, $isVerified, $providerReference) implements PaymentGateway
    {
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
                externalId: (string) ($payload['externalId'] ?? ''),
                modifiedAt: (string) ($payload['modifiedAt'] ?? ''),
            );
        }

        public function verifyNotification(Payment $payment, array $payload, ?string $rawBody = null): bool
        {
            return $this->isVerified;
        }
    };
}

function paymentNotificationPayload(Order $order, string $status, string $modifiedAt = '2026-08-27T00:00:00+00:00'): array
{
    return [
        'paymentId' => 'pay_123',
        'externalId' => (string) $order->id,
        'status' => $status,
        'modifiedAt' => $modifiedAt,
    ];
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

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED'),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'CONFIRMED'),
        '{"paymentId":"pay_123"}',
    );

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::PAID)
        ->external_status->toBe('CONFIRMED')
        ->paid_at->not->toBeNull();

    expect($order->refresh())
        ->status->toBe(OrderStatus::CONFIRMED)
        ->payment_status->toBe(PaymentStatus::PAID);
});

it('keeps NEW and PENDING notifications in the pending local state', function (string $externalStatus): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::UNPAID,
    ]);

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification($externalStatus),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, $externalStatus),
        '{"paymentId":"pay_123"}',
    );

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::PENDING)
        ->external_status->toBe($externalStatus);

    expect($order->refresh())
        ->status->toBe(OrderStatus::PENDING_PAYMENT)
        ->payment_status->toBe(PaymentStatus::PENDING);
})->with(['NEW', 'PENDING']);

it('marks terminal Paynow failures as retryable locally', function (string $externalStatus): void {
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

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification($externalStatus),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, $externalStatus),
        '{"paymentId":"pay_123"}',
    );

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::FAILED)
        ->external_status->toBe($externalStatus);

    expect($order->refresh())
        ->status->toBe(OrderStatus::PENDING_PAYMENT)
        ->payment_status->toBe(PaymentStatus::UNPAID)
        ->canRetryPaymentInitialization()->toBeTrue();
})->with(['REJECTED', 'ERROR', 'EXPIRED', 'ABANDONED']);

it('ignores an exact replay without creating duplicate payment or order events', function (): void {
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

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED'),
    ]));
    $payload = paymentNotificationPayload($order, 'CONFIRMED');

    $service->handle('test_gateway', $payload, '{"paymentId":"pay_123"}');
    $service->handle('test_gateway', $payload, '{"paymentId":"pay_123"}');

    expect($order->events()->where('type', 'payment_paid')->count())->toBe(1)
        ->and($order->events()->where('type', 'order_confirmed')->count())->toBe(1)
        ->and($order->events()->where('type', 'payment_notification_received')->count())->toBe(1);
});

it('ignores stale out-of-order notifications using Paynow modifiedAt', function (): void {
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

    $confirmedService = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED'),
    ]));
    $rejectedService = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('REJECTED'),
    ]));

    $confirmedService->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'CONFIRMED', '2026-08-27T00:05:00+00:00'),
        '{"paymentId":"pay_123"}',
    );

    $rejectedService->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'REJECTED', '2026-08-27T00:04:00+00:00'),
        '{"paymentId":"pay_123"}',
    );

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::PAID)
        ->external_status->toBe('CONFIRMED');

    expect($order->refresh())
        ->status->toBe(OrderStatus::CONFIRMED)
        ->payment_status->toBe(PaymentStatus::PAID);

    expect($order->events()->where('type', 'payment_notification_received')->count())->toBe(1);
});

it('does not regress a locally paid payment even when a later failure notification arrives', function (): void {
    $order = Order::factory()->paid()->create();
    $payment = Payment::factory()->forOrder($order)->paid()->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'external_status' => 'CONFIRMED',
        'payload' => paymentNotificationPayload($order, 'CONFIRMED', '2026-08-27T00:05:00+00:00'),
    ]);

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('ERROR'),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'ERROR', '2026-08-27T00:06:00+00:00'),
        '{"paymentId":"pay_123"}',
    );

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::PAID)
        ->external_status->toBe('CONFIRMED');

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::PAID);
});

it('scopes the payment lookup to the notification provider', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::PENDING,
    ]);

    Payment::factory()->forOrder($order)->create([
        'provider' => 'other_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::PENDING,
    ]);

    $expectedPayment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::PENDING,
    ]);

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED'),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'CONFIRMED'),
        '{"paymentId":"pay_123"}',
    );

    expect($expectedPayment->refresh()->status)->toBe(PaymentStatus::PAID)
        ->and(Payment::query()->where('provider', 'other_gateway')->firstOrFail()->status)->toBe(PaymentStatus::PENDING);
});

it('rejects a signed notification whose externalId does not match the payment order', function (): void {
    $order = Order::factory()->create([
        'payment_status' => PaymentStatus::PENDING,
    ]);
    $otherOrder = Order::factory()->create();

    $payment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::PENDING,
    ]);

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED'),
    ]));

    try {
        $service->handle(
            'test_gateway',
            paymentNotificationPayload($otherOrder, 'CONFIRMED'),
            '{"paymentId":"pay_123"}',
        );

        $this->fail('Expected mismatched externalId to be rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Payment notification external ID does not match the payment order.');
    }

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING);
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

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('CONFIRMED', isVerified: false),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'CONFIRMED'),
        '{"paymentId":"pay_123"}',
    );
})->throws(RuntimeException::class, 'Invalid payment notification signature.');

it('does not let an old failed attempt override a newer pending retry attempt', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::PENDING,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $oldPayment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_123',
        'status' => PaymentStatus::FAILED,
        'external_status' => 'REJECTED',
        'payload' => paymentNotificationPayload($order, 'REJECTED', '2026-08-27T00:05:00+00:00'),
    ]);

    $newPayment = Payment::factory()->forOrder($order)->create([
        'provider' => 'test_gateway',
        'provider_reference' => 'pay_456',
        'status' => PaymentStatus::PENDING,
    ]);

    $service = new HandlePaymentNotificationService(new PaymentGatewayRegistry([
        fakeGatewayForNotification('ABANDONED'),
    ]));

    $service->handle(
        'test_gateway',
        paymentNotificationPayload($order, 'ABANDONED', '2026-08-27T00:06:00+00:00'),
        '{"paymentId":"pay_123"}',
    );

    expect($oldPayment->refresh())
        ->status->toBe(PaymentStatus::FAILED)
        ->external_status->toBe('ABANDONED');

    expect($newPayment->refresh()->status)->toBe(PaymentStatus::PENDING)
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::PENDING);
});
