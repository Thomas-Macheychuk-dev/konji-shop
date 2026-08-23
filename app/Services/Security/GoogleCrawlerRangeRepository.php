<?php

declare(strict_types=1);

namespace App\Services\Security;

use JsonException;

final class GoogleCrawlerRangeRepository
{
    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        $path = (string) config('traffic_protection.google.ranges_file');

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $prefixes = $decoded['prefixes'] ?? [];

        if (! is_array($prefixes)) {
            return [];
        }

        return array_values(array_filter(
            $prefixes,
            static fn (mixed $prefix): bool => is_string($prefix) && $prefix !== '',
        ));
    }
}
