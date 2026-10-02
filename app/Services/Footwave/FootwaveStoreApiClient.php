<?php

declare(strict_types=1);

namespace App\Services\Footwave;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FootwaveStoreApiClient
{
    private const BASE_URL = 'https://footwave.pl/wp-json/wc/store/v1';

    /**
     * Return top-level WooCommerce catalogue products.
     *
     * Variation rows are deliberately excluded here. They are hydrated
     * individually from the IDs exposed by each variable parent product.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(?int $limit = null): array
    {
        $products = [];
        $page = 1;

        do {
            $response = $this->get('/products', [
                'per_page' => 100,
                'page' => $page,
            ]);

            $decoded = $response->json();

            if (! is_array($decoded)) {
                throw new RuntimeException(
                    'FootWave Store API catalogue returned invalid JSON.'
                );
            }

            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! in_array(
                    $row['type'] ?? null,
                    ['simple', 'variable'],
                    true,
                )) {
                    continue;
                }

                $products[] = $row;

                if ($limit !== null && count($products) >= $limit) {
                    return array_slice($products, 0, $limit);
                }
            }

            $totalPages = max(
                1,
                (int) ($response->header('X-WP-TotalPages') ?: 1),
            );

            $page++;
        } while ($page <= $totalPages);

        return array_values($products);
    }

    /**
     * @return array<string, mixed>
     */
    public function product(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException(
                'FootWave product ID must be a positive integer.'
            );
        }

        $decoded = $this->get('/products/'.$id)->json();

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'FootWave Store API product '.$id.' returned invalid JSON.'
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function get(
        string $path,
        array $query = [],
    ): Response {
        $url = self::BASE_URL.$path;

        $response = Http::connectTimeout(10)
            ->timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.5',
                'User-Agent' => 'KonjiShopImporter/1.0 (+https://ortezka.pl)',
            ])
            ->get($url, $query);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'FootWave Store API request failed: HTTP %d for %s',
                $response->status(),
                $url,
            ));
        }

        return $response;
    }
}
