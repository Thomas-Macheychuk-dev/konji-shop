<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class AdminPolkurierAvailableCarriersRefreshController extends Controller
{
    public function __construct(
        private readonly PolkurierAvailableCarriersService $availableCarriersService,
    ) {}

    public function __invoke(): RedirectResponse
    {
        try {
            $carriers = $this->availableCarriersService->refresh();
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.polkurier.index')
                ->with('error', 'Nie udało się odświeżyć dostępnych przewoźników Polkurier: '.$exception->getMessage());
        }

        return redirect()
            ->route('admin.polkurier.index')
            ->with('success', 'Odświeżono dostępnych przewoźników Polkurier. Liczba przewoźników: '.count($carriers).'.');
    }
}
