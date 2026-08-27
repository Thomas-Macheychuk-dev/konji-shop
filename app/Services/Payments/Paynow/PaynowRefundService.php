<?php

declare(strict_types=1);

namespace App\Services\Payments\Paynow;

use App\Data\Payments\PaynowRefundResult;
use App\Enums\PaymentRefundStatus;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

final class PaynowRefundService
{
    public function create(Payment $payment, PaymentRefund $refund): PaynowRefundResult
    {
        if ($payment->provider !== 'paynow' || trim((string) $payment->provider_reference) === '') {
            throw new RuntimeException('Zwrot wymaga potwierdzonej płatności Paynow.');
        }

        if ($refund->payment_id !== $payment->id) {
            throw new RuntimeException('Zwrot nie należy do wskazanej płatności.');
        }

        if ((int) $refund->amount <= 0 || (int) $refund->amount > (int) $payment->amount) {
            throw new RuntimeException('Kwota zwrotu Paynow jest nieprawidłowa.');
        }

        $body = [
            'amount' => (int) $refund->amount,
        ];

        if (trim((string) $refund->reason) !== '') {
            $body['reason'] = $refund->reason;
        }

        try {
            $rawBody = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Nie udało się przygotować żądania zwrotu Paynow.', 0, $exception);
        }

        [$baseUrl, $apiKey, $signatureKey, $connectTimeout, $timeout] = $this->configuration();

        try {
            $signature = PaynowSignature::forRequest(
                apiKey: $apiKey,
                signatureKey: $signatureKey,
                idempotencyKey: $refund->idempotency_key,
                body: $rawBody,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Nie udało się podpisać żądania zwrotu Paynow.', 0, $exception);
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $apiKey,
                'Signature' => $signature,
                'Idempotency-Key' => $refund->idempotency_key,
            ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->withBody($rawBody, 'application/json')
                ->post("{$baseUrl}/v3/payments/{$payment->provider_reference}/refunds");
        } catch (ConnectionException $exception) {
            Log::warning('Paynow refund connection failure', [
                'order_id' => $refund->order_id,
                'payment_id' => $payment->id,
                'refund_id' => $refund->id,
            ]);

            throw new RuntimeException(
                'Paynow jest tymczasowo niedostępny. Zwrot pozostaje oczekujący i może zostać bezpiecznie ponowiony.',
                0,
                $exception,
            );
        }

        return $this->resultFromResponse($response, $refund, expectedStatus: 201);
    }

    public function status(PaymentRefund $refund): PaynowRefundResult
    {
        $providerRefundId = trim((string) $refund->provider_refund_id);

        if ($providerRefundId === '') {
            throw new RuntimeException('Zwrot Paynow nie ma jeszcze identyfikatora operatora.');
        }

        [$baseUrl, $apiKey, $signatureKey, $connectTimeout, $timeout] = $this->configuration();

        try {
            $signature = PaynowSignature::forRequest(
                apiKey: $apiKey,
                signatureKey: $signatureKey,
                idempotencyKey: $refund->idempotency_key,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Nie udało się podpisać zapytania o status zwrotu Paynow.', 0, $exception);
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Api-Key' => $apiKey,
                'Signature' => $signature,
                'Idempotency-Key' => $refund->idempotency_key,
            ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get("{$baseUrl}/v3/refunds/{$providerRefundId}/status");
        } catch (ConnectionException $exception) {
            Log::warning('Paynow refund status connection failure', [
                'order_id' => $refund->order_id,
                'payment_id' => $refund->payment_id,
                'refund_id' => $refund->id,
                'provider_refund_id' => $providerRefundId,
            ]);

            throw new RuntimeException('Nie udało się pobrać statusu zwrotu Paynow.', 0, $exception);
        }

        return $this->resultFromResponse($response, $refund, expectedStatus: 200);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int, 4: int}
     */
    private function configuration(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('payments.providers.paynow');

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $signatureKey = trim((string) ($config['signature_key'] ?? ''));

        if ($apiKey === '' || $signatureKey === '') {
            throw new RuntimeException('Paynow nie jest skonfigurowany do wykonywania zwrotów.');
        }

        $baseUrl = (bool) ($config['sandbox'] ?? true)
            ? 'https://api.sandbox.paynow.pl'
            : 'https://api.paynow.pl';

        return [
            $baseUrl,
            $apiKey,
            $signatureKey,
            max(1, (int) ($config['connect_timeout'] ?? 5)),
            max(1, (int) ($config['timeout'] ?? 15)),
        ];
    }

    private function resultFromResponse(
        Response $response,
        PaymentRefund $refund,
        int $expectedStatus,
    ): PaynowRefundResult {
        $data = $response->json();

        if ($response->status() !== $expectedStatus || ! is_array($data)) {
            Log::warning('Paynow refund request rejected', [
                'order_id' => $refund->order_id,
                'payment_id' => $refund->payment_id,
                'refund_id' => $refund->id,
                'http_status' => $response->status(),
                'error_types' => $this->errorTypes($data),
            ]);

            throw new RuntimeException('Paynow odrzucił operację zwrotu. Szczegóły zapisano w logach.');
        }

        $providerRefundId = trim((string) ($data['refundId'] ?? ''));
        $status = strtoupper(trim((string) ($data['status'] ?? '')));

        if ($providerRefundId === '' || ($refund->provider_refund_id !== null && $refund->provider_refund_id !== $providerRefundId)) {
            throw new RuntimeException('Paynow zwrócił nieprawidłowy identyfikator zwrotu.');
        }

        try {
            $refundStatus = PaymentRefundStatus::fromPaynow($status);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('Paynow zwrócił nieobsługiwany status zwrotu.', 0, $exception);
        }

        return new PaynowRefundResult(
            providerRefundId: $providerRefundId,
            status: $refundStatus,
            payload: $data,
            failureReason: isset($data['failureReason']) && is_string($data['failureReason'])
                ? $data['failureReason']
                : null,
        );
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
}
