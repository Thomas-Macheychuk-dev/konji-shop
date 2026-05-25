<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestPolkurierValuationRequest;
use App\Services\Delivery\Polkurier\PolkurierApiClient;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

final class AdminPolkurierValuationTestController extends Controller
{
    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    public function __invoke(TestPolkurierValuationRequest $request): RedirectResponse
    {
        try {
            $valuationRequest = $this->valuationRequest(
                courierCode: (string) $request->input('courier_code'),
                recipientPostcode: (string) $request->input('recipient_postcode'),
                recipientCountry: (string) $request->input('recipient_country'),
            );

            $response = $this->client->orderValuationV2($valuationRequest);
        } catch (Throwable $exception) {
            return back()
                ->withInput()
                ->with('error', 'Polkurier valuation test failed: '.$exception->getMessage());
        }

        return back()
            ->withInput()
            ->with('success', 'Polkurier valuation test completed.')
            ->with('polkurier_valuation_request', $valuationRequest)
            ->with('polkurier_valuation_response', $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function valuationRequest(
        string $courierCode,
        string $recipientPostcode,
        string $recipientCountry,
    ): array {
        $sender = config('delivery.providers.polkurier.sender');

        if (! is_array($sender)) {
            throw new RuntimeException('Polkurier sender configuration is missing.');
        }

        $pack = config('delivery.providers.polkurier.default_pack');

        if (! is_array($pack)) {
            throw new RuntimeException('Polkurier default pack configuration is missing.');
        }

        return [
            'returnvaluations' => $courierCode,
            'shipmenttype' => (string) ($pack['shipmenttype'] ?? 'box'),
            'packs' => [
                [
                    'length' => (int) ($pack['length'] ?? 30),
                    'width' => (int) ($pack['width'] ?? 20),
                    'height' => (int) ($pack['height'] ?? 10),
                    'weight' => (float) ($pack['weight'] ?? 1),
                    'amount' => (int) ($pack['amount'] ?? 1),
                    'type' => (string) ($pack['type'] ?? 'ST'),
                ],
            ],
            'sender' => [
                'postcode' => (string) ($sender['postcode'] ?? ''),
                'country' => (string) ($sender['country'] ?? 'PL'),
            ],
            'recipient' => [
                'postcode' => $recipientPostcode,
                'country' => $recipientCountry,
            ],
        ];
    }
}
