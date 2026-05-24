<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PolkurierApiClient
{
    public function request(string $method, array $data = []): array
    {
        $response = $this->http()->post('/', [
            'authorization' => [
                'login' => config('delivery.providers.polkurier.login'),
                'token' => config('delivery.providers.polkurier.token'),
            ],
            'apimethod' => $method,
            'data' => $data,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Polkurier HTTP request failed.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Polkurier returned invalid JSON.');
        }

        if (($payload['status'] ?? null) !== 'success') {
            throw new RuntimeException((string) ($payload['response'] ?? 'Polkurier request failed.'));
        }

        return $payload;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('delivery.providers.polkurier.base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    public function inpostPointsMachines(): array
    {
        $payload = $this->request('inpost_points_machines');

        return $payload['response'] ?? [];
    }

    public function labelPdf(array $orderNumbers): string
    {
        $payload = $this->request('get_label', [
            'orderno' => array_values($orderNumbers),
        ]);

        $file = $payload['response']['file'] ?? null;

        if (! is_string($file) || $file === '') {
            throw new RuntimeException('Polkurier did not return a label file.');
        }

        $decoded = base64_decode($file, true);

        if ($decoded === false) {
            throw new RuntimeException('Polkurier returned an invalid label file.');
        }

        return $decoded;
    }

    public function shipmentStatus(string $orderNumber): array
    {
        $payload = $this->request('get_status', [
            'orderno' => $orderNumber,
        ]);

        $response = $payload['response'] ?? null;

        if (! is_array($response)) {
            throw new RuntimeException('Polkurier did not return shipment status data.');
        }

        return $response;
    }

    public function cancelOrder(string $orderNumber): array
    {
        $payload = $this->request('cancel_order', [
            'orderno' => $orderNumber,
        ]);

        $response = $payload['response'] ?? null;

        if (! is_array($response)) {
            throw new RuntimeException('Polkurier did not return cancellation data.');
        }

        if (($response['cancellation'] ?? false) !== true) {
            throw new RuntimeException('Polkurier did not confirm shipment cancellation.');
        }

        return $payload;
    }

    public function orderValuationV2(array $data): array
    {
        $payload = $this->request('order_valuation_v2', $data);

        $response = $payload['response'] ?? null;

        if (! is_array($response)) {
            throw new RuntimeException('Polkurier did not return valuation data.');
        }

        return $response;
    }
}
