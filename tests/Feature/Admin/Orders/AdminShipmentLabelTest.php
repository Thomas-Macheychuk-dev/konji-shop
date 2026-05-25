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

it('downloads and stores a Polkurier shipment label locally', function (): void {
    Storage::fake('local');

    Config::set('delivery.providers.polkurier.labels.disk', 'local');
    Config::set('delivery.providers.polkurier.labels.path', 'polkurier/labels');

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

    $shipment->refresh();

    expect($shipment->label_disk)->toBe('local')
        ->and($shipment->label_path)->not->toBeNull()
        ->and($shipment->label_downloaded_at)->not->toBeNull();

    Storage::disk('local')->assertExists($shipment->label_path);

    expect(Storage::disk('local')->get($shipment->label_path))->toBe($pdf);

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'get_label');
});

it('serves an already stored Polkurier label without calling Polkurier again', function (): void {
    Storage::fake('local');

    Config::set('delivery.providers.polkurier.labels.disk', 'local');
    Config::set('delivery.providers.polkurier.labels.path', 'polkurier/labels');

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $order = Order::factory()->create();

    $pdf = '%PDF-1.4 stored fake label pdf';

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
        'label_disk' => 'local',
        'label_path' => 'polkurier/labels/1/polkurier-label-1234-1.pdf',
        'label_downloaded_at' => now(),
    ]);

    Storage::disk('local')->put($shipment->label_path, $pdf);

    Http::fake();

    $this
        ->actingAs($admin)
        ->get(route('admin.shipments.label', $shipment))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee($pdf, false);

    Http::assertNothingSent();
});
