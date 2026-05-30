<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Enums\DeliveryProvider;
use App\Models\Shipment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PolkurierShipmentProtocolService
{
    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    /**
     * @return array{disk: string, path: string, contents: string, filename: string}
     */
    public function getOrStoreProtocol(Shipment $shipment): array
    {
        if ($shipment->provider !== DeliveryProvider::POLKURIER) {
            throw new RuntimeException('Only Polkurier shipment protocols can be downloaded here.');
        }

        if (! $shipment->provider_reference) {
            throw new RuntimeException('Shipment has no Polkurier order number.');
        }

        if (
            $shipment->hasStoredProtocol()
            && Storage::disk((string) $shipment->protocol_disk)->exists((string) $shipment->protocol_path)
        ) {
            return [
                'disk' => (string) $shipment->protocol_disk,
                'path' => (string) $shipment->protocol_path,
                'contents' => Storage::disk((string) $shipment->protocol_disk)->get((string) $shipment->protocol_path),
                'filename' => $this->filename($shipment),
            ];
        }

        $contents = $this->client->protocolPdf([
            $shipment->provider_reference,
        ]);

        $disk = $this->protocolDisk();
        $path = $this->protocolPath($shipment);

        Storage::disk($disk)->put($path, $contents);

        $shipment->markProtocolStored($disk, $path);

        return [
            'disk' => $disk,
            'path' => $path,
            'contents' => $contents,
            'filename' => $this->filename($shipment),
        ];
    }

    private function protocolDisk(): string
    {
        return (string) config('delivery.providers.polkurier.protocols.disk', 'local');
    }

    private function protocolPath(Shipment $shipment): string
    {
        $basePath = trim((string) config('delivery.providers.polkurier.protocols.path', 'polkurier/protocols'), '/');

        return sprintf(
            '%s/%s/%s',
            $basePath,
            $shipment->id,
            $this->filename($shipment),
        );
    }

    private function filename(Shipment $shipment): string
    {
        return sprintf(
            'polkurier-protocol-%s.pdf',
            Str::slug((string) $shipment->provider_reference)
        );
    }
}
