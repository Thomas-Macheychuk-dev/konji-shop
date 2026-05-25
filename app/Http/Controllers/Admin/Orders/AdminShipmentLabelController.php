<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\PolkurierShipmentLabelService;
use Illuminate\Http\Response;

final class AdminShipmentLabelController extends Controller
{
    public function __construct(
        private readonly PolkurierShipmentLabelService $labelService,
    ) {}

    public function __invoke(Shipment $shipment): Response
    {
        $label = $this->labelService->getOrStoreLabel($shipment);

        return response($label['contents'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$label['filename'].'"',
        ]);
    }
}
