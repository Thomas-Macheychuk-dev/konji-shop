<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Services\Shop\ShopConfiguration;
use App\Services\Shop\ShopReadinessCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AdminShopReadinessController extends Controller
{
    public function __construct(
        private readonly ShopReadinessCheck $readinessCheck,
        private readonly ShopConfiguration $configuration,
    ) {}

    public function __invoke(): View
    {
        $summary = $this->readinessCheck->summary();

        return view('admin.shop.readiness', [
            'ready' => $summary['ready'],
            'items' => $summary['items'],
            'settingsFields' => $this->configuration->editableFields(),
            'settingsValues' => $this->configuration->formValues(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings.seller_identity_address' => ['required', 'string', 'max:2000'],
            'settings.seller_email' => ['required', 'email', 'max:255'],
            'settings.seller_phone' => ['required', 'string', 'max:255'],
            'settings.seller_tax_id' => ['nullable', 'string', 'max:255'],
            'settings.return_address' => ['required', 'string', 'max:2000'],
            'settings.mail_from_address' => ['required', 'email', 'max:255'],
            'settings.polkurier_login' => ['required', 'string', 'max:255'],
            'settings.polkurier_token' => ['required', 'string', 'max:2000'],
        ]);

        $this->configuration->updateFromForm($validated['settings']);

        return redirect()
            ->route('admin.shop.readiness')
            ->with('status', 'Ustawienia gotowości produkcyjnej zostały zapisane.');
    }
}
