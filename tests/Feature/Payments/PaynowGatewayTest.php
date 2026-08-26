<?php

use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Paynow\PaynowGateway;
use App\Services\Payments\Paynow\PaynowSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.url', 'https://shop.example.test');
    URL::forceRootUrl('https://shop.example.test');
    URL::forceScheme('https');
    config()->set('payments.providers.paynow', [
        'api_key' => 'test-api-key',
        'signature_key' => 'test-signature-key',
        'sandbox' => true,
        'connect_timeout' => 5,
        'timeout' => 15,
        'notification_path' => '/api/payments/paynow/notifications',
        'return_path' => '/checkout/success',
    ]);
});

function paynowOrderAndPayment(): array
{
    $order = Order::factory()->guest('buyer@example.test')->create([
        'number' => 'ORD-PAYNOW-001',
        'total_amount' => 12345,
        'currency' => 'PLN',
    ]);

    $payment = Payment::factory()->forOrder($order)->create([
        'amount' => 12345,
        'currency' => 'PLN',
    ]);

    return [$order, $payment];
}

it('creates a Paynow v3 payment in sandbox with a valid v3 signature', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        'https://api.sandbox.paynow.pl/v3/payments' => Http::response([
            'redirectUrl' => 'https://paywall.sandbox.paynow.pl/PBLX-123?token=test-token',
            'paymentId' => 'PBLX-123',
            'status' => 'NEW',
        ], 201),
    ]);

    $result = (new PaynowGateway)->initialize($order, $payment);

    expect($result->provider)->toBe('paynow')
        ->and($result->providerReference)->toBe('PBLX-123')
        ->and($result->redirectUrl)->toBe('https://paywall.sandbox.paynow.pl/PBLX-123?token=test-token')
        ->and($result->payload['status'])->toBe('NEW');

    Http::assertSent(function (Request $request) use ($order, $payment): bool {
        $idempotencyKey = 'konji-payment-'.$payment->id;
        $rawBody = $request->body();
        $expectedSignature = PaynowSignature::forRequest(
            apiKey: 'test-api-key',
            signatureKey: 'test-signature-key',
            idempotencyKey: $idempotencyKey,
            body: $rawBody,
        );

        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        expect($request->url())->toBe('https://api.sandbox.paynow.pl/v3/payments')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Api-Key'))->toBe(['test-api-key'])
            ->and($request->header('Idempotency-Key'))->toBe([$idempotencyKey])
            ->and($request->header('Signature'))->toBe([$expectedSignature])
            ->and($payload['amount'])->toBe(12345)
            ->and($payload['currency'])->toBe('PLN')
            ->and($payload['externalId'])->toBe((string) $order->id)
            ->and($payload['description'])->toBe('Zamówienie #ORD-PAYNOW-001')
            ->and($payload['buyer']['email'])->toBe('buyer@example.test')
            ->and($payload['continueUrl'])->toBe('https://shop.example.test/checkout/success');

        return true;
    });
});

it('accepts a created response without the optional initial status', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        '*' => Http::response([
            'redirectUrl' => 'https://paywall.sandbox.paynow.pl/PBLX-123A?token=test-token',
            'paymentId' => 'PBLX-123A',
        ], 201),
    ]);

    $result = (new PaynowGateway)->initialize($order, $payment);

    expect($result->providerReference)->toBe('PBLX-123A')
        ->and(array_key_exists('status', $result->payload))->toBeFalse();
});

it('accepts PENDING as a valid initial Paynow status', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        '*' => Http::response([
            'redirectUrl' => 'https://paywall.sandbox.paynow.pl/PBLX-124?token=test-token',
            'paymentId' => 'PBLX-124',
            'status' => 'PENDING',
        ], 201),
    ]);

    $result = (new PaynowGateway)->initialize($order, $payment);

    expect($result->providerReference)->toBe('PBLX-124')
        ->and($result->payload['status'])->toBe('PENDING');
});

it('uses the production Paynow v3 endpoint when sandbox is disabled', function (): void {
    [$order, $payment] = paynowOrderAndPayment();
    config()->set('payments.providers.paynow.sandbox', false);

    Http::fake([
        'https://api.paynow.pl/v3/payments' => Http::response([
            'redirectUrl' => 'https://paywall.paynow.pl/PBLX-125?token=test-token',
            'paymentId' => 'PBLX-125',
            'status' => 'NEW',
        ], 201),
    ]);

    (new PaynowGateway)->initialize($order, $payment);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.paynow.pl/v3/payments');
});

it('uses a stable idempotency key for the same local payment attempt', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        '*' => Http::response([
            'redirectUrl' => 'https://paywall.sandbox.paynow.pl/PBLX-126?token=test-token',
            'paymentId' => 'PBLX-126',
            'status' => 'NEW',
        ], 201),
    ]);

    $gateway = new PaynowGateway;
    $gateway->initialize($order, $payment);
    $gateway->initialize($order, $payment);

    $keys = collect(Http::recorded())
        ->map(fn (array $record): ?string => $record[0]->header('Idempotency-Key')[0] ?? null)
        ->all();

    expect($keys)->toBe([
        'konji-payment-'.$payment->id,
        'konji-payment-'.$payment->id,
    ]);
});

it('rejects a Paynow ERROR initialization state', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        '*' => Http::response([
            'redirectUrl' => 'https://paywall.sandbox.paynow.pl/PBLX-ERR?token=test-token',
            'paymentId' => 'PBLX-ERR',
            'status' => 'ERROR',
        ], 201),
    ]);

    (new PaynowGateway)->initialize($order, $payment);
})->throws(RuntimeException::class, 'Payment provider did not create a payable payment.');

it('rejects malformed successful responses', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        '*' => Http::response([
            'paymentId' => 'PBLX-NO-URL',
            'status' => 'NEW',
        ], 201),
    ]);

    (new PaynowGateway)->initialize($order, $payment);
})->throws(RuntimeException::class, 'Payment provider returned an invalid initialization response.');

it('does not expose Paynow error response bodies to callers', function (int $status): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake([
        '*' => Http::response([
            'statusCode' => $status,
            'errors' => [[
                'errorType' => 'SYSTEM_TEMPORARILY_UNAVAILABLE',
                'message' => 'sensitive-provider-detail',
            ]],
        ], $status),
    ]);

    try {
        (new PaynowGateway)->initialize($order, $payment);
        $this->fail('Expected Paynow initialization to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toBe('Payment provider rejected payment initialization.')
            ->not->toContain('sensitive-provider-detail');
    }
})->with([400, 401, 429, 503]);

it('wraps connection failures in a safe payment-provider error', function (): void {
    [$order, $payment] = paynowOrderAndPayment();

    Http::fake(function (): never {
        throw new ConnectionException('socket failure with provider internals');
    });

    try {
        (new PaynowGateway)->initialize($order, $payment);
        $this->fail('Expected Paynow initialization to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toBe('Payment provider is temporarily unavailable.')
            ->not->toContain('provider internals');
    }
});

it('fails fast when Paynow credentials are missing', function (): void {
    [$order, $payment] = paynowOrderAndPayment();
    config()->set('payments.providers.paynow.api_key', '');

    Http::preventStrayRequests();

    (new PaynowGateway)->initialize($order, $payment);
})->throws(RuntimeException::class, 'Paynow is not configured for payment initialization.');
