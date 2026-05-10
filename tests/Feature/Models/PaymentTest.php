<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks a payment as pending', function (): void {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::UNPAID,
    ]);

    $payment->markAsPending();

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING);
});

it('marks a payment as paid', function (): void {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::PENDING,
        'paid_at' => null,
    ]);

    $payment->markAsPaid();

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::PAID)
        ->paid_at->not->toBeNull();
});

it('marks a payment as failed', function (): void {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::PENDING,
    ]);

    $payment->markAsFailed();

    expect($payment->refresh()->status)->toBe(PaymentStatus::FAILED);
});

it('records an event when payment is marked as pending', function (): void {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::UNPAID,
    ]);

    $payment->markAsPending();

    expect($payment->order->events()->where('type', 'payment_pending')->exists())->toBeTrue();
});

it('records an event when payment is marked as paid', function (): void {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::PENDING,
        'paid_at' => null,
    ]);

    $payment->markAsPaid();

    expect($payment->order->events()->where('type', 'payment_paid')->exists())->toBeTrue();
});

it('records an event when payment is marked as failed', function (): void {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::PENDING,
    ]);

    $payment->markAsFailed();

    expect($payment->order->events()->where('type', 'payment_failed')->exists())->toBeTrue();
});

it('records an event when a payment notification is recorded', function (): void {
    $payment = Payment::factory()->create();

    $payment->recordNotification('CONFIRMED', [
        'paymentId' => 'pay_123',
    ]);

    $event = $payment->order
        ->events()
        ->where('type', 'payment_notification_received')
        ->first();

    expect($event)
        ->not->toBeNull()
        ->and($event->meta)->toBe([
            'external_status' => 'CONFIRMED',
        ]);
});
