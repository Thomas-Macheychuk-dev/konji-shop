<?php

declare(strict_types=1);

namespace App\Http\Controllers\Checkout;

use App\Http\Controllers\Controller;
use App\Services\Delivery\Polkurier\PolkurierApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class InPostParcelLockerSearchController extends Controller
{
    public function __invoke(Request $request, PolkurierApiClient $client): JsonResponse
    {
        $query = Str::lower(trim((string) $request->query('query')));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $lockers = collect($client->inpostPointsMachines())
            ->filter(function (array $locker) use ($query): bool {
                if (! in_array($locker['status'] ?? null, ['Operating', 'Overloaded'], true)) {
                    return false;
                }

                if (($locker['parcel_collect'] ?? false) !== true) {
                    return false;
                }

                $haystack = Str::lower(
                    ($locker['name'] ?? '').' '.($locker['adres'] ?? '')
                );

                return str_contains($haystack, $query);
            })
            ->take(20)
            ->map(fn (array $locker): array => [
                'code' => $locker['name'],
                'label' => $locker['name'].' — '.$locker['adres'],
            ])
            ->values();

        return response()->json($lockers);
    }
}
