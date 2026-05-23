<?php

declare(strict_types=1);

namespace App\Services\Payments\Paynow;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class PaynowGateway implements PaymentGateway
{
    public function providerKey(): string
    {
        return PaymentProvider::PAYNOW->value;
    }

    public function initialize(Order $order, Payment $payment): PaymentInitializationResult
    {
        $config = config('payments.providers.paynow');

        $baseUrl = $config['sandbox']
            ? 'https://api.sandbox.paynow.pl'
            : 'https://api.paynow.pl';

        // Payment amount is already stored in minor units.
        // Example: 180.00 PLN = 18000 groszy.
        $amountInGrosze = (int) $payment->amount;

        $body = [
            'amount' => $amountInGrosze,
            'currency' => $payment->currency ?? 'PLN',
            'externalId' => (string) $order->id,
            'description' => "Zamówienie #{$order->number}",
            'buyer' => [
                'email' => $order->user?->email ?? $order->guest_email ?? 'test@example.com',
            ],
            'continueUrl' => url($config['return_path']),
        ];

        $rawBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($rawBody === false) {
            throw new RuntimeException('Could not encode Paynow request body.');
        }

        $signature = base64_encode(
            hash_hmac('sha256', $rawBody, $config['signature_key'], true)
        );

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Api-Key' => $config['api_key'],
            'Signature' => $signature,
            'Idempotency-Key' => (string) Str::uuid(),
        ])
            ->withBody($rawBody, 'application/json')
            ->post("{$baseUrl}/v1/payments");

        if (! $response->successful() || ($response->json('status') ?? '') !== 'SUCCESS') {
            throw new RuntimeException('Paynow initialize failed: '.$response->body());
        }

        $data = $response->json();

        return new PaymentInitializationResult(
            provider: $this->providerKey(),
            providerReference: $data['paymentId'],
            redirectUrl: $data['redirectUrl'],
            payload: $data,
        );
    }

    public function parseNotification(array $payload): PaymentNotificationData
    {
        $status = $payload['status'] ?? 'ERROR';

        return new PaymentNotificationData(
            providerReference: $payload['paymentId'] ?? '',
            isSuccessful: $status === 'CONFIRMED',
            externalStatus: $status,
            payload: $payload,
        );
    }

    public function verifyNotification(Payment $payment, array $payload, ?string $rawBody = null): bool
    {
        $config = config('payments.providers.paynow');

        if (empty($config['signature_key']) || $rawBody === null) {
            return false;
        }

        $computedSignature = base64_encode(
            hash_hmac('sha256', $rawBody, $config['signature_key'], true)
        );

        $receivedSignature = request()->header('Signature');

        return hash_equals($computedSignature, $receivedSignature ?? '');
    }
}
