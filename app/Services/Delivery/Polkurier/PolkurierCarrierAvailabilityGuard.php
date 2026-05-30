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
     *     missing_required_fields: array<int, string>,
     *     additional_fields: array<int, array<string, mixed>>
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

        $additionalFields = $this->additionalFieldDefinitionsForCarrier($carrier);
        $requiredFields = $this->requiredAdditionalFieldNames($additionalFields);

        if ($requiredFields !== []) {
            return $this->result(
                enabled: true,
                blocking: false,
                severity: 'warning',
                courierCode: $courierCode,
                message: 'Polkurier carrier '.$courierCode.' requires additional fields. Fill them in before creating the shipment.',
                missingRequiredFields: $requiredFields,
                additionalFields: $additionalFields,
            );
        }

        return $this->result(
            enabled: true,
            blocking: false,
            severity: 'success',
            courierCode: $courierCode,
            message: 'Polkurier carrier '.$courierCode.' is available.',
            additionalFields: $additionalFields,
        );
    }

    /**
     * @param array<string, mixed> $additionalFields
     */
    public function ensureCanCreateShipment(Order $order, array $additionalFields = []): void
    {
        $check = $this->check($order);

        if ($check['blocking']) {
            throw new PolkurierCarrierAvailabilityException((string) $check['message']);
        }

        if (! $this->blockRequiredAdditionalFields()) {
            return;
        }

        $missingRequiredFields = $this->missingRequiredAdditionalFields(
            $check['additional_fields'],
            $additionalFields,
        );

        if ($missingRequiredFields === []) {
            return;
        }

        throw new PolkurierCarrierAvailabilityException(
            'Polkurier carrier '.$check['courier_code'].' requires additional fields that are missing: '
            .implode(', ', $missingRequiredFields).'.'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function additionalFieldDefinitions(Order $order): array
    {
        $courierCode = $this->courierCode($order);

        if ($courierCode === null || ! $this->availableCarriersService->hasCachedData()) {
            return [];
        }

        $carrier = $this->findCarrier($courierCode);

        if ($carrier === null) {
            return [];
        }

        return $this->additionalFieldDefinitionsForCarrier($carrier);
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
     * @return array<int, array<string, mixed>>
     */
    private function additionalFieldDefinitionsForCarrier(array $carrier): array
    {
        $fields = data_get($carrier, 'additional_data.additional_fields');

        if (! is_array($fields)) {
            $fields = data_get($carrier, 'additional_data.pickup.additional_fields', []);
        }

        if (! is_array($fields)) {
            return [];
        }

        $definitions = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $type = strtoupper((string) ($field['type'] ?? 'TEXT'));

            if (! in_array($type, ['TEXT', 'SELECT'], true)) {
                $type = 'TEXT';
            }

            $options = [];

            if (isset($field['options']) && is_array($field['options'])) {
                foreach ($field['options'] as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $value = $option['value'] ?? null;

                    if (! is_scalar($value) || trim((string) $value) === '') {
                        continue;
                    }

                    $options[] = [
                        'value' => trim((string) $value),
                        'label' => is_scalar($option['label'] ?? null)
                            ? trim((string) $option['label'])
                            : trim((string) $value),
                    ];
                }
            }

            $definitions[] = [
                'name' => trim($name),
                'label' => is_scalar($field['label'] ?? null) && trim((string) $field['label']) !== ''
                    ? trim((string) $field['label'])
                    : trim($name),
                'description' => is_scalar($field['description'] ?? null)
                    ? trim((string) $field['description'])
                    : '',
                'type' => $type,
                'required' => ($field['required'] ?? false) === true,
                'options' => $options,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<int, array<string, mixed>> $additionalFieldDefinitions
     * @return array<int, string>
     */
    private function requiredAdditionalFieldNames(array $additionalFieldDefinitions): array
    {
        $required = [];

        foreach ($additionalFieldDefinitions as $field) {
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
     * @param array<int, array<string, mixed>> $additionalFieldDefinitions
     * @param array<string, mixed> $additionalFields
     * @return array<int, string>
     */
    private function missingRequiredAdditionalFields(array $additionalFieldDefinitions, array $additionalFields): array
    {
        $missing = [];

        foreach ($this->requiredAdditionalFieldNames($additionalFieldDefinitions) as $fieldName) {
            $value = $additionalFields[$fieldName] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $fieldName;
            }
        }

        return $missing;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     blocking: bool,
     *     severity: string,
     *     courier_code: string|null,
     *     message: string|null,
     *     missing_required_fields: array<int, string>,
     *     additional_fields: array<int, array<string, mixed>>
     * }
     */
    private function result(
        bool $enabled,
        bool $blocking,
        string $severity,
        ?string $courierCode,
        ?string $message,
        array $missingRequiredFields = [],
        array $additionalFields = [],
    ): array {
        return [
            'enabled' => $enabled,
            'blocking' => $blocking,
            'severity' => $severity,
            'courier_code' => $courierCode,
            'message' => $message,
            'missing_required_fields' => $missingRequiredFields,
            'additional_fields' => $additionalFields,
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
