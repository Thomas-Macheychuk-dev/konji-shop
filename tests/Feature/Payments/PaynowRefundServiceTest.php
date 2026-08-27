<?php

use App\Enums\Currency;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Payments\Paynow\PaynowRefundService;
use App\Services\Payments\Paynow\PaynowSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('payments.providers.paynow.api_key', 'refund-api-key');
    config()->set('payments.providers.paynow.signature_key', 'refund-signature-key');
    config()->set('payments.providers.paynow.sandbox', true);
    config()->set('payments.providers.paynow.connect_timeout', 2);
    config()->set('payments.providers.paynow.timeout', 5);
});

it('creates a Paynow v3 refund with the ledger idempotency key and signed body', function (): void {
    [$payment, $refund] = paynowRefundServiceFixture();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFU-123-ABC-456',
            'status' => 'PENDING',
        ], 201),
    ]);

    $result = app(PaynowRefundService::class)->create($payment, $refund);

    expect($result)
        ->providerRefundId->toBe('REFU-123-ABC-456')
        ->status->toBe(PaymentRefundStatus::PENDING);

    Http::assertSent(function (Request $request) use ($payment, $refund): bool {
        $rawBody = (string) $request->body();
        $expectedSignature = PaynowSignature::forRequest(
            apiKey: 'refund-api-key',
            signatureKey: 'refund-signature-key',
            idempotencyKey: $refund->idempotency_key,
            body: $rawBody,
        );

        return $request->method() === 'POST'
            && $request->url() === "https://api.sandbox.paynow.pl/v3/payments/{$payment->provider_reference}/refunds"
            && $request->header('Api-Key')[0] === 'refund-api-key'
            && $request->header('Idempotency-Key')[0] === $refund->idempotency_key
            && $request->header('Signature')[0] === $expectedSignature
            && $request->data() === [
                'amount' => 12300,
                'reason' => 'REFUND_BEFORE_14',
            ];
    });
});

it('queries Paynow refund status with the same stable idempotency key', function (): void {
    [, $refund] = paynowRefundServiceFixture([
        'provider_refund_id' => 'REFU-123-ABC-456',
        'status' => PaymentRefundStatus::PENDING,
    ]);

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/refunds/REFU-123-ABC-456/status' => Http::response([
            'refundId' => 'REFU-123-ABC-456',
            'status' => 'SUCCESSFUL',
        ]),
    ]);

    $result = app(PaynowRefundService::class)->status($refund);

    expect($result->status)->toBe(PaymentRefundStatus::SUCCESSFUL);

    Http::assertSent(function (Request $request) use ($refund): bool {
        $expectedSignature = PaynowSignature::forRequest(
            apiKey: 'refund-api-key',
            signatureKey: 'refund-signature-key',
            idempotencyKey: $refund->idempotency_key,
        );

        return $request->method() === 'GET'
            && $request->url() === 'https://api.sandbox.paynow.pl/v3/refunds/REFU-123-ABC-456/status'
            && $request->header('Idempotency-Key')[0] === $refund->idempotency_key
            && $request->header('Signature')[0] === $expectedSignature;
    });
});

it('fails closed on an unsupported Paynow refund state', function (): void {
    [$payment, $refund] = paynowRefundServiceFixture();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFU-123-ABC-456',
            'status' => 'UNKNOWN',
        ], 201),
    ]);

    app(PaynowRefundService::class)->create($payment, $refund);
})->throws(RuntimeException::class, 'Paynow zwrócił nieobsługiwany status zwrotu.');

/**
 * @param  array<string, mixed>  $refundOverrides
 * @return array{0: Payment, 1: PaymentRefund}
 */
function paynowRefundServiceFixture(array $refundOverrides = []): array
{
    $order = Order::factory()->create([
        'payment_status' => PaymentStatus::PAID,
        'currency' => Currency::PLN->value,
        'total_amount' => 12300,
    ]);

    $payment = Payment::factory()
        ->forOrder($order)
        ->paid()
        ->create([
            'provider' => 'paynow',
            'provider_reference' => 'PAYM-123-ABC-456',
            'amount' => 12300,
            'currency' => Currency::PLN->value,
        ]);

    $refund = PaymentRefund::query()->create(array_merge([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'provider' => 'paynow',
        'status' => PaymentRefundStatus::REQUESTED,
        'amount' => 12300,
        'currency' => Currency::PLN->value,
        'reason' => 'REFUND_BEFORE_14',
        'idempotency_key' => 'refund-idempotency-key-123',
        'withdrawal_request_ids' => [1],
        'withdrawal_statuses' => ['1' => 'acknowledged'],
    ], $refundOverrides));

    return [$payment, $refund];
}
