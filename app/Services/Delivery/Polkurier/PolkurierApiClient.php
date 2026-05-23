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
}
