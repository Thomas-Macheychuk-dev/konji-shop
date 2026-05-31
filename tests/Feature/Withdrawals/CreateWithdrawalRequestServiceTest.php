<?php

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Enums\WithdrawalStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Withdrawals\CreateWithdrawalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a withdrawal request with selected order item snapshots', function (): void {
    [$order, $orderItem] = withdrawalTestOrderWithItem(quantity: 2);

    $withdrawalRequest = app(CreateWithdrawalRequestService::class)->create($order, [
        'customer_name' => 'Jan Kowalski',
        'customer_email' => 'jan@example.test',
        'items' => [
            $orderItem->id => 1,
        ],
        'reason' => null,
        'customer_note' => 'I want to withdraw from part of the order.',
        'refund_note' => 'Refund to original payment method.',
        'statement_confirmed' => true,
        'submission_ip' => '203.0.113.30',
        'submission_user_agent' => 'KonjiWithdrawalTestBrowser/1.0',
        'source' => 'account',
    ]);

    expect($withdrawalRequest)
        ->order_id->toBe($order->id)
        ->status->toBe(WithdrawalStatus::SUBMITTED)
        ->customer_name->toBe('Jan Kowalski')
        ->customer_email->toBe('jan@example.test')
        ->order_number_snapshot->toBe($order->number)
        ->customer_note->toBe('I want to withdraw from part of the order.')
        ->refund_note->toBe('Refund to original payment method.')
        ->submission_ip->toBe('203.0.113.30');

    expect($withdrawalRequest->items)->toHaveCount(1);

    $withdrawalItem = $withdrawalRequest->items->first();

    expect($withdrawalItem)
        ->order_item_id->toBe($orderItem->id)
        ->product_name_snapshot->toBe($orderItem->product_name_snapshot)
        ->sku_snapshot->toBe($orderItem->sku_snapshot)
        ->quantity_ordered->toBe(2)
        ->quantity_requested->toBe(1)
        ->unit_gross_amount->toBe(12300)
        ->line_gross_amount->toBe(12300);

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'withdrawal_request_submitted',
        'description' => 'Customer submitted a contract withdrawal request.',
    ]);
});

it('does not allow requesting more than the remaining item quantity', function (): void {
    [$order, $orderItem] = withdrawalTestOrderWithItem(quantity: 1);

    app(CreateWithdrawalRequestService::class)->create($order, [
        'customer_name' => 'Jan Kowalski',
        'customer_email' => 'jan@example.test',
        'items' => [
            $orderItem->id => 1,
        ],
        'statement_confirmed' => true,
    ]);

    app(CreateWithdrawalRequestService::class)->create($order->refresh(), [
        'customer_name' => 'Jan Kowalski',
        'customer_email' => 'jan@example.test',
        'items' => [
            $orderItem->id => 1,
        ],
        'statement_confirmed' => true,
    ]);
})->throws(DomainException::class);

it('requires at least one selected order item', function (): void {
    [$order] = withdrawalTestOrderWithItem(quantity: 1);

    app(CreateWithdrawalRequestService::class)->create($order, [
        'customer_name' => 'Jan Kowalski',
        'customer_email' => 'jan@example.test',
        'items' => [],
        'statement_confirmed' => true,
    ]);
})->throws(DomainException::class);

/**
 * @return array{0: Order, 1: OrderItem}
 */
function withdrawalTestOrderWithItem(int $quantity): array
{
    $product = Product::query()->create([
        'name' => 'Withdrawal Product',
        'slug' => 'withdrawal-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'WITHDRAWAL-SKU',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-WITHDRAWAL-'.str()->upper(str()->random(6)),
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'currency' => Currency::PLN->value,
        'subtotal_amount' => 12300 * $quantity,
        'items_net_amount' => 10000 * $quantity,
        'items_tax_amount' => 2300 * $quantity,
        'items_gross_amount' => 12300 * $quantity,
        'shipping_amount' => 0,
        'shipping_net_amount' => 0,
        'shipping_tax_amount' => 0,
        'shipping_gross_amount' => 0,
        'tax_amount' => 2300 * $quantity,
        'total_amount' => 12300 * $quantity,
        'placed_at' => now(),
    ]);

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Withdrawal Product',
        'variant_name_snapshot' => 'Default',
        'sku_snapshot' => 'WITHDRAWAL-SKU',

        'unit_price_amount' => 12300,
        'unit_net_amount' => 10000,
        'unit_tax_amount' => 2300,
        'unit_gross_amount' => 12300,

        'quantity' => $quantity,

        'line_total_amount' => 12300 * $quantity,
        'line_net_amount' => 10000 * $quantity,
        'line_tax_amount' => 2300 * $quantity,
        'line_gross_amount' => 12300 * $quantity,

        'vat_rate_snapshot' => 23,
        'meta' => null,
    ]);

    return [$order, $orderItem];
}
