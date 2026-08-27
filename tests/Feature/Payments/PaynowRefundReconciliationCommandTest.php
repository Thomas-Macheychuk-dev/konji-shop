<?php

use App\Enums\Currency;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentRefundStatus;
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
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('reconciles a pending Paynow refund and finalizes the local refund only after SUCCESSFUL', function (): void {
    Mail::fake();
    config()->set('payments.providers.paynow.api_key', 'refund-api-key');
    config()->set('payments.providers.paynow.signature_key', 'refund-signature-key');
    config()->set('payments.providers.paynow.sandbox', true);

    [$order, $payment, $withdrawalRequest, $refund] = pendingPaynowRefundFixture();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/refunds/REFU-123-ABC-456/status' => Http::response([
            'refundId' => 'REFU-123-ABC-456',
            'status' => 'SUCCESSFUL',
        ]),
    ]);

    expect(Artisan::call('paynow:reconcile-refunds', ['--limit' => 10]))->toBe(0);

    expect($refund->refresh())
        ->status->toBe(PaymentRefundStatus::SUCCESSFUL)
        ->completed_at->not->toBeNull();

    expect($withdrawalRequest->refresh())
        ->status->toBe(WithdrawalStatus::REFUNDED)
        ->refunded_at->not->toBeNull();

    expect($payment->refresh()->status)->toBe(PaymentStatus::REFUNDED);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::REFUNDED);

    Mail::assertSent(WithdrawalRefundedMail::class, 1);
});

/**
 * @return array{0: Order, 1: Payment, 2: WithdrawalRequest, 3: PaymentRefund}
 */
function pendingPaynowRefundFixture(): array
{
    $product = Product::query()->create([
        'name' => 'Paynow reconciliation product',
        'slug' => 'paynow-reconcile-'.str()->lower(str()->random(8)),
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'PAYNOW-REC-'.str()->upper(str()->random(6)),
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $order = Order::factory()->create([
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
        'placed_at' => now()->subDays(2),
    ]);

    $payment = Payment::factory()
        ->forOrder($order)
        ->paid()
        ->create([
            'provider' => 'paynow',
            'provider_reference' => 'PAYM-123-ABC-456',
            'external_status' => 'CONFIRMED',
            'amount' => 12300,
            'currency' => Currency::PLN->value,
        ]);

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => $product->name,
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
    ]);

    $withdrawalRequest = WithdrawalRequest::query()->create([
        'order_id' => $order->id,
        'number' => 'WD-REC-'.str()->upper(str()->random(8)),
        'status' => WithdrawalStatus::REFUND_PENDING,
        'customer_name' => 'Refund Customer',
        'customer_email' => 'refund-customer@example.test',
        'order_number_snapshot' => $order->number,
        'submitted_at' => now()->subDay(),
        'acknowledged_at' => now()->subDay(),
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
    ]);

    $refund = PaymentRefund::query()->create([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'provider' => 'paynow',
        'provider_refund_id' => 'REFU-123-ABC-456',
        'status' => PaymentRefundStatus::PENDING,
        'amount' => 12300,
        'currency' => Currency::PLN->value,
        'reason' => 'REFUND_BEFORE_14',
        'idempotency_key' => 'refund-command-idempotency-key',
        'withdrawal_request_ids' => [$withdrawalRequest->id],
        'withdrawal_statuses' => [
            (string) $withdrawalRequest->id => WithdrawalStatus::ACKNOWLEDGED->value,
        ],
    ]);

    return [$order, $payment, $withdrawalRequest, $refund];
}
