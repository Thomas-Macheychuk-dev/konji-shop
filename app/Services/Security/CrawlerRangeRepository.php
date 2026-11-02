<?php

declare(strict_types=1);

namespace App\Services\Security;

use JsonException;

final class CrawlerRangeRepository
{
    public function hasRanges(string $crawler): bool
    {
        $path = $this->path($crawler);

        return $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * @return list<string>
     */
    public function prefixes(string $crawler): array
    {
        if (! $this->hasRanges($crawler)) {
            return [];
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($this->path($crawler)),
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

    private function path(string $crawler): string
    {
        return (string) config('traffic_protection.verified_crawlers.'.$crawler.'.ranges_file');
    }
}
