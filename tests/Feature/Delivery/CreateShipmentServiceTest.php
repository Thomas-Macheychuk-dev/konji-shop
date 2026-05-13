<?php

use App\Contracts\Delivery\DeliveryGateway;
use App\Data\Delivery\ShipmentCreationResult;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Delivery\CreateShipmentService;
use App\Services\Delivery\DeliveryGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeDeliveryGateway(): DeliveryGateway
{
    return new class implements DeliveryGateway {
        public function providerKey(): string
        {
            return DeliveryProvider::POLKURIER->value;
        }

        public function createShipment(Order $order, Shipment $shipment): ShipmentCreationResult
        {
            return new ShipmentCreationResult(
                provider: DeliveryProvider::POLKURIER->value,
                providerReference: 'inpost-shipment-123',
                trackingNumber: 'TRACK123',
                trackingUrl: 'https://example.test/track/TRACK123',
                payload: [
                    'provider_status' => 'created',
                ],
            );
        }
    };
}

it('creates a shipment through a delivery gateway', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $service = new CreateShipmentService(
        new DeliveryGatewayRegistry([
            fakeDeliveryGateway(),
        ]),
    );

    $shipment = $service->create(
        order: $order,
        provider: DeliveryProvider::POLKURIER->value,
        service: 'parcel_locker',
        lockerCode: 'WAW01A',
    );

    expect($shipment)
        ->provider->toBe(DeliveryProvider::POLKURIER)
        ->status->toBe(ShipmentStatus::CREATED)
        ->provider_reference->toBe('inpost-shipment-123')
        ->tracking_number->toBe('TRACK123')
        ->tracking_url->toBe('https://example.test/track/TRACK123')
        ->service->toBe('parcel_locker')
        ->locker_code->toBe('WAW01A')
        ->payload->toBe([
            'provider_status' => 'created',
        ]);

    expect($order->shipments()->count())->toBe(1);

    expect($order->events()->where('type', 'shipment_created')->exists())->toBeTrue();
});

it('does not create a shipment for an order that is not confirmed', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING_PAYMENT,
        'payment_status' => PaymentStatus::UNPAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $service = new CreateShipmentService(
        new DeliveryGatewayRegistry([
            fakeDeliveryGateway(),
        ]),
    );

    $service->create(
        order: $order,
        provider: DeliveryProvider::POLKURIER->value,
    );
})->throws(RuntimeException::class, 'Only confirmed orders can have shipments created.');
