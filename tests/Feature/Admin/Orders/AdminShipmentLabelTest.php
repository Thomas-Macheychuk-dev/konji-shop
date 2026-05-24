<?php

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('allows an admin to download a Polkurier shipment label', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create();

    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => DeliveryProvider::POLKURIER,
        'status' => ShipmentStatus::CREATED,
        'provider_reference' => '1234-1',
        'tracking_number' => 'TRACK123',
        'tracking_url' => 'https://example.com/track/TRACK123',
        'service' => 'courier',
        'locker_code' => null,
        'payload' => [],
    ]);

    $pdf = '%PDF-1.4 fake label pdf';

    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                'file' => base64_encode($pdf),
            ],
        ]),
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.shipments.label', $shipment))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee($pdf, false);
});
