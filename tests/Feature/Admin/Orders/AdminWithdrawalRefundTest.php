<?php

use App\Enums\Currency;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Enums\WithdrawalStatus;
use App\Mail\WithdrawalRefundedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('shows a refund action on the admin order page when an order has a refundable withdrawal request', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$order, $withdrawalRequest] = adminOrderWithdrawalRefundFixture();

    $this
        ->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Withdrawal refund requested')
        ->assertSee($withdrawalRequest->number)
        ->assertSee('Refund')
        ->assertSee(route('admin.orders.fulfilment.update', [$order, 'refund']), false);
});

it('processes a withdrawal refund and emails the customer', function (): void {
    Mail::fake();

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$order, $withdrawalRequest, $payment] = adminOrderWithdrawalRefundFixture();

    $this
        ->actingAs($admin)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'refund']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Withdrawal refund processed and customer notified.');

    expect($withdrawalRequest->refresh())
        ->status->toBe(WithdrawalStatus::REFUNDED)
        ->refunded_at->not->toBeNull();

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::REFUNDED);
    expect($payment->refresh()->status)->toBe(PaymentStatus::REFUNDED);

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'withdrawal_refund_processed',
    ]);

    Mail::assertSent(WithdrawalRefundedMail::class, function (WithdrawalRefundedMail $mail) use ($withdrawalRequest): bool {
        return $mail->withdrawalRequest->is($withdrawalRequest)
            && $mail->withdrawalRequest->customer_email === 'refund-customer@example.test';
    });
});

/**
 * @return array{0: Order, 1: WithdrawalRequest, 2: Payment}
 */
function adminOrderWithdrawalRefundFixture(): array
{
    $product = Product::query()->create([
        'name' => 'Refund Product',
        'slug' => 'refund-product-'.str()->lower(str()->random(8)),
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'REFUND-SKU-'.str()->upper(str()->random(6)),
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-REFUND-'.str()->upper(str()->random(6)),
        'guest_email' => 'refund-customer@example.test',
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
        'currency' => Currency::PLN->value,
        'subtotal_amount' => 12300,
        'items_net_amount' => 10000,
        'items_tax_amount' => 2300,
        'items_gross_amount' => 12300,
        'shipping_amount' => 0,
        'shipping_net_amount' => 0,
        'shipping_tax_amount' => 0,
        'shipping_gross_amount' => 0,
        'tax_amount' => 2300,
        'total_amount' => 12300,
        'placed_at' => now(),
    ]);

    $payment = Payment::factory()
        ->forOrder($order)
        ->paid()
        ->create([
            'provider' => 'paynow',
            'provider_reference' => 'refund-payment-reference',
            'amount' => 12300,
            'currency' => Currency::PLN->value,
        ]);

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Refund Product',
        'variant_name_snapshot' => 'Default',
        'sku_snapshot' => $variant->sku,
        'unit_price_amount' => 12300,
        'unit_net_amount' => 10000,
        'unit_tax_amount' => 2300,
        'unit_gross_amount' => 12300,
        'quantity' => 1,
        'line_total_amount' => 12300,
        'line_net_amount' => 10000,
        'line_tax_amount' => 2300,
        'line_gross_amount' => 12300,
        'vat_rate_snapshot' => 23,
        'meta' => null,
    ]);

    $withdrawalRequest = WithdrawalRequest::query()->create([
        'order_id' => $order->id,
        'user_id' => null,
        'number' => 'WD-REFUND-'.str()->upper(str()->random(8)),
        'status' => WithdrawalStatus::ACKNOWLEDGED,
        'customer_name' => 'Refund Customer',
        'customer_email' => 'refund-customer@example.test',
        'order_number_snapshot' => $order->number,
        'reason' => null,
        'customer_note' => null,
        'refund_note' => null,
        'submitted_at' => now(),
        'acknowledged_at' => now(),
        'submission_ip' => '203.0.113.60',
        'submission_user_agent' => 'KonjiRefundTestBrowser/1.0',
        'meta' => null,
    ]);

    $withdrawalRequest->items()->create([
        'order_item_id' => $orderItem->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => $orderItem->product_name_snapshot,
        'variant_name_snapshot' => $orderItem->variant_name_snapshot,
        'sku_snapshot' => $orderItem->sku_snapshot,
        'quantity_ordered' => 1,
        'quantity_requested' => 1,
        'unit_gross_amount' => 12300,
        'line_gross_amount' => 12300,
        'meta' => null,
    ]);

    return [$order, $withdrawalRequest->load(['order', 'items']), $payment];
}
