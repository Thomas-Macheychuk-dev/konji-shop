<?php

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('downloads and stores a Polkurier shipment protocol locally', function (): void {
    Storage::fake('local');

    Config::set('delivery.providers.polkurier.protocols.disk', 'local');
    Config::set('delivery.providers.polkurier.protocols.path', 'polkurier/protocols');

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

    $pdf = '%PDF-1.4 fake protocol pdf';

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
        ->get(route('admin.shipments.protocol', $shipment))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee($pdf, false);

    $shipment->refresh();

    expect($shipment->protocol_disk)->toBe('local')
        ->and($shipment->protocol_path)->not->toBeNull()
        ->and($shipment->protocol_downloaded_at)->not->toBeNull();

    Storage::disk('local')->assertExists($shipment->protocol_path);

    expect(Storage::disk('local')->get($shipment->protocol_path))->toBe($pdf);

    expect($order->events()->where('type', 'shipment_protocol_stored')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'get_protocol'
        && $request['data']['orderno'] === ['1234-1']);
});

it('serves an already stored Polkurier protocol without calling Polkurier again', function (): void {
    Storage::fake('local');

    Config::set('delivery.providers.polkurier.protocols.disk', 'local');
    Config::set('delivery.providers.polkurier.protocols.path', 'polkurier/protocols');

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create();

    $pdf = '%PDF-1.4 stored fake protocol pdf';

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
        'protocol_disk' => 'local',
        'protocol_path' => 'polkurier/protocols/1/polkurier-protocol-1234-1.pdf',
        'protocol_downloaded_at' => now(),
    ]);

    Storage::disk('local')->put($shipment->protocol_path, $pdf);

    Http::fake();

    $this
        ->actingAs($admin)
        ->get(route('admin.shipments.protocol', $shipment))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee($pdf, false);

    Http::assertNothingSent();
});
