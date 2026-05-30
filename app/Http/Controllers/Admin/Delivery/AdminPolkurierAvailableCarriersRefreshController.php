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
                ->with('error', 'Could not refresh Polkurier available carriers: '.$exception->getMessage());
        }

        return redirect()
            ->route('admin.polkurier.index')
            ->with('success', 'Polkurier available carriers refreshed. Found '.count($carriers).' carrier(s).');
    }
}
