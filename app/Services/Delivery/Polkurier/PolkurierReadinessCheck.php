<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use Illuminate\Support\Facades\Storage;
use Throwable;

final class PolkurierReadinessCheck
{
    public function __construct(
        private readonly PolkurierAvailableCarriersService $availableCarriersService,
    ) {}

    /**
     * @return array<int, array{
     *     category: string,
     *     name: string,
     *     status: string,
     *     required: bool,
     *     message: string
     * }>
     */
    public function items(): array
    {
        return [
            ...$this->apiItems(),
            ...$this->senderItems(),
            ...$this->defaultPackItems(),
            ...$this->labelStorageItems(),
            ...$this->protocolStorageItems(),
            ...$this->carrierAvailabilityItems(),
            ...$this->valuationItems(),
            ...$this->operationalItems(),
        ];
    }

    public function isReady(): bool
    {
        foreach ($this->items() as $item) {
            if ($item['required'] && $item['status'] !== 'OK') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function apiItems(): array
    {
        return [
            $this->item(
                category: 'API',
                name: 'Base URL',
                ok: filled(config('delivery.providers.polkurier.base_url')),
                required: true,
                message: filled(config('delivery.providers.polkurier.base_url'))
                    ? (string) config('delivery.providers.polkurier.base_url')
                    : 'POLKURIER_BASE_URL is missing.',
            ),
            $this->item(
                category: 'API',
                name: 'Login',
                ok: filled(config('delivery.providers.polkurier.login')),
                required: true,
                message: filled(config('delivery.providers.polkurier.login'))
                    ? 'Configured.'
                    : 'POLKURIER_LOGIN is missing.',
            ),
            $this->item(
                category: 'API',
                name: 'Token',
                ok: filled(config('delivery.providers.polkurier.token')),
                required: true,
                message: filled(config('delivery.providers.polkurier.token'))
                    ? 'Configured. Token value is hidden.'
                    : 'POLKURIER_TOKEN is missing.',
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function senderItems(): array
    {
        $sender = config('delivery.providers.polkurier.sender');

        if (! is_array($sender)) {
            return [
                $this->item(
                    category: 'Sender',
                    name: 'Sender config',
                    ok: false,
                    required: true,
                    message: 'delivery.providers.polkurier.sender is missing.',
                ),
            ];
        }

        $requiredFields = [
            'company',
            'person',
            'street',
            'housenumber',
            'postcode',
            'city',
            'email',
            'phone',
            'country',
        ];

        return array_map(
            fn (string $field): array => $this->item(
                category: 'Sender',
                name: $field,
                ok: filled($sender[$field] ?? null),
                required: true,
                message: filled($sender[$field] ?? null)
                    ? 'Configured.'
                    : sprintf('POLKURIER_SENDER_%s is missing.', strtoupper($field)),
            ),
            $requiredFields,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultPackItems(): array
    {
        $pack = config('delivery.providers.polkurier.default_pack');

        if (! is_array($pack)) {
            return [
                $this->item(
                    category: 'Default pack',
                    name: 'Default pack config',
                    ok: false,
                    required: true,
                    message: 'delivery.providers.polkurier.default_pack is missing.',
                ),
            ];
        }

        return [
            $this->item('Default pack', 'shipmenttype', filled($pack['shipmenttype'] ?? null), true, 'Default shipment type.'),
            $this->item('Default pack', 'length', (int) ($pack['length'] ?? 0) > 0, true, 'Must be greater than 0 cm.'),
            $this->item('Default pack', 'width', (int) ($pack['width'] ?? 0) > 0, true, 'Must be greater than 0 cm.'),
            $this->item('Default pack', 'height', (int) ($pack['height'] ?? 0) > 0, true, 'Must be greater than 0 cm.'),
            $this->item('Default pack', 'weight', (float) ($pack['weight'] ?? 0) > 0, true, 'Must be greater than 0 kg.'),
            $this->item('Default pack', 'amount', (int) ($pack['amount'] ?? 0) > 0, true, 'Must be greater than 0.'),
            $this->item('Default pack', 'type', filled($pack['type'] ?? null), true, 'Default Polkurier pack type.'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function labelStorageItems(): array
    {
        $disk = (string) config('delivery.providers.polkurier.labels.disk', '');
        $path = (string) config('delivery.providers.polkurier.labels.path', '');

        return [
            $this->item(
                category: 'Labels',
                name: 'Label disk',
                ok: filled($disk) && $this->diskExists($disk),
                required: true,
                message: filled($disk)
                    ? sprintf('Configured disk: %s', $disk)
                    : 'POLKURIER_LABEL_DISK is missing.',
            ),
            $this->item(
                category: 'Labels',
                name: 'Label path',
                ok: filled($path),
                required: true,
                message: filled($path)
                    ? $path
                    : 'POLKURIER_LABEL_PATH is missing.',
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function protocolStorageItems(): array
    {
        $disk = (string) config('delivery.providers.polkurier.protocols.disk', '');
        $path = (string) config('delivery.providers.polkurier.protocols.path', '');

        return [
            $this->item(
                category: 'Protocols',
                name: 'Protocol disk',
                ok: filled($disk) && $this->diskExists($disk),
                required: true,
                message: filled($disk)
                    ? sprintf('Configured disk: %s', $disk)
                    : 'POLKURIER_PROTOCOL_DISK is missing.',
            ),
            $this->item(
                category: 'Protocols',
                name: 'Protocol path',
                ok: filled($path),
                required: true,
                message: filled($path)
                    ? $path
                    : 'POLKURIER_PROTOCOL_PATH is missing.',
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function carrierAvailabilityItems(): array
    {
        $items = [
            $this->item(
                category: 'Carrier availability',
                name: 'Available carriers cache',
                ok: $this->availableCarriersService->hasCachedData(),
                required: false,
                message: $this->availableCarriersService->hasCachedData()
                    ? sprintf('Cached carrier records: %d.', count($this->availableCarriersService->cached()))
                    : 'Available carriers have not been refreshed yet. Refresh them in Polkurier diagnostics before production use.',
            ),
        ];

        foreach ($this->availableCarriersService->configuredCarrierSummaries() as $summary) {
            $items[] = $this->item(
                category: 'Carrier availability',
                name: $summary['label'].' / '.$summary['code'],
                ok: (bool) $summary['available'],
                required: false,
                message: $summary['available']
                    ? $this->carrierSummaryMessage($summary)
                    : 'This configured carrier was not returned by Polkurier available_carriers.',
            );

            if ($summary['required_additional_fields'] !== []) {
                $items[] = $this->item(
                    category: 'Carrier availability',
                    name: $summary['label'].' required fields',
                    ok: false,
                    required: false,
                    message: 'Required additional fields: '.implode(', ', $summary['required_additional_fields']).'. These should be rendered on the admin shipment form.',
                );
            }
        }

        return $items;
    }

    /**
     * @param array{
     *     key: string,
     *     label: string,
     *     code: string,
     *     available: bool,
     *     name: string|null,
     *     foreign_shipments: bool|null,
     *     shipment_types: array<int, string>,
     *     courier_services: array<int, string>,
     *     required_additional_fields: array<int, string>
     * } $summary
     */
    private function carrierSummaryMessage(array $summary): string
    {
        $parts = [];

        if ($summary['name']) {
            $parts[] = $summary['name'];
        }

        if ($summary['shipment_types'] !== []) {
            $parts[] = 'shipment types: '.implode(', ', $summary['shipment_types']);
        }

        if ($summary['courier_services'] !== []) {
            $parts[] = 'services: '.implode(', ', $summary['courier_services']);
        }

        if ($summary['foreign_shipments'] !== null) {
            $parts[] = 'foreign shipments: '.($summary['foreign_shipments'] ? 'yes' : 'no');
        }

        return $parts !== []
            ? implode('; ', $parts).'.'
            : 'Returned by Polkurier available_carriers.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function valuationItems(): array
    {
        $enabled = (bool) config('delivery.providers.polkurier.valuation.enabled', false);

        return [
            $this->item(
                category: 'Valuation',
                name: 'Live valuation',
                ok: $enabled,
                required: false,
                message: $enabled
                    ? 'Live Polkurier valuation is enabled.'
                    : 'Live valuation is disabled. Fallback prices will be used.',
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function operationalItems(): array
    {
        return [
            $this->item(
                category: 'Operations',
                name: 'Status sync scheduler',
                ok: true,
                required: false,
                message: 'Ensure the Laravel scheduler is running in production so polkurier:sync-shipments executes regularly.',
            ),
            $this->item(
                category: 'Operations',
                name: 'Queue worker',
                ok: true,
                required: false,
                message: 'Ensure a queue worker is running in production if queued tracking emails are not using the sync queue.',
            ),
            $this->item(
                category: 'Operations',
                name: 'Mail delivery',
                ok: true,
                required: false,
                message: 'Ensure production mail configuration is working so customers receive shipment tracking emails.',
            ),
        ];
    }

    /**
     * @return array{
     *     category: string,
     *     name: string,
     *     status: string,
     *     required: bool,
     *     message: string
     * }
     */
    private function item(
        string $category,
        string $name,
        bool $ok,
        bool $required,
        string $message,
    ): array {
        return [
            'category' => $category,
            'name' => $name,
            'status' => $ok ? 'OK' : ($required ? 'MISSING' : 'WARNING'),
            'required' => $required,
            'message' => $message,
        ];
    }

    private function diskExists(string $disk): bool
    {
        try {
            Storage::disk($disk);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
