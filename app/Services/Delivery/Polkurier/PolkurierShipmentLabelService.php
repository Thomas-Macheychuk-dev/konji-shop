<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Enums\DeliveryProvider;
use App\Models\Shipment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PolkurierShipmentLabelService
{
    public function __construct(
        private readonly PolkurierApiClient $client,
    ) {}

    /**
     * @return array{disk: string, path: string, contents: string, filename: string}
     */
    public function getOrStoreLabel(Shipment $shipment): array
    {
        if ($shipment->provider !== DeliveryProvider::POLKURIER) {
            throw new RuntimeException('Only Polkurier shipment labels can be downloaded here.');
        }

        if (! $shipment->provider_reference) {
            throw new RuntimeException('Przesyłka nie ma numeru zamówienia Polkurier.');
        }

        if (
            $shipment->hasStoredLabel()
            && Storage::disk((string) $shipment->label_disk)->exists((string) $shipment->label_path)
        ) {
            return [
                'disk' => (string) $shipment->label_disk,
                'path' => (string) $shipment->label_path,
                'contents' => Storage::disk((string) $shipment->label_disk)->get((string) $shipment->label_path),
                'filename' => $this->filename($shipment),
            ];
        }

        $contents = $this->client->labelPdf([
            $shipment->provider_reference,
        ]);

        $disk = $this->labelDisk();
        $path = $this->labelPath($shipment);

        Storage::disk($disk)->put($path, $contents);

        $shipment->markLabelStored($disk, $path);

        return [
            'disk' => $disk,
            'path' => $path,
            'contents' => $contents,
            'filename' => $this->filename($shipment),
        ];
    }

    private function labelDisk(): string
    {
        return (string) config('delivery.providers.polkurier.labels.disk', 'local');
    }

    private function labelPath(Shipment $shipment): string
    {
        $basePath = trim((string) config('delivery.providers.polkurier.labels.path', 'polkurier/labels'), '/');

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
            'polkurier-label-%s.pdf',
            Str::slug((string) $shipment->provider_reference)
        );
    }
}
