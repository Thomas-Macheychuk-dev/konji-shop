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
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the admin withdrawal index page', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.index'))
        ->assertOk()
        ->assertSee('Contract withdrawals')
        ->assertSee('Review customer withdrawal requests submitted through the one-click withdrawal flow.')
        ->assertSee($withdrawalRequest->number)
        ->assertSee($withdrawalRequest->order_number_snapshot)
        ->assertSee($withdrawalRequest->customer_name)
        ->assertSee($withdrawalRequest->customer_email)
        ->assertSee('Withdrawals')
        ->assertSee(route('admin.withdrawals.index'), false)
        ->assertSee('Acknowledged')
        ->assertSee(route('admin.withdrawals.show', $withdrawalRequest), false);
});

it('shows the admin withdrawal detail page', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    [$withdrawalRequest, $orderItem] = adminWithdrawalTestRequest();

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawalRequest))
        ->assertOk()
        ->assertSee('Withdrawal '.$withdrawalRequest->number)
        ->assertSee('Selected items')
        ->assertSee($orderItem->product_name_snapshot)
        ->assertSee($orderItem->sku_snapshot)
        ->assertSee('Quantity requested')
        ->assertSee('1 / 2')
        ->assertSee('Amount gross')
        ->assertSee('123.00 PLN')
        ->assertSee('Customer statement')
        ->assertSee('Changed my mind.')
        ->assertSee('Please process the withdrawal.')
        ->assertSee('Refund to original payment method.')
        ->assertSee('Details')
        ->assertSee($withdrawalRequest->order_number_snapshot)
        ->assertSee($withdrawalRequest->customer_name)
        ->assertSee($withdrawalRequest->customer_email)
        ->assertSee('Submission IP')
        ->assertSee('203.0.113.50')
        ->assertSee('User agent')
        ->assertSee('KonjiAdminWithdrawalTestBrowser/1.0')
        ->assertSee(route('admin.orders.show', $withdrawalRequest->order), false);
});

it('does not allow guests to view the admin withdrawal index page', function (): void {
    $this
        ->get(route('admin.withdrawals.index'))
        ->assertRedirect(route('login'));
});

it('does not allow non-admin users to view the admin withdrawal index page', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->get(route('admin.withdrawals.index'))
        ->assertForbidden();
});

/**
 * @return array{0: WithdrawalRequest, 1: OrderItem}
 */
function adminWithdrawalTestRequest(): array
{
    $product = Product::query()->create([
        'name' => 'Admin Withdrawal Product',
        'slug' => 'admin-withdrawal-product-'.str()->lower(str()->random(8)),
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'ADMIN-WITHDRAWAL-SKU-'.str()->upper(str()->random(6)),
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $order = Order::factory()->create([
        'number' => 'ORD-ADMIN-WITHDRAWAL-'.str()->upper(str()->random(6)),
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'currency' => Currency::PLN->value,
        'subtotal_amount' => 24600,
        'items_net_amount' => 20000,
        'items_tax_amount' => 4600,
        'items_gross_amount' => 24600,
        'shipping_amount' => 0,
        'shipping_net_amount' => 0,
        'shipping_tax_amount' => 0,
        'shipping_gross_amount' => 0,
        'tax_amount' => 4600,
        'total_amount' => 24600,
        'placed_at' => now(),
    ]);

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Admin Withdrawal Product',
        'variant_name_snapshot' => 'Default',
        'sku_snapshot' => $variant->sku,

        'unit_price_amount' => 12300,
        'unit_net_amount' => 10000,
        'unit_tax_amount' => 2300,
        'unit_gross_amount' => 12300,

        'quantity' => 2,

        'line_total_amount' => 24600,
        'line_net_amount' => 20000,
        'line_tax_amount' => 4600,
        'line_gross_amount' => 24600,

        'vat_rate_snapshot' => 23,
        'meta' => null,
    ]);

    $withdrawalRequest = WithdrawalRequest::query()->create([
        'order_id' => $order->id,
        'user_id' => null,
        'number' => 'WD-ADMIN-'.str()->upper(str()->random(8)),
        'status' => WithdrawalStatus::ACKNOWLEDGED,
        'customer_name' => 'Admin Test Customer',
        'customer_email' => 'withdrawal-admin@example.test',
        'order_number_snapshot' => $order->number,
        'reason' => 'Changed my mind.',
        'customer_note' => 'Please process the withdrawal.',
        'refund_note' => 'Refund to original payment method.',
        'submitted_at' => now(),
        'acknowledged_at' => now(),
        'submission_ip' => '203.0.113.50',
        'submission_user_agent' => 'KonjiAdminWithdrawalTestBrowser/1.0',
        'meta' => [
            'source' => 'guest',
        ],
    ]);

    $withdrawalRequest->items()->create([
        'order_item_id' => $orderItem->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => $orderItem->product_name_snapshot,
        'variant_name_snapshot' => $orderItem->variant_name_snapshot,
        'sku_snapshot' => $orderItem->sku_snapshot,
        'quantity_ordered' => 2,
        'quantity_requested' => 1,
        'unit_gross_amount' => 12300,
        'line_gross_amount' => 12300,
        'meta' => null,
    ]);

    return [$withdrawalRequest->load(['order', 'items']), $orderItem];
}
