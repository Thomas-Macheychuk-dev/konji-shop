<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class RefreshGoogleCrawlerRangesCommand extends Command
{
    protected $signature = 'traffic:refresh-google-crawler-ranges
        {--show-prefixes : Print the downloaded CIDR prefixes}';

    protected $description = 'Refresh the official Google crawler and fetcher IP ranges used by traffic protection.';

    public function handle(): int
    {
        $sources = array_values(array_filter(
            (array) config('traffic_protection.google.sources', []),
            static fn (mixed $source): bool => is_string($source) && $source !== '',
        ));

        if ($sources === []) {
            $this->error('No Google crawler range sources are configured.');

            return self::FAILURE;
        }

        $prefixes = [];
        $sourceMetadata = [];

        foreach ($sources as $source) {
            $this->line('Fetching '.$source);

            try {
                $response = Http::acceptJson()
                    ->timeout(max(1, (int) config('traffic_protection.google.refresh_timeout_seconds', 20)))
                    ->retry(
                        max(1, (int) config('traffic_protection.google.refresh_attempts', 3)),
                        500,
                        static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                        throw: false,
                    )
                    ->get($source);
            } catch (Throwable $exception) {
                $this->error('Failed to fetch '.$source.': '.$exception->getMessage());

                return self::FAILURE;
            }

            if (! $response->successful()) {
                $this->error(sprintf('Failed to fetch %s: HTTP %d', $source, $response->status()));

                return self::FAILURE;
            }

            $payload = $response->json();

            if (! is_array($payload) || ! is_array($payload['prefixes'] ?? null)) {
                $this->error('Invalid Google crawler range payload from '.$source);

                return self::FAILURE;
            }

            $sourceCount = 0;

            foreach ($payload['prefixes'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $prefix = $entry['ipv4Prefix'] ?? $entry['ipv6Prefix'] ?? null;

                if (! is_string($prefix) || $prefix === '') {
                    continue;
                }

                $prefixes[$prefix] = true;
                $sourceCount++;
            }

            $sourceMetadata[] = [
                'url' => $source,
                'creation_time' => is_string($payload['creationTime'] ?? null)
                    ? $payload['creationTime']
                    : null,
                'prefix_count' => $sourceCount,
            ];
        }

        $prefixList = array_keys($prefixes);
        sort($prefixList, SORT_STRING);

        if ($prefixList === []) {
            $this->error('Google crawler range refresh returned zero CIDR prefixes.');

            return self::FAILURE;
        }

        $path = (string) config('traffic_protection.google.ranges_file');

        if ($path === '') {
            $this->error('Google crawler range file path is not configured.');

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Could not create directory: '.$directory);

            return self::FAILURE;
        }

        try {
            $json = json_encode([
                'refreshed_at' => now()->toIso8601String(),
                'sources' => $sourceMetadata,
                'prefixes' => $prefixList,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (JsonException $exception) {
            $this->error('Could not encode Google crawler ranges: '.$exception->getMessage());

            return self::FAILURE;
        }

        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            $this->error('Could not atomically write Google crawler range file: '.$path);

            return self::FAILURE;
        }

        $this->info(sprintf('Saved %d unique Google CIDR prefixes to %s', count($prefixList), $path));

        if ($this->option('show-prefixes')) {
            foreach ($prefixList as $prefix) {
                $this->line($prefix);
            }
        }

        return self::SUCCESS;
    }
}
