<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\DeliveryService;
use App\Exceptions\Delivery\PolkurierCarrierAvailabilityException;
use App\Models\Order;

final class PolkurierCarrierAvailabilityGuard
{
    public function __construct(
        private readonly PolkurierAvailableCarriersService $availableCarriersService,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     blocking: bool,
     *     severity: string,
     *     courier_code: string|null,
     *     message: string|null,
     *     missing_required_fields: array<int, string>
     * }
     */
    public function check(Order $order): array
    {
        if (! $this->enabled()) {
            return $this->result(
                enabled: false,
                blocking: false,
                severity: 'info',
                courierCode: null,
                message: null,
            );
        }

        if ($order->delivery_provider !== DeliveryProvider::POLKURIER) {
            return $this->result(
                enabled: true,
                blocking: false,
                severity: 'info',
                courierCode: null,
                message: null,
            );
        }

        if ((string) $order->delivery_service === DeliveryService::LOCAL_PICKUP->value) {
            return $this->result(
                enabled: true,
                blocking: false,
                severity: 'info',
                courierCode: null,
                message: null,
            );
        }

        $courierCode = $this->courierCode($order);

        if ($courierCode === null) {
            return $this->result(
                enabled: true,
                blocking: true,
                severity: 'error',
                courierCode: null,
                message: 'The selected delivery carrier cannot be mapped to a Polkurier courier code.',
            );
        }

        if (! $this->availableCarriersService->hasCachedData()) {
            return $this->result(
                enabled: true,
                blocking: $this->failWhenCacheEmpty(),
                severity: $this->failWhenCacheEmpty() ? 'error' : 'warning',
                courierCode: $courierCode,
                message: $this->failWhenCacheEmpty()
                    ? 'Polkurier available carrier data has not been refreshed yet.'
                    : 'Polkurier available carrier data has not been refreshed yet. Shipment creation is allowed, but refresh carrier data in diagnostics before production use.',
            );
        }

        $carrier = $this->findCarrier($courierCode);

        if ($carrier === null) {
            return $this->result(
                enabled: true,
                blocking: true,
                severity: 'error',
                courierCode: $courierCode,
                message: 'Polkurier did not return the selected courier code '.$courierCode.' in available carriers.',
            );
        }

        $shipmentType = (string) config('delivery.providers.polkurier.default_pack.shipmenttype', 'box');

        if ($this->shipmentTypeExplicitlyUnavailable($carrier, $shipmentType)) {
            return $this->result(
                enabled: true,
                blocking: true,
                severity: 'error',
                courierCode: $courierCode,
                message: 'Polkurier carrier '.$courierCode.' does not currently support shipment type '.$shipmentType.'.',
            );
        }

        $requiredFields = $this->requiredAdditionalFields($carrier);

        if ($requiredFields !== [] && $this->blockRequiredAdditionalFields()) {
            return $this->result(
                enabled: true,
                blocking: true,
                severity: 'error',
                courierCode: $courierCode,
                message: 'Polkurier carrier '.$courierCode.' requires additional fields that Konji Shop does not collect yet: '.implode(', ', $requiredFields).'.',
                missingRequiredFields: $requiredFields,
            );
        }

        if ($requiredFields !== []) {
            return $this->result(
                enabled: true,
                blocking: false,
                severity: 'warning',
                courierCode: $courierCode,
                message: 'Polkurier carrier '.$courierCode.' reports required additional fields: '.implode(', ', $requiredFields).'.',
                missingRequiredFields: $requiredFields,
            );
        }

        return $this->result(
            enabled: true,
            blocking: false,
            severity: 'success',
            courierCode: $courierCode,
            message: 'Polkurier carrier '.$courierCode.' is available.',
        );
    }

    public function ensureCanCreateShipment(Order $order): void
    {
        $check = $this->check($order);

        if ($check['blocking']) {
            throw new PolkurierCarrierAvailabilityException((string) $check['message']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cachedCarriers(): array
    {
        return $this->availableCarriersService->cached();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCarrier(string $courierCode): ?array
    {
        foreach ($this->cachedCarriers() as $carrier) {
            if (! is_array($carrier)) {
                continue;
            }

            if (($carrier['servicecode'] ?? null) === $courierCode) {
                return $carrier;
            }
        }

        return null;
    }

    private function courierCode(Order $order): ?string
    {
        $carrier = $order->delivery_carrier;

        if (
            (string) $order->delivery_service === DeliveryService::PARCEL_LOCKER->value
            && $carrier === DeliveryCarrier::INPOST
        ) {
            return 'INPOST_PACZKOMAT';
        }

        return match ($carrier) {
            DeliveryCarrier::UPS => 'UPS',
            DeliveryCarrier::DPD => 'DPD',
            DeliveryCarrier::INPOST => 'INPOST',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $carrier
     */
    private function shipmentTypeExplicitlyUnavailable(array $carrier, string $shipmentType): bool
    {
        $shipmentTypeData = data_get($carrier, 'additional_data.shipmenttype.'.$shipmentType);

        return is_array($shipmentTypeData)
            && array_key_exists('available', $shipmentTypeData)
            && $shipmentTypeData['available'] !== true;
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
                $required[] = trim($name);
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * @return array{
     *     enabled: bool,
     *     blocking: bool,
     *     severity: string,
     *     courier_code: string|null,
     *     message: string|null,
     *     missing_required_fields: array<int, string>
     * }
     */
    private function result(
        bool $enabled,
        bool $blocking,
        string $severity,
        ?string $courierCode,
        ?string $message,
        array $missingRequiredFields = [],
    ): array {
        return [
            'enabled' => $enabled,
            'blocking' => $blocking,
            'severity' => $severity,
            'courier_code' => $courierCode,
            'message' => $message,
            'missing_required_fields' => $missingRequiredFields,
        ];
    }

    private function enabled(): bool
    {
        return (bool) config('delivery.providers.polkurier.available_carriers.guards.enabled', true);
    }

    private function failWhenCacheEmpty(): bool
    {
        return (bool) config('delivery.providers.polkurier.available_carriers.guards.fail_when_cache_empty', false);
    }

    private function blockRequiredAdditionalFields(): bool
    {
        return (bool) config('delivery.providers.polkurier.available_carriers.guards.block_required_additional_fields', true);
    }
}
