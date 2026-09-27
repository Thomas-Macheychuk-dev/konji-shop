<?php

use App\Enums\FulfilmentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders persisted customer payment state instead of raw Paynow return status', function (): void {
    $order = Order::factory()->paid()->create([
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->paid()->create([
        'provider' => 'paynow',
        'provider_reference' => 'P7W8-Q56-C8P-9HA',
        'external_status' => 'CONFIRMED',
    ]);

    $this->get(route('checkout.success', [
        'paymentId' => $payment->provider_reference,
        'paymentStatus' => 'CONFIRMED',
    ]))
        ->assertOk()
        ->assertSee('Płatność została potwierdzona. Dziękujemy za zamówienie.')
        ->assertSee('Potwierdzone')
        ->assertSee('Opłacone')
        ->assertSee('Oczekuje na realizację')
        ->assertDontSee('Status operatora płatności')
        ->assertDontSee('CONFIRMED');
});

it('keeps a persisted paid payment successful even when the return query is stale or tampered', function (): void {
    $order = Order::factory()->paid()->create([
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->paid()->create([
        'provider' => 'paynow',
        'provider_reference' => 'PAID-WITH-STALE-RETURN',
        'external_status' => 'CONFIRMED',
    ]);

    $this->get(route('checkout.success', [
        'paymentId' => $payment->provider_reference,
        'paymentStatus' => 'ERROR',
    ]))
        ->assertOk()
        ->assertSee('Płatność została potwierdzona. Dziękujemy za zamówienie.')
        ->assertSee('Opłacone')
        ->assertDontSee('Coś poszło nie tak');
});

it('does not claim payment is confirmed until the persisted payment is paid', function (): void {
    $order = Order::factory()->pendingPayment()->create([
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->pending()->create([
        'provider' => 'paynow',
        'provider_reference' => 'PENDING-PAYNOW-RETURN',
        'external_status' => 'NEW',
    ]);

    $this->get(route('checkout.success', [
        'paymentId' => $payment->provider_reference,
        'paymentStatus' => 'CONFIRMED',
    ]))
        ->assertOk()
        ->assertSee('Płatność jest przetwarzana')
        ->assertSee('Oczekujące')
        ->assertDontSee('Płatność została potwierdzona. Dziękujemy za zamówienie.')
        ->assertDontSee('Status operatora płatności')
        ->assertDontSee('CONFIRMED');
});

it('renders checkout return labels through the active locale', function (): void {
    app()->setLocale('en');

    $order = Order::factory()->paid()->create([
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $payment = Payment::factory()->forOrder($order)->paid()->create([
        'provider' => 'paynow',
        'provider_reference' => 'ENGLISH-PAYNOW-RETURN',
        'external_status' => 'CONFIRMED',
    ]);

    $this->get(route('checkout.success', [
        'paymentId' => $payment->provider_reference,
        'paymentStatus' => 'CONFIRMED',
    ]))
        ->assertOk()
        ->assertSee('Payment confirmed. Thank you for your order.')
        ->assertSee('Order status')
        ->assertSee('Confirmed')
        ->assertSee('Paid')
        ->assertSee('Awaiting fulfilment')
        ->assertDontSee('Status operatora płatności')
        ->assertDontSee('CONFIRMED');
});
