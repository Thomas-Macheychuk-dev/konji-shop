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

        $lockers = collect($client->courierPoints(
            couriers: ['INPOST_PACZKOMAT'],
            searchQuery: $query,
            functions: ['collect'],
            limit: 20,
            page: 1,
        ))
            ->filter(fn (array $locker): bool => $this->isSelectableParcelLocker($locker))
            ->take(20)
            ->map(fn (array $locker): array => $this->toSearchResult($locker))
            ->values();

        return response()->json($lockers);
    }

    /**
     * @param array<string, mixed> $locker
     */
    private function isSelectableParcelLocker(array $locker): bool
    {
        if (! isset($locker['id']) || ! is_string($locker['id']) || trim($locker['id']) === '') {
            return false;
        }

        if (($locker['provider'] ?? null) !== 'INPOST_PACZKOMAT') {
            return false;
        }

        if (($locker['available'] ?? false) !== true) {
            return false;
        }

        if (($locker['collect'] ?? false) !== true) {
            return false;
        }

        if (($locker['visible'] ?? true) !== true) {
            return false;
        }

        $status = $locker['status'] ?? null;

        return $status === null || in_array($status, ['Operating', 'Overloaded'], true);
    }

    /**
     * @param array<string, mixed> $locker
     * @return array{code: string, label: string}
     */
    private function toSearchResult(array $locker): array
    {
        $code = (string) ($locker['id'] ?? '');
        $address = $this->formatAddress($locker);

        return [
            'code' => $code,
            'label' => $address !== '' ? $code.' — '.$address : $code,
        ];
    }

    /**
     * @param array<string, mixed> $locker
     */
    private function formatAddress(array $locker): string
    {
        if (isset($locker['address']) && is_string($locker['address']) && trim($locker['address']) !== '') {
            return trim($locker['address']);
        }

        $cityLine = trim(implode(' ', array_filter([
            $locker['zip'] ?? null,
            $locker['city'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        $street = isset($locker['street']) && is_string($locker['street']) ? trim($locker['street']) : '';
        $description = isset($locker['description']) && is_string($locker['description']) ? trim($locker['description']) : '';

        return trim(implode(', ', array_filter([
            $cityLine,
            $street,
            $description,
        ], fn (string $value): bool => $value !== '')));
    }
}
