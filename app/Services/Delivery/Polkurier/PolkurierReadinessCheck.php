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
                    ? 'Skonfigurowano.'
                    : 'POLKURIER_LOGIN is missing.',
            ),
            $this->item(
                category: 'API',
                name: 'Token',
                ok: filled(config('delivery.providers.polkurier.token')),
                required: true,
                message: filled(config('delivery.providers.polkurier.token'))
                    ? 'Skonfigurowano. Wartość tokenu jest ukryta.'
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
                    category: 'Nadawca',
                    name: 'Konfiguracja nadawcy',
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
                category: 'Nadawca',
                name: $field,
                ok: filled($sender[$field] ?? null),
                required: true,
                message: filled($sender[$field] ?? null)
                    ? 'Skonfigurowano.'
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
                    category: 'Domyślna paczka',
                    name: 'Konfiguracja domyślnej paczki',
                    ok: false,
                    required: true,
                    message: 'delivery.providers.polkurier.default_pack is missing.',
                ),
            ];
        }

        return [
            $this->item('Domyślna paczka', 'shipmenttype', filled($pack['shipmenttype'] ?? null), true, 'Domyślny typ przesyłki.'),
            $this->item('Domyślna paczka', 'length', (int) ($pack['length'] ?? 0) > 0, true, 'Musi być większe niż 0 cm.'),
            $this->item('Domyślna paczka', 'width', (int) ($pack['width'] ?? 0) > 0, true, 'Musi być większe niż 0 cm.'),
            $this->item('Domyślna paczka', 'height', (int) ($pack['height'] ?? 0) > 0, true, 'Musi być większe niż 0 cm.'),
            $this->item('Domyślna paczka', 'weight', (float) ($pack['weight'] ?? 0) > 0, true, 'Musi być większe niż 0 kg.'),
            $this->item('Domyślna paczka', 'amount', (int) ($pack['amount'] ?? 0) > 0, true, 'Musi być większe niż 0.'),
            $this->item('Domyślna paczka', 'type', filled($pack['type'] ?? null), true, 'Domyślny typ paczki Polkurier.'),
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
                category: 'Etykiety',
                name: 'Dysk etykiet',
                ok: filled($disk) && $this->diskExists($disk),
                required: true,
                message: filled($disk)
                    ? sprintf('Configured disk: %s', $disk)
                    : 'POLKURIER_LABEL_DISK is missing.',
            ),
            $this->item(
                category: 'Etykiety',
                name: 'Ścieżka etykiet',
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
                category: 'Protokoły',
                name: 'Dysk protokołów',
                ok: filled($disk) && $this->diskExists($disk),
                required: true,
                message: filled($disk)
                    ? sprintf('Configured disk: %s', $disk)
                    : 'POLKURIER_PROTOCOL_DISK is missing.',
            ),
            $this->item(
                category: 'Protokoły',
                name: 'Ścieżka protokołów',
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
                category: 'Dostępność przewoźników',
                name: 'Cache dostępnych przewoźników',
                ok: $this->availableCarriersService->hasCachedData(),
                required: false,
                message: $this->availableCarriersService->hasCachedData()
                    ? sprintf('Cached carrier records: %d.', count($this->availableCarriersService->cached()))
                    : 'Dostępni przewoźnicy nie zostali jeszcze odświeżeni. Odśwież ich w diagnostyce Polkurier przed użyciem produkcyjnym.',
            ),
        ];

        foreach ($this->availableCarriersService->configuredCarrierSummaries() as $summary) {
            $items[] = $this->item(
                category: 'Dostępność przewoźników',
                name: $summary['label'].' / '.$summary['code'],
                ok: (bool) $summary['available'],
                required: false,
                message: $summary['available']
                    ? $this->carrierSummaryMessage($summary)
                    : 'Ten skonfigurowany przewoźnik nie został zwrócony przez Polkurier available_carriers.',
            );

            if ($summary['required_additional_fields'] !== []) {
                $items[] = $this->item(
                    category: 'Dostępność przewoźników',
                    name: $summary['label'].' required fields',
                    ok: false,
                    required: false,
                    message: 'Wymagane pola dodatkowe: '.implode(', ', $summary['required_additional_fields']).'. Powinny być wyświetlone w formularzu przesyłki w panelu administracyjnym.',
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
            $parts[] = 'typy przesyłek: '.implode(', ', $summary['shipment_types']);
        }

        if ($summary['courier_services'] !== []) {
            $parts[] = 'usługi: '.implode(', ', $summary['courier_services']);
        }

        if ($summary['foreign_shipments'] !== null) {
            $parts[] = 'przesyłki zagraniczne: '.($summary['foreign_shipments'] ? 'tak' : 'nie');
        }

        return $parts !== []
            ? implode('; ', $parts).'.'
            : 'Zwrócono przez Polkurier available_carriers.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function valuationItems(): array
    {
        $enabled = (bool) config('delivery.providers.polkurier.valuation.enabled', false);

        return [
            $this->item(
                category: 'Wycena',
                name: 'Wycena na żywo',
                ok: $enabled,
                required: false,
                message: $enabled
                    ? 'Wycena Polkurier na żywo jest włączona.'
                    : 'Wycena na żywo jest wyłączona. Zostaną użyte ceny zapasowe.',
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
                category: 'Operacje',
                name: 'Harmonogram synchronizacji statusów',
                ok: true,
                required: false,
                message: 'Upewnij się, że scheduler Laravela działa na produkcji, aby polkurier:sync-shipments wykonywało się regularnie.',
            ),
            $this->item(
                category: 'Operacje',
                name: 'Worker kolejki',
                ok: true,
                required: false,
                message: 'Upewnij się, że worker kolejki działa na produkcji, jeśli e-maile trackingowe w kolejce nie używają kolejki sync.',
            ),
            $this->item(
                category: 'Operacje',
                name: 'Wysyłka e-mail',
                ok: true,
                required: false,
                message: 'Upewnij się, że produkcyjna konfiguracja poczty działa, aby klienci otrzymywali e-maile ze śledzeniem przesyłki.',
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
