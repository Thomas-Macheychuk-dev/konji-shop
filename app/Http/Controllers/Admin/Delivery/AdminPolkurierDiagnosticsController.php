<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class AdminPolkurierDiagnosticsController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.delivery.polkurier-diagnostics', [
            'configuration' => $this->configuration(),
            'senderFields' => $this->senderFields(),
            'defaultPackFields' => $this->defaultPackFields(),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function configuration(): array
    {
        return [
            'Base URL configured' => filled(config('delivery.providers.polkurier.base_url')),
            'Login configured' => filled(config('delivery.providers.polkurier.login')),
            'Token configured' => filled(config('delivery.providers.polkurier.token')),
            'Sender configured' => $this->senderConfigured(),
            'Default pack configured' => $this->defaultPackConfigured(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function senderFields(): array
    {
        $sender = config('delivery.providers.polkurier.sender');

        if (! is_array($sender)) {
            return [];
        }

        return [
            'company' => filled($sender['company'] ?? null),
            'person' => filled($sender['person'] ?? null),
            'street' => filled($sender['street'] ?? null),
            'housenumber' => filled($sender['housenumber'] ?? null),
            'postcode' => filled($sender['postcode'] ?? null),
            'city' => filled($sender['city'] ?? null),
            'email' => filled($sender['email'] ?? null),
            'phone' => filled($sender['phone'] ?? null),
            'country' => filled($sender['country'] ?? null),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function defaultPackFields(): array
    {
        $pack = config('delivery.providers.polkurier.default_pack');

        if (! is_array($pack)) {
            return [];
        }

        return [
            'shipmenttype' => filled($pack['shipmenttype'] ?? null),
            'length' => (int) ($pack['length'] ?? 0) > 0,
            'width' => (int) ($pack['width'] ?? 0) > 0,
            'height' => (int) ($pack['height'] ?? 0) > 0,
            'weight' => (float) ($pack['weight'] ?? 0) > 0,
            'amount' => (int) ($pack['amount'] ?? 0) > 0,
            'type' => filled($pack['type'] ?? null),
        ];
    }

    private function senderConfigured(): bool
    {
        foreach ($this->senderFields() as $configured) {
            if (! $configured) {
                return false;
            }
        }

        return $this->senderFields() !== [];
    }

    private function defaultPackConfigured(): bool
    {
        foreach ($this->defaultPackFields() as $configured) {
            if (! $configured) {
                return false;
            }
        }

        return $this->defaultPackFields() !== [];
    }
}
