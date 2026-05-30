<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\PolkurierShipmentProtocolService;
use Illuminate\Http\Response;

final class AdminShipmentProtocolController extends Controller
{
    public function __construct(
        private readonly PolkurierShipmentProtocolService $protocolService,
    ) {}

    public function __invoke(Shipment $shipment): Response
    {
        $protocol = $this->protocolService->getOrStoreProtocol($shipment);

        return response($protocol['contents'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$protocol['filename'].'"',
        ]);
    }
}
