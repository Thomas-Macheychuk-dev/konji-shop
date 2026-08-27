<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts a signed Paynow notification with an empty 200 response', function (): void {
    config()->set('payments.providers.paynow.signature_key', 'notification-signature-key');

    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::PENDING,
    ]);

    Payment::factory()->forOrder($order)->create([
        'provider' => 'paynow',
        'provider_reference' => 'PBLX-WEBHOOK',
        'status' => PaymentStatus::PENDING,
    ]);

    $rawBody = json_encode([
        'paymentId' => 'PBLX-WEBHOOK',
        'externalId' => (string) $order->id,
        'status' => 'CONFIRMED',
        'modifiedAt' => '2026-08-27T00:00:00+00:00',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $signature = base64_encode(hash_hmac(
        'sha256',
        $rawBody,
        'notification-signature-key',
        true,
    ));

    $response = $this->call(
        'POST',
        route('payments.paynow.notifications'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURE' => $signature,
        ],
        content: $rawBody,
    );

    $response->assertOk();

    expect($response->getContent())->toBe('')
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::PAID);
});
