<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use Illuminate\Support\Facades\Cache;

final class PolkurierAvailableCarriersService
{
    public const CACHE_KEY = 'polkurier.available_carriers.v1';

    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cached(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refresh(): array
    {
        $carriers = $this->client->availableCarriers(additionalData: true);

        Cache::put(self::CACHE_KEY, $carriers, $this->cacheTtl());

        return $carriers;
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     code: string,
     *     available: bool,
     *     name: string|null,
     *     foreign_shipments: bool|null,
     *     shipment_types: array<int, string>,
     *     courier_services: array<int, string>,
     *     required_additional_fields: array<int, string>
     * }>
     */
    public function configuredCarrierSummaries(): array
    {
        $configuredCarriers = config('delivery.providers.polkurier.configured_carriers', []);

        if (! is_array($configuredCarriers)) {
            return [];
        }

        $availableByCode = [];

        foreach ($this->cached() as $carrier) {
            if (! is_array($carrier)) {
                continue;
            }

            $code = $carrier['servicecode'] ?? null;

            if (is_string($code) && trim($code) !== '') {
                $availableByCode[$code] = $carrier;
            }
        }

        $summaries = [];

        foreach ($configuredCarriers as $key => $configuredCarrier) {
            if (! is_array($configuredCarrier)) {
                continue;
            }

            $code = (string) ($configuredCarrier['code'] ?? '');
            $carrier = $availableByCode[$code] ?? null;

            $summaries[] = [
                'key' => (string) $key,
                'label' => (string) ($configuredCarrier['label'] ?? $key),
                'code' => $code,
                'available' => is_array($carrier),
                'name' => is_array($carrier) && isset($carrier['name']) ? (string) $carrier['name'] : null,
                'foreign_shipments' => is_array($carrier) && array_key_exists('foreign_shipments', $carrier)
                    ? (bool) $carrier['foreign_shipments']
                    : null,
                'shipment_types' => is_array($carrier) ? $this->availableKeys(data_get($carrier, 'additional_data.shipmenttype', [])) : [],
                'courier_services' => is_array($carrier) ? $this->availableKeys(data_get($carrier, 'additional_data.courierservice', [])) : [],
                'required_additional_fields' => is_array($carrier) ? $this->requiredAdditionalFields($carrier) : [],
            ];
        }

        return $summaries;
    }

    private function cacheTtl(): int
    {
        return max(60, (int) config('delivery.providers.polkurier.available_carriers.cache_ttl', 43200));
    }

    /**
     * @return array<int, string>
     */
    private function availableKeys(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $available = [];

        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['available'] ?? false) === true) {
                $available[] = (string) $key;
            }
        }

        return $available;
    }

    /**
     * @param array<string, mixed> $carrier
     * @return array<int, string>
     */
    private function requiredAdditionalFields(array $carrier): array
    {
        $fields = data_get($carrier, 'additional_data.additional_fields');

        if (! is_array($fields)) {
            $fields = data_get($carrier, 'additional_data.pickup.additional_fields', []);
        }

        if (! is_array($fields)) {
            return [];
        }

        $required = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['required'] ?? false) !== true) {
                continue;
            }

            $name = $field['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                $required[] = $name;
            }
        }

        return $required;
    }
}
