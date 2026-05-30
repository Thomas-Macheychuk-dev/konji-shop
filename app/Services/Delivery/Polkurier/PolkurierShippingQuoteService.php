<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Data\Delivery\ShippingQuoteResult;
use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PolkurierShippingQuoteService
{
    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    /**
     * @param array<string, mixed> $shippingAddress
     * @param array<int, array<string, int|float|string>> $packs
     */
    public function quote(
        DeliveryProvider $provider,
        DeliveryCarrier $carrier,
        string $service,
        array $shippingAddress,
        string $currency = 'PLN',
        array $packs = [],
    ): ShippingQuoteResult {
        if ($provider !== DeliveryProvider::POLKURIER) {
            throw new RuntimeException('Unsupported delivery provider for shipping quote.');
        }

        if ($carrier === DeliveryCarrier::LOCAL_PICKUP || $service === 'local_pickup') {
            return new ShippingQuoteResult(
                amount: 0,
                currency: $currency,
                provider: $provider->value,
                carrier: DeliveryCarrier::LOCAL_PICKUP->value,
                service: 'local_pickup',
                providerServiceCode: null,
                providerServiceName: 'Local pickup',
                payload: [
                    'source' => 'local_pickup',
                ],
            );
        }

        if (! (bool) config('delivery.providers.polkurier.valuation.enabled', false)) {
            return $this->fallbackQuote(
                provider: $provider,
                carrier: $carrier,
                service: $service,
                currency: $currency,
                reason: 'valuation_disabled',
            );
        }

        $courierCode = $this->courierCode($carrier, $service);
        $valuationRequest = null;

        try {
            $valuationRequest = $this->valuationRequest($courierCode, $shippingAddress, $packs);

            $valuations = $this->client->orderValuationV2($valuationRequest);
            $selected = $this->selectValuation($valuations, $courierCode);

            $grossPrice = (float) ($selected['grossprice'] ?? 0);

            if ($grossPrice <= 0) {
                throw new RuntimeException('Polkurier returned an invalid gross shipping price.');
            }

            return new ShippingQuoteResult(
                amount: (int) round($grossPrice * 100),
                currency: $currency,
                provider: $provider->value,
                carrier: $carrier->value,
                service: $service,
                providerServiceCode: (string) ($selected['servicecode'] ?? $courierCode),
                providerServiceName: (string) ($selected['servicename'] ?? $selected['serviceName'] ?? $courierCode),
                payload: [
                    'source' => 'polkurier_order_valuation_v2',
                    'request' => $valuationRequest,
                    'response' => $selected,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Polkurier live shipping valuation failed; using fallback price.', [
                'carrier' => $carrier->value,
                'service' => $service,
                'courier_code' => $courierCode,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'request' => $valuationRequest,
            ]);

            return $this->fallbackQuote(
                provider: $provider,
                carrier: $carrier,
                service: $service,
                currency: $currency,
                reason: 'live_valuation_failed',
                context: [
                    'courier_code' => $courierCode,
                    'error' => [
                        'message' => $exception->getMessage(),
                        'class' => $exception::class,
                    ],
                    'request' => $valuationRequest,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function fallbackQuote(
        DeliveryProvider $provider,
        DeliveryCarrier $carrier,
        string $service,
        string $currency,
        string $reason,
        array $context = [],
    ): ShippingQuoteResult {
        $amount = (int) config(
            sprintf('delivery.providers.polkurier.valuation.fallback_prices.%s.%s', $carrier->value, $service),
            0,
        );

        return new ShippingQuoteResult(
            amount: $amount,
            currency: $currency,
            provider: $provider->value,
            carrier: $carrier->value,
            service: $service,
            providerServiceCode: $this->courierCode($carrier, $service),
            providerServiceName: null,
            payload: array_merge([
                'source' => 'fallback',
                'reason' => $reason,
            ], $context),
        );
    }

    /**
     * @param array<string, mixed> $shippingAddress
     * @param array<int, array<string, int|float|string>> $packs
     * @return array<string, mixed>
     */
    private function valuationRequest(string $courierCode, array $shippingAddress, array $packs = []): array
    {
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
            'packs' => $this->normalizePacks($packs),
            'sender' => [
                'postcode' => (string) ($sender['postcode'] ?? ''),
                'country' => (string) ($sender['country'] ?? 'PL'),
            ],
            'recipient' => [
                'postcode' => (string) ($shippingAddress['postcode'] ?? ''),
                'country' => (string) ($shippingAddress['country_code'] ?? 'PL'),
            ],
        ];
    }

    /**
     * @param array<int, array<string, int|float|string>> $packs
     * @return array<int, array<string, int|float|string>>
     */
    private function normalizePacks(array $packs): array
    {
        if ($packs === []) {
            return [
                $this->defaultPack(),
            ];
        }

        return array_values(array_map(
            fn (array $pack): array => [
                'length' => max(1, (int) ($pack['length'] ?? 30)),
                'width' => max(1, (int) ($pack['width'] ?? 20)),
                'height' => max(1, (int) ($pack['height'] ?? 10)),
                'weight' => max(0.001, (float) ($pack['weight'] ?? 1)),
                'amount' => max(1, (int) ($pack['amount'] ?? 1)),
                'type' => (string) ($pack['type'] ?? 'ST'),
            ],
            $packs,
        ));
    }

    /**
     * @return array<string, int|float|string>
     */
    private function defaultPack(): array
    {
        $pack = config('delivery.providers.polkurier.default_pack');

        if (! is_array($pack)) {
            throw new RuntimeException('Polkurier default pack configuration is missing.');
        }

        return [
            'length' => (int) ($pack['length'] ?? 30),
            'width' => (int) ($pack['width'] ?? 20),
            'height' => (int) ($pack['height'] ?? 10),
            'weight' => (float) ($pack['weight'] ?? 1),
            'amount' => (int) ($pack['amount'] ?? 1),
            'type' => (string) ($pack['type'] ?? 'ST'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $valuations
     * @return array<string, mixed>
     */
    private function selectValuation(array $valuations, string $courierCode): array
    {
        foreach ($valuations as $valuation) {
            if (
                is_array($valuation)
                && (string) ($valuation['servicecode'] ?? '') === $courierCode
                && ($valuation['available'] ?? true) !== false
                && ($valuation['shipment'] ?? true) !== false
            ) {
                return $valuation;
            }
        }

        foreach ($valuations as $valuation) {
            if (
                is_array($valuation)
                && ($valuation['available'] ?? true) !== false
                && ($valuation['shipment'] ?? true) !== false
            ) {
                return $valuation;
            }
        }

        throw new RuntimeException('Polkurier did not return an available valuation.');
    }

    private function courierCode(DeliveryCarrier $carrier, string $service): string
    {
        if ($carrier === DeliveryCarrier::INPOST && $service === 'parcel_locker') {
            return 'INPOST_PACZKOMAT';
        }

        return match ($carrier) {
            DeliveryCarrier::UPS => 'UPS',
            DeliveryCarrier::DPD => 'DPD',
            DeliveryCarrier::INPOST => 'INPOST',
            DeliveryCarrier::LOCAL_PICKUP => 'LOCAL_PICKUP',
        };
    }
}
