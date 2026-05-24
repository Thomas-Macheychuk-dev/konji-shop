<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\SyncPolkurierShipmentStatusService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class AdminShipmentStatusRefreshController extends Controller
{
    public function __construct(
        private readonly SyncPolkurierShipmentStatusService $syncStatus,
    ) {}

    public function __invoke(Shipment $shipment): RedirectResponse
    {
        try {
            $this->syncStatus->sync($shipment);
        } catch (DomainException|RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Shipment status refreshed.');
    }
}
