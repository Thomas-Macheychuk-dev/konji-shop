<?php

declare(strict_types=1);

namespace App\Services\Payments\Paynow;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentInitializationResult;
use App\Data\Payments\PaymentNotificationData;
use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

final class PaynowGateway implements PaymentGateway
{
    /** @var list<string> */
    private const ACCEPTED_INITIAL_STATUSES = ['NEW', 'PENDING'];

    public function providerKey(): string
    {
        return PaymentProvider::PAYNOW->value;
    }

    public function initialize(Order $order, Payment $payment): PaymentInitializationResult
    {
        /** @var array<string, mixed> $config */
        $config = config('payments.providers.paynow');

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $signatureKey = trim((string) ($config['signature_key'] ?? ''));

        if ($apiKey === '' || $signatureKey === '') {
            throw new RuntimeException('Paynow is not configured for payment initialization.');
        }

        $buyerEmail = trim((string) ($order->user?->email ?? $order->guest_email ?? ''));

        if ($buyerEmail === '' || filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid buyer email is required to initialize Paynow payment.');
        }

        $baseUrl = (bool) ($config['sandbox'] ?? true)
            ? 'https://api.sandbox.paynow.pl'
            : 'https://api.paynow.pl';

        $body = [
            // Paynow expects the amount in the smallest currency unit (grosz for PLN).
            'amount' => (int) $payment->amount,
            'currency' => $payment->currency ?? 'PLN',
            'externalId' => (string) $order->id,
            'description' => "Zamówienie #{$order->number}",
            'buyer' => [
                'email' => $buyerEmail,
            ],
            'continueUrl' => url((string) ($config['return_path'] ?? '/checkout/success')),
        ];

        try {
            $rawBody = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not prepare Paynow payment request.', 0, $exception);
        }

        // Stable for one local Payment row, so a transport-level retry can safely reuse
        // the same key without creating another Paynow payment. A future payment retry
        // should use a new local Payment attempt and therefore receives a new key.
        $idempotencyKey = 'konji-payment-'.$payment->id;

        try {
            $signature = PaynowSignature::forRequest(
                apiKey: $apiKey,
                signatureKey: $signatureKey,
                idempotencyKey: $idempotencyKey,
                body: $rawBody,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not sign Paynow payment request.', 0, $exception);
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $apiKey,
                'Signature' => $signature,
                'Idempotency-Key' => $idempotencyKey,
            ])
                ->connectTimeout(max(1, (int) ($config['connect_timeout'] ?? 5)))
                ->timeout(max(1, (int) ($config['timeout'] ?? 15)))
                ->withBody($rawBody, 'application/json')
                ->post("{$baseUrl}/v3/payments");
        } catch (ConnectionException $exception) {
            Log::warning('Paynow payment initialization connection failure', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            throw new RuntimeException('Payment provider is temporarily unavailable.', 0, $exception);
        }

        return $this->initializationResult($response, $order, $payment);
    }

    public function parseNotification(array $payload): PaymentNotificationData
    {
        $providerReference = trim((string) ($payload['paymentId'] ?? ''));
        $externalId = trim((string) ($payload['externalId'] ?? ''));
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));
        $modifiedAt = trim((string) ($payload['modifiedAt'] ?? ''));

        $supportedStatuses = [
            'NEW',
            'PENDING',
            'CONFIRMED',
            'REJECTED',
            'ERROR',
            'EXPIRED',
            'ABANDONED',
        ];

        if (
            $providerReference === ''
            || $externalId === ''
            || $modifiedAt === ''
            || ! in_array($status, $supportedStatuses, true)
        ) {
            throw new RuntimeException('Malformed or unsupported Paynow notification.');
        }

        return new PaymentNotificationData(
            providerReference: $providerReference,
            isSuccessful: $status === 'CONFIRMED',
            externalStatus: $status,
            payload: $payload,
            externalId: $externalId,
            modifiedAt: $modifiedAt,
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

    private function initializationResult(
        Response $response,
        Order $order,
        Payment $payment,
    ): PaymentInitializationResult {
        $data = $response->json();

        if ($response->status() !== 201 || ! is_array($data)) {
            $this->logInitializationFailure($response, $order, $payment, $data);

            throw new RuntimeException('Payment provider rejected payment initialization.');
        }

        $status = strtoupper(trim((string) ($data['status'] ?? '')));
        $providerReference = trim((string) ($data['paymentId'] ?? ''));
        $redirectUrl = trim((string) ($data['redirectUrl'] ?? ''));

        if ($status === 'ERROR' || ($status !== '' && ! in_array($status, self::ACCEPTED_INITIAL_STATUSES, true))) {
            $this->logInitializationFailure($response, $order, $payment, $data);

            throw new RuntimeException('Payment provider did not create a payable payment.');
        }

        if ($providerReference === '' || ! $this->isSecureRedirectUrl($redirectUrl)) {
            $this->logInitializationFailure($response, $order, $payment, $data);

            throw new RuntimeException('Payment provider returned an invalid initialization response.');
        }

        return new PaymentInitializationResult(
            provider: $this->providerKey(),
            providerReference: $providerReference,
            redirectUrl: $redirectUrl,
            payload: $data,
        );
    }

    private function logInitializationFailure(
        Response $response,
        Order $order,
        Payment $payment,
        mixed $data,
    ): void {
        Log::warning('Paynow payment initialization rejected', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'http_status' => $response->status(),
            'external_status' => is_array($data) ? ($data['status'] ?? null) : null,
            'error_types' => $this->errorTypes($data),
        ]);
    }

    /**
     * @return list<string>
     */
    private function errorTypes(mixed $data): array
    {
        if (! is_array($data) || ! isset($data['errors']) || ! is_array($data['errors'])) {
            return [];
        }

        $types = [];

        foreach ($data['errors'] as $error) {
            if (is_array($error) && isset($error['errorType']) && is_string($error['errorType'])) {
                $types[] = $error['errorType'];
            }
        }

        return array_values(array_unique($types));
    }

    private function isSecureRedirectUrl(string $redirectUrl): bool
    {
        if (filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return strtolower((string) parse_url($redirectUrl, PHP_URL_SCHEME)) === 'https';
    }
}
