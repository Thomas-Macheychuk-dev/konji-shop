<?php

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\DeliveryService;
use App\Enums\ShipmentStatus;
use App\Mail\ShipmentTrackingMail;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'sync');

    Mail::fake();
});

it('sends a tracking email when shipment tracking becomes available', function (): void {
    $order = Order::factory()->guest('guest@example.test')->paid()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::INPOST,
        'delivery_service' => DeliveryService::PARCEL_LOCKER->value,
        'delivery_locker_code' => 'WAW01A',
    ]);

    $order->shippingAddress()->create([
        'type' => 'shipping',
        'first_name' => 'Jan',
        'last_name' => 'Kowalski',
        'company' => null,
        'phone' => '123456789',
        'email' => 'guest@example.test',
        'address_line_1' => 'Test Street 1',
        'address_line_2' => null,
        'city' => 'Warszawa',
        'postcode' => '00-001',
        'country_code' => 'PL',
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'provider_reference' => '1234-10',
        'status' => ShipmentStatus::CREATED,
        'tracking_number' => '13299300045383',
        'tracking_url' => 'https://example.test/track/13299300045383',
        'service' => DeliveryService::PARCEL_LOCKER->value,
        'locker_code' => 'WAW01A',
    ]);

    $shipment->markAsCreated('1234-10');

    Mail::assertSent(ShipmentTrackingMail::class, function (ShipmentTrackingMail $mail) use ($shipment): bool {
        return $mail->hasTo('guest@example.test')
            && $mail->shipment->is($shipment);
    });

    expect($shipment->refresh()->tracking_email_sent_at)->not->toBeNull();

    expect($order->events()->where('type', 'shipment_tracking_email_sent')->exists())->toBeTrue();
});

it('does not send the tracking email twice for the same shipment', function (): void {
    $order = Order::factory()->guest('guest@example.test')->paid()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'provider_reference' => '1234-11',
        'status' => ShipmentStatus::CREATED,
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.test/track/TRACK123',
        'service' => DeliveryService::COURIER->value,
    ]);

    $shipment->markAsCreated('1234-11');
    $shipment->markAsCreated('1234-11');

    Mail::assertSent(ShipmentTrackingMail::class, 1);
});

it('does not send a tracking email when tracking details are missing', function (): void {
    $order = Order::factory()->guest('guest@example.test')->paid()->create([
        'delivery_provider' => DeliveryProvider::POLKURIER,
        'delivery_carrier' => DeliveryCarrier::DPD,
        'delivery_service' => DeliveryService::COURIER->value,
    ]);

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'provider_reference' => '1234-12',
        'status' => ShipmentStatus::PENDING,
        'service' => DeliveryService::COURIER->value,
    ]);

    $shipment->markAsCreated('1234-12');

    Mail::assertNothingSent();
});
