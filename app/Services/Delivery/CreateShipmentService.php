<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Contracts\Delivery\CreatesShipments;
use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CreateShipmentService implements CreatesShipments
{
    public function __construct(
        private readonly DeliveryGatewayRegistry $registry,
    ) {}

    public function create(
        Order $order,
        string $provider,
        ?string $service = null,
        ?string $lockerCode = null,
        ?array $pickup = null,
    ): Shipment {
        if (! $order->status->isConfirmed()) {
            throw new RuntimeException('Only confirmed orders can have shipments created.');
        }

        $shipment = DB::transaction(function () use ($order, $provider, $service, $lockerCode): Shipment {
            return Shipment::query()->create([
                'order_id' => $order->id,
                'provider' => DeliveryProvider::from($provider),
                'status' => ShipmentStatus::PENDING,
                'service' => $service,
                'locker_code' => $lockerCode,
            ]);
        });

        try {
            $gateway = $this->registry->for($provider);

            $result = $gateway->createShipment($order, $shipment, [
                'pickup' => $pickup ?? [
                        'nocourierorder' => true,
                    ],
            ]);

            DB::transaction(function () use ($shipment, $result): void {
                $shipment->update([
                    'provider_reference' => $result->providerReference,
                    'tracking_number' => $result->trackingNumber,
                    'tracking_url' => $result->trackingUrl,
                    'payload' => $result->payload,
                ]);

                $shipment->markAsCreated($result->providerReference, $result->payload);
            });

            return $shipment->refresh();
        } catch (Throwable $exception) {
            $failurePayload = [
                'error' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ];

            $shipment->markAsFailed($failurePayload);

            throw new RuntimeException(
                'Shipment creation failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
