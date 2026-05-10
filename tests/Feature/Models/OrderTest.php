<?php

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks an order as paid and confirms it', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markAsPaid();

    expect($order->refresh())
        ->status->toBe(OrderStatus::CONFIRMED)
        ->payment_status->toBe(PaymentStatus::PAID);
});

it('does not confirm an unpaid order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $order->confirm();
})->throws(DomainException::class);

it('cancels a pending unpaid unfulfilled order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
        'placed_at' => now(),
    ]);

    $order->cancel();

    expect($order->refresh()->status)->toBe(OrderStatus::CANCELLED);
});

it('does not cancel a confirmed order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
        'placed_at' => now(),
    ]);

    $order->cancel();
})->throws(DomainException::class);

it('marks a confirmed order as fulfilment processing', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markFulfilmentAsProcessing();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::PROCESSING);
});

it('does not ship an unpaid order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markAsShipped();
})->throws(DomainException::class);

it('marks a confirmed paid order as shipped', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
    ]);

    $order->markAsShipped();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::SHIPPED);
});

it('marks a shipped order as delivered', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $order->markAsDelivered();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});

it('completes a confirmed delivered order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
    ]);

    $order->complete();

    expect($order->refresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('marks payment as pending for an unpaid order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markPaymentAsPending();

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::PENDING);
});

it('does not mark payment as pending when order is already paid', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markPaymentAsPending();
})->throws(DomainException::class);

it('appends a cancellation note when cancelling an order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
        'placed_at' => now(),
        'notes' => 'Existing note',
    ]);

    $order->cancel('Cancelled by customer');

    expect($order->refresh())
        ->status->toBe(OrderStatus::CANCELLED)
        ->notes->toBe('Existing note'.PHP_EOL.'Cancelled by customer');
});

it('records an event when an order is confirmed', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markAsPaid();

    expect($order->events()->where('type', 'order_confirmed')->exists())->toBeTrue();
});

it('records an event when an order is cancelled', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
        'placed_at' => now(),
    ]);

    $order->cancel('Cancelled by customer');

    expect($order->events()->where('type', 'order_cancelled')->exists())->toBeTrue();
});

it('records events for fulfilment transitions', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markFulfilmentAsProcessing();
    $order->markAsShipped();
    $order->markAsDelivered();
    $order->complete();

    expect($order->events()->pluck('type')->all())->toContain(
        'fulfilment_processing_started',
        'order_shipped',
        'order_delivered',
        'order_completed',
    );
});
