<?php

declare(strict_types=1);

namespace App\Http\Controllers\Checkout;

use App\Http\Controllers\Controller;
use App\Services\Delivery\Polkurier\PolkurierApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class PolkurierMapTokenController extends Controller
{
    public function __invoke(PolkurierApiClient $client): JsonResponse
    {
        $token = Cache::remember(
            'polkurier:checkout-map-token',
            now()->addMinutes(210),
            fn (): string => $client->mapToken(),
        );

        $apiBaseUrl = (string) config('delivery.providers.polkurier.base_url');

        return response()->json([
            'token' => $token,
            'map_base_url' => str_contains($apiBaseUrl, 'sandbox')
                ? 'https://maps-sandbox.polkurier.pl/'
                : 'https://maps.polkurier.pl/',
        ]);
    }
}
