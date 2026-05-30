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

    /**
     * @param array<int, string> $couriers
     * @param array<int, string> $functions
     * @return array<int, array<string, mixed>>
     */
    public function courierPoints(
        array $couriers,
        ?string $searchQuery = null,
        array $functions = [],
        int $limit = 20,
        int $page = 1,
        ?string $id = null,
    ): array {
        $data = [
            'couriers' => array_values($couriers),
        ];

        if ($id !== null && trim($id) !== '') {
            $data['id'] = trim($id);
        }

        if ($searchQuery !== null && trim($searchQuery) !== '') {
            $data['searchquery'] = trim($searchQuery);
        }

        if ($functions !== []) {
            $data['functions'] = array_values($functions);
        }

        if ($limit > 0) {
            $data['limit'] = $limit;
        }

        if ($page > 0) {
            $data['page'] = $page;
        }

        $payload = $this->request('get_courier_point', $data);
        $response = $payload['response'] ?? null;

        if (! is_array($response)) {
            throw new RuntimeException('Polkurier did not return courier point data.');
        }

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function courierPickupTimes(
        string $courier,
        string $senderPostcode,
        string $shipmentType = 'box',
        ?string $recipientPostcode = null,
    ): array {
        $data = [
            'courier' => $courier,
            'shipfrom' => $senderPostcode,
            'shipmenttype' => $shipmentType,
        ];

        if ($recipientPostcode !== null && trim($recipientPostcode) !== '') {
            $data['shipto'] = trim($recipientPostcode);
        }

        $payload = $this->request('get_courier_pickup_time', $data);

        $response = $payload['response'] ?? null;

        if (! is_array($response)) {
            throw new RuntimeException('Polkurier did not return pickup time data.');
        }

        return $response;
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

    public function protocolPdf(array $orderNumbers): string
    {
        $payload = $this->request('get_protocol', [
            'orderno' => array_values($orderNumbers),
        ]);

        $file = $payload['response']['file'] ?? null;

        if (! is_string($file) || $file === '') {
            throw new RuntimeException('Polkurier did not return a protocol file.');
        }

        $decoded = base64_decode($file, true);

        if ($decoded === false) {
            throw new RuntimeException('Polkurier returned an invalid protocol file.');
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableCarriers(bool $additionalData = false, ?string $returnCarrier = null): array
    {
        $data = [];

        if ($additionalData) {
            $data['additional_data'] = true;
        }

        if ($returnCarrier !== null && trim($returnCarrier) !== '') {
            $data['returncarrier'] = trim($returnCarrier);
        }

        $payload = $this->request('available_carriers', $data);

        $response = $payload['response'] ?? null;

        if (! is_array($response)) {
            throw new RuntimeException('Polkurier did not return available carrier data.');
        }

        return $response;
    }
}
