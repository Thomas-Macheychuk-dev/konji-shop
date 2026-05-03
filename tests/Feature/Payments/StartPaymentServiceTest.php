<?php

declare(strict_types=1);

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\StartPaymentService;

it('starts a payment and updates payment and order state', function (): void {
    $order = Order::factory()->create([
        'status' => \App\Enums\OrderStatus::PENDING_PAYMENT,
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

    $result = $service->start(
        $order,
        $payment,
        PaymentProvider::PRZELEWY24->value,
    );

    $payment->refresh();
    $order->refresh();

    expect($result->provider)->toBe(PaymentProvider::PRZELEWY24->value)
        ->and($result->providerReference)->toBe('fake-p24-' . $payment->id)
        ->and($result->redirectUrl)->not->toBeEmpty();

    expect($payment->provider)->toBe(PaymentProvider::PRZELEWY24->value)
        ->and($payment->provider_reference)->toBe('fake-p24-' . $payment->id)
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

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The payment does not belong to the given order.');

    $service->start(
        $order,
        $payment,
        PaymentProvider::PRZELEWY24->value,
    );
});
