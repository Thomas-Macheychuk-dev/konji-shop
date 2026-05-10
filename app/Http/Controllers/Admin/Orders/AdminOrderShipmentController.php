<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Enums\DeliveryProvider;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Delivery\CreateShipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use ValueError;

final class AdminOrderShipmentController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        CreateShipmentService $createShipmentService,
    ): RedirectResponse {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'service' => ['nullable', 'string', 'max:255'],
            'locker_code' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DeliveryProvider::from($validated['provider']);

            $createShipmentService->create(
                order: $order,
                provider: $validated['provider'],
                service: $validated['service'] ?? null,
                lockerCode: $validated['locker_code'] ?? null,
            );
        } catch (ValueError) {
            return back()->with('error', 'Unsupported delivery provider.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Shipment created.');
    }
}
