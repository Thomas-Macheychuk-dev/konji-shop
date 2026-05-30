<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Contracts\Delivery\DeliveryGateway;
use App\Data\Delivery\ShipmentCreationResult;
use App\Enums\DeliveryProvider;
use App\Models\Order;
use App\Models\Shipment;
use RuntimeException;

final class PolkurierDeliveryGateway implements DeliveryGateway
{
    public function __construct(
        private readonly PolkurierApiClient $client,
        private readonly PolkurierPackBuilder $packBuilder,
    ) {}

    public function providerKey(): string
    {
        return DeliveryProvider::POLKURIER->value;
    }

    public function createShipment(Order $order, Shipment $shipment, array $options = []): ShipmentCreationResult
    {
        $payload = $this->client->request('create_order', [
            'shipmenttype' => (string) config('delivery.providers.polkurier.default_pack.shipmenttype', 'box'),
            'courier' => $this->courierCode($order),
            'description' => mb_substr('Konji Shop order '.$order->number, 0, 30),
            'sender' => $this->sender(),
            'recipient' => $this->recipient($order, $shipment),
            'packs' => $this->packBuilder->fromOrder($order),
            'pickup' => $this->pickup($options),
        ]);

        $response = $payload['response'];

        return new ShipmentCreationResult(
            provider: DeliveryProvider::POLKURIER->value,
            providerReference: (string) $response['order_number'],
            trackingNumber: $response['label'][0] ?? null,
            trackingUrl: $response['url_tracktrace'] ?? null,
            payload: $payload,
        );
    }

    private function courierCode(Order $order): string
    {
        if (
            $order->delivery_service === 'parcel_locker'
            && $order->delivery_carrier?->value === 'inpost'
        ) {
            return 'INPOST_PACZKOMAT';
        }

        return match ($order->delivery_carrier?->value) {
            'ups' => 'UPS',
            'dpd' => 'DPD',
            'dhl' => 'DHL',
            'inpost' => 'INPOST',
            default => strtoupper((string) $order->delivery_carrier?->value),
        };
    }

    private function sender(): array
    {
        $sender = config('delivery.providers.polkurier.sender');

        if (! is_array($sender)) {
            throw new RuntimeException('Polkurier sender configuration is missing.');
        }

        return [
            'company' => (string) ($sender['company'] ?? ''),
            'person' => (string) ($sender['person'] ?? ''),
            'street' => (string) ($sender['street'] ?? ''),
            'housenumber' => (string) ($sender['housenumber'] ?? ''),
            'flatnumber' => (string) ($sender['flatnumber'] ?? ''),
            'postcode' => (string) ($sender['postcode'] ?? ''),
            'city' => (string) ($sender['city'] ?? ''),
            'email' => (string) ($sender['email'] ?? ''),
            'phone' => $this->normalizePhone((string) ($sender['phone'] ?? '')),
            'country' => (string) ($sender['country'] ?? 'PL'),
            'point_id' => (string) ($sender['point_id'] ?? $sender['machinename'] ?? ''),
        ];
    }

    private function recipient(Order $order, Shipment $shipment): array
    {
        $shippingAddress = $order->shippingAddress()->first()
            ?? $order->addresses()->where('type', 'shipping')->first();

        if ($shippingAddress === null) {
            throw new RuntimeException('Order has no shipping address.');
        }

        return [
            'company' => (string) ($shippingAddress->company ?? ''),
            'person' => mb_substr(trim($shippingAddress->first_name.' '.$shippingAddress->last_name), 0, 35),
            'street' => mb_substr((string) $shippingAddress->address_line_1, 0, 40),
            'housenumber' => '1',
            'flatnumber' => mb_substr((string) ($shippingAddress->address_line_2 ?? ''), 0, 5),
            'postcode' => (string) $shippingAddress->postcode,
            'city' => mb_substr((string) $shippingAddress->city, 0, 35),
            'email' => (string) ($shippingAddress->email ?? $order->guest_email ?? $order->user?->email ?? ''),
            'phone' => $this->normalizePhone((string) ($shippingAddress->phone ?? '123123123')),
            'country' => (string) ($shippingAddress->country_code ?: 'PL'),
            'point_id' => (string) ($shipment->locker_code ?? ''),
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($normalized, '48') && strlen($normalized) > 9) {
            $normalized = substr($normalized, 2);
        }

        return mb_substr($normalized, 0, 15);
    }

    private function pickup(array $options): array
    {
        $pickup = $options['pickup'] ?? [];

        if (! is_array($pickup)) {
            $pickup = [];
        }

        if ($pickup !== []) {
            return $pickup;
        }

        return [
            'nocourierorder' => true,
        ];
    }
}
