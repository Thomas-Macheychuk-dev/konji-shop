<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Enums\DeliveryCarrier;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Delivery\Polkurier\PolkurierApiClient;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class AdminPolkurierPickupTimesController extends Controller
{
    public function __invoke(Order $order, PolkurierApiClient $client): JsonResponse
    {
        if ($order->delivery_service === 'local_pickup') {
            return response()->json([
                'data' => [],
            ]);
        }

        $sender = config('delivery.providers.polkurier.sender');

        if (! is_array($sender) || blank($sender['postcode'] ?? null)) {
            throw new RuntimeException('Polkurier sender postcode is missing.');
        }

        $defaultPack = config('delivery.providers.polkurier.default_pack');

        if (! is_array($defaultPack)) {
            throw new RuntimeException('Polkurier default pack configuration is missing.');
        }

        $shippingAddress = $order->shippingAddress()->first()
            ?? $order->addresses()->where('type', 'shipping')->first();

        $pickupTimes = $client->courierPickupTimes(
            courier: $this->courierCode($order),
            senderPostcode: (string) $sender['postcode'],
            shipmentType: (string) ($defaultPack['shipmenttype'] ?? 'box'),
            recipientPostcode: $shippingAddress?->postcode,
        );

        return response()->json([
            'data' => $this->normalizePickupTimes($pickupTimes),
        ]);
    }

    private function courierCode(Order $order): string
    {
        if (
            $order->delivery_service === 'parcel_locker'
            && $order->delivery_carrier === DeliveryCarrier::INPOST
        ) {
            return 'INPOST_PACZKOMAT';
        }

        return match ($order->delivery_carrier) {
            DeliveryCarrier::UPS => 'UPS',
            DeliveryCarrier::DPD => 'DPD',
            DeliveryCarrier::INPOST => 'INPOST',
            default => strtoupper((string) $order->delivery_carrier?->value),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $pickupTimes
     * @return array<int, array{date: string, time_from: string|null, time_to: string|null, label: string}>
     */
    private function normalizePickupTimes(array $pickupTimes): array
    {
        $options = [];

        foreach ($pickupTimes as $day) {
            if (! is_array($day)) {
                continue;
            }

            $date = $day['pickupdate'] ?? null;

            if (! is_string($date) || trim($date) === '') {
                continue;
            }

            $times = $day['time'] ?? [];

            if (! is_array($times) || $times === []) {
                $options[] = [
                    'date' => $date,
                    'time_from' => null,
                    'time_to' => null,
                    'label' => $date.' — pickup date only',
                ];

                continue;
            }

            foreach ($times as $time) {
                if (! is_array($time)) {
                    continue;
                }

                $timeFrom = $time['timefrom'] ?? null;
                $timeTo = $time['timeto'] ?? null;

                if (! is_string($timeFrom) || ! is_string($timeTo)) {
                    continue;
                }

                $options[] = [
                    'date' => $date,
                    'time_from' => $timeFrom,
                    'time_to' => $timeTo,
                    'label' => $date.' '.$timeFrom.'-'.$timeTo,
                ];
            }
        }

        return $options;
    }
}
