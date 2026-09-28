<?php

use App\Enums\Currency;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('shows a Paynow refund action for a cancelled paid order without a withdrawal', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$order] = cancelledPaidOrderRefundFixture();

    $this
        ->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Zwrot za anulowane zamówienie')
        ->assertSee('Zwróć środki')
        ->assertSee(route('admin.orders.fulfilment.update', [$order, 'cancellation-refund']), false);
});

it('fully refunds a cancelled paid order through Paynow without creating a withdrawal', function (): void {
    configureCancellationRefundPaynowTest();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFU-CANCEL-123',
            'status' => 'SUCCESSFUL',
        ], 201),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$order, $payment] = cancelledPaidOrderRefundFixture();

    $this
        ->actingAs($admin)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'cancellation-refund']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Paynow potwierdził zwrot za anulowane zamówienie.');

    expect($order->refresh())
        ->status->toBe(OrderStatus::CANCELLED)
        ->payment_status->toBe(PaymentStatus::REFUNDED)
        ->fulfilment_status->toBe(FulfilmentStatus::CANCELLED);

    expect($payment->refresh())
        ->status->toBe(PaymentStatus::REFUNDED)
        ->and(data_get($payment->payload, 'refunds.0.source'))->toBe('admin_order_cancellation_refund');

    $refund = PaymentRefund::query()->sole();

    expect($refund)
        ->status->toBe(PaymentRefundStatus::SUCCESSFUL)
        ->amount->toBe(1776)
        ->provider_refund_id->toBe('REFU-CANCEL-123')
        ->withdrawal_request_ids->toBe([])
        ->completed_at->not->toBeNull();

    expect($order->withdrawalRequests()->count())->toBe(0);

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'order_cancellation_refund_processed',
    ]);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v3/payments/PAYM-CANCEL-123/refunds')
            && $request['amount'] === 1776
            && $request['reason'] === 'OTHER';
    });
});

it('keeps a cancelled-order refund open until Paynow reports success', function (): void {
    configureCancellationRefundPaynowTest();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFU-CANCEL-123',
            'status' => 'PENDING',
        ], 201),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$order, $payment] = cancelledPaidOrderRefundFixture();

    $this
        ->actingAs($admin)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'cancellation-refund']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Zwrot za anulowane zamówienie został zlecony w Paynow i oczekuje na potwierdzenie.');

    expect($order->refresh())
        ->payment_status->toBe(PaymentStatus::PAID)
        ->fulfilment_status->toBe(FulfilmentStatus::CANCELLED);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PAID);

    $refund = PaymentRefund::query()->sole();

    expect($refund)
        ->status->toBe(PaymentRefundStatus::PENDING)
        ->provider_refund_id->toBe('REFU-CANCEL-123')
        ->completed_at->toBeNull();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/refunds/REFU-CANCEL-123/status' => Http::response([
            'refundId' => 'REFU-CANCEL-123',
            'status' => 'SUCCESSFUL',
        ]),
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'cancellation-refund']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Paynow potwierdził zwrot za anulowane zamówienie.');

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::REFUNDED);
    expect($refund->refresh())
        ->status->toBe(PaymentRefundStatus::SUCCESSFUL)
        ->completed_at->not->toBeNull();
});

/**
 * @return array{0: Order, 1: Payment}
 */
function cancelledPaidOrderRefundFixture(): array
{
    $order = Order::factory()->create([
        'number' => 'ORD-CANCEL-'.str()->upper(str()->random(6)),
        'status' => OrderStatus::CANCELLED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
        'currency' => Currency::PLN->value,
        'subtotal_amount' => 400,
        'items_net_amount' => 370,
        'items_tax_amount' => 30,
        'items_gross_amount' => 400,
        'shipping_amount' => 1376,
        'shipping_net_amount' => 1119,
        'shipping_tax_amount' => 257,
        'shipping_gross_amount' => 1376,
        'tax_amount' => 287,
        'total_amount' => 1776,
        'placed_at' => now(),
    ]);

    $payment = Payment::factory()
        ->forOrder($order)
        ->paid()
        ->create([
            'provider' => 'paynow',
            'provider_reference' => 'PAYM-CANCEL-123',
            'external_status' => 'CONFIRMED',
            'amount' => 1776,
            'currency' => Currency::PLN->value,
        ]);

    return [$order, $payment];
}

function configureCancellationRefundPaynowTest(): void
{
    config()->set('payments.providers.paynow.api_key', 'paynow-test-api-key');
    config()->set('payments.providers.paynow.signature_key', 'paynow-test-signature-key');
    config()->set('payments.providers.paynow.sandbox', true);
    config()->set('payments.providers.paynow.connect_timeout', 2);
    config()->set('payments.providers.paynow.timeout', 5);
}
