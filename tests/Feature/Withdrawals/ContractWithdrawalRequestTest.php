<?php

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Enums\WithdrawalStatus;
use App\Mail\WithdrawalAcknowledgementMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use App\Models\WithdrawalRequest;

uses(RefreshDatabase::class);

it('shows the public withdrawal start page', function (): void {
    $this
        ->get(route('withdrawals.start'))
        ->assertOk()
        ->assertSee('Withdraw from contract here')
        ->assertSee('I have an account')
        ->assertSee('I ordered as a guest')
        ->assertSee('You do not need to provide a reason')
        ->assertSee(route('guest.orders.track.show'), false);
});

it('shows a withdrawal button on the account order detail page', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@gmail.com',
        'email_verified_at' => now(),
    ]);

    [$order] = withdrawalFeatureTestOrderWithItem(
        user: $user,
        guestEmail: null,
    );

    $this
        ->actingAs($user)
        ->get(route('account.orders.show', $order->id))
        ->assertOk()
        ->assertSee('Withdraw from contract')
        ->assertSee(route('account.orders.withdrawals.create', $order->id), false);
});

it('shows a withdrawal button on the guest order detail page', function (): void {
    [$order] = withdrawalFeatureTestOrderWithItem(
        user: null,
        guestEmail: 'guest@gmail.com',
    );

    $this
        ->withSession([
            'guest_order_access' => [
                'order_id' => $order->id,
            ],
        ])
        ->get(route('guest.orders.show', $order))
        ->assertOk()
        ->assertSee('Withdraw from contract')
        ->assertSee(route('guest.orders.withdrawals.create', $order), false);
});

it('shows the account withdrawal form to the order owner', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@gmail.com',
        'email_verified_at' => now(),
    ]);

    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: $user,
        guestEmail: null,
    );

    $this
        ->actingAs($user)
        ->get(route('account.orders.withdrawals.create', $order->id))
        ->assertOk()
        ->assertSee('Withdraw from contract')
        ->assertSee('Order '.$order->number)
        ->assertSee($orderItem->product_name_snapshot)
        ->assertSee('Confirm withdrawal')
        ->assertSee('Reason')
        ->assertSee('(optional)');
});

it('allows an authenticated customer to submit a withdrawal request', function (): void {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'customer@gmail.com',
        'email_verified_at' => now(),
    ]);

    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: $user,
        guestEmail: null,
    );

    $this
        ->actingAs($user)
        ->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.40',
            'HTTP_USER_AGENT' => 'KonjiAccountWithdrawalBrowser/1.0',
        ])
        ->post(route('account.orders.withdrawals.store', $order->id), [
            'customer_name' => 'Jan Kowalski',
            'customer_email' => 'customer@gmail.com',
            'items' => [
                $orderItem->id => 1,
            ],
            'reason' => '',
            'customer_note' => 'I withdraw from this item.',
            'refund_note' => 'Please refund to the original payment method.',
            'statement_confirmed' => '1',
            'source' => 'account',
        ])
        ->assertRedirect(route('account.orders.show', $order->id))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('withdrawal_requests', [
        'order_id' => $order->id,
        'user_id' => $user->id,
        'status' => WithdrawalStatus::ACKNOWLEDGED->value,
        'customer_name' => 'Jan Kowalski',
        'customer_email' => 'customer@gmail.com',
        'order_number_snapshot' => $order->number,
        'customer_note' => 'I withdraw from this item.',
        'refund_note' => 'Please refund to the original payment method.',
        'submission_ip' => '203.0.113.40',
        'submission_user_agent' => 'KonjiAccountWithdrawalBrowser/1.0',
    ]);

    $this->assertDatabaseHas('withdrawal_request_items', [
        'order_item_id' => $orderItem->id,
        'product_name_snapshot' => $orderItem->product_name_snapshot,
        'sku_snapshot' => $orderItem->sku_snapshot,
        'quantity_ordered' => 2,
        'quantity_requested' => 1,
        'unit_gross_amount' => 12300,
        'line_gross_amount' => 12300,
    ]);

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'withdrawal_request_submitted',
    ]);

    Mail::assertSent(WithdrawalAcknowledgementMail::class, function (WithdrawalAcknowledgementMail $mail): bool {
        return $mail->withdrawalRequest->customer_email === 'customer@gmail.com';
    });
});

it('shows the guest withdrawal form when the guest has order access', function (): void {
    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: null,
        guestEmail: 'guest@gmail.com',
    );

    $this
        ->withSession([
            'guest_order_access' => [
                'order_id' => $order->id,
            ],
        ])
        ->get(route('guest.orders.withdrawals.create', $order))
        ->assertOk()
        ->assertSee('Withdraw from contract')
        ->assertSee('Order '.$order->number)
        ->assertSee($orderItem->product_name_snapshot)
        ->assertSee('Confirm withdrawal');
});

it('allows a guest customer with order access to submit a withdrawal request', function (): void {
    Mail::fake();

    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: null,
        guestEmail: 'guest@gmail.com',
    );

    $this
        ->withSession([
            'guest_order_access' => [
                'order_id' => $order->id,
            ],
        ])
        ->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.41',
            'HTTP_USER_AGENT' => 'KonjiGuestWithdrawalBrowser/1.0',
        ])
        ->post(route('guest.orders.withdrawals.store', $order), [
            'customer_name' => 'Guest Customer',
            'customer_email' => 'guest@gmail.com',
            'items' => [
                $orderItem->id => 2,
            ],
            'reason' => '',
            'customer_note' => '',
            'refund_note' => '',
            'statement_confirmed' => '1',
            'source' => 'guest',
        ])
        ->assertRedirect(route('guest.orders.show', $order))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('withdrawal_requests', [
        'order_id' => $order->id,
        'user_id' => null,
        'status' => WithdrawalStatus::ACKNOWLEDGED->value,
        'customer_name' => 'Guest Customer',
        'customer_email' => 'guest@gmail.com',
        'order_number_snapshot' => $order->number,
        'submission_ip' => '203.0.113.41',
        'submission_user_agent' => 'KonjiGuestWithdrawalBrowser/1.0',
    ]);

    $this->assertDatabaseHas('withdrawal_request_items', [
        'order_item_id' => $orderItem->id,
        'quantity_ordered' => 2,
        'quantity_requested' => 2,
        'line_gross_amount' => 24600,
    ]);

    Mail::assertSent(WithdrawalAcknowledgementMail::class, function (WithdrawalAcknowledgementMail $mail): bool {
        return $mail->withdrawalRequest->customer_email === 'guest@gmail.com';
    });
});

it('requires withdrawal statement confirmation', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@gmail.com',
        'email_verified_at' => now(),
    ]);

    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: $user,
        guestEmail: null,
    );

    $this
        ->actingAs($user)
        ->post(route('account.orders.withdrawals.store', $order->id), [
            'customer_name' => 'Jan Kowalski',
            'customer_email' => 'customer@gmail.com',
            'items' => [
                $orderItem->id => 1,
            ],
            'source' => 'account',
        ])
        ->assertSessionHasErrors('statement_confirmed');

    $this->assertDatabaseCount('withdrawal_requests', 0);
});

it('does not allow a guest without order access to open the withdrawal form', function (): void {
    [$order] = withdrawalFeatureTestOrderWithItem(
        user: null,
        guestEmail: 'guest@gmail.com',
    );

    $this
        ->get(route('guest.orders.withdrawals.create', $order))
        ->assertForbidden();
});

/**
 * @return array{0: Order, 1: OrderItem}
 */
function withdrawalFeatureTestOrderWithItem(?User $user, ?string $guestEmail): array
{
    $product = Product::query()->create([
        'name' => 'Withdrawal Feature Product',
        'slug' => 'withdrawal-feature-product-'.str()->lower(str()->random(8)),
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'WITHDRAWAL-FEATURE-SKU-'.str()->upper(str()->random(6)),
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user?->id,
        'guest_email' => $guestEmail,
        'number' => 'ORD-WITHDRAWAL-FEATURE-'.str()->upper(str()->random(6)),
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

    $order->shippingAddress()->create([
        'type' => 'shipping',
        'first_name' => $user ? 'Jan' : 'Guest',
        'last_name' => $user ? 'Kowalski' : 'Customer',
        'company' => null,
        'phone' => '123456789',
        'email' => $user?->email ?? $guestEmail,
        'address_line_1' => 'Test Street 1',
        'address_line_2' => null,
        'city' => 'Warszawa',
        'postcode' => '00-001',
        'country_code' => 'PL',
    ]);

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Withdrawal Feature Product',
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

    return [$order, $orderItem];
}

it('shows withdrawal history on the account order detail page', function (): void {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'customer@gmail.com',
        'email_verified_at' => now(),
    ]);

    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: $user,
        guestEmail: null,
    );

    $this
        ->actingAs($user)
        ->post(route('account.orders.withdrawals.store', $order->id), [
            'customer_name' => 'Jan Kowalski',
            'customer_email' => 'customer@gmail.com',
            'items' => [
                $orderItem->id => 1,
            ],
            'statement_confirmed' => '1',
            'source' => 'account',
        ])
        ->assertRedirect(route('account.orders.show', $order->id));

    $withdrawalRequest = WithdrawalRequest::query()->firstOrFail();

    $this
        ->actingAs($user)
        ->get(route('account.orders.show', $order->id))
        ->assertOk()
        ->assertSee('Contract withdrawal requests')
        ->assertSee($withdrawalRequest->number)
        ->assertSee('Acknowledged')
        ->assertSee($orderItem->product_name_snapshot)
        ->assertSee('Qty 1 / 2');
});

it('shows withdrawal history on the guest order detail page', function (): void {
    Mail::fake();

    [$order, $orderItem] = withdrawalFeatureTestOrderWithItem(
        user: null,
        guestEmail: 'guest@gmail.com',
    );

    $session = [
        'guest_order_access' => [
            'order_id' => $order->id,
        ],
    ];

    $this
        ->withSession($session)
        ->post(route('guest.orders.withdrawals.store', $order), [
            'customer_name' => 'Guest Customer',
            'customer_email' => 'guest@gmail.com',
            'items' => [
                $orderItem->id => 2,
            ],
            'statement_confirmed' => '1',
            'source' => 'guest',
        ])
        ->assertRedirect(route('guest.orders.show', $order));

    $withdrawalRequest = WithdrawalRequest::query()->firstOrFail();

    $this
        ->withSession($session)
        ->get(route('guest.orders.show', $order))
        ->assertOk()
        ->assertSee('Contract withdrawal requests')
        ->assertSee($withdrawalRequest->number)
        ->assertSee('Acknowledged')
        ->assertSee($orderItem->product_name_snapshot)
        ->assertSee('Qty 2 / 2');
});
