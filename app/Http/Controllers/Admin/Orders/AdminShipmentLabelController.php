<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Enums\DeliveryProvider;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\PolkurierApiClient;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

final class AdminShipmentLabelController extends Controller
{
    public function __construct(
        private readonly PolkurierApiClient $polkurier,
    ) {}

    public function __invoke(Shipment $shipment): Response
    {
        if ($shipment->provider !== DeliveryProvider::POLKURIER) {
            abort(404);
        }

        if (! $shipment->provider_reference) {
            throw new RuntimeException('Shipment has no Polkurier order number.');
        }

        $pdf = $this->polkurier->labelPdf([
            $shipment->provider_reference,
        ]);

        $filename = sprintf(
            'polkurier-label-%s.pdf',
            Str::slug((string) $shipment->provider_reference)
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
