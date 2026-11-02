<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class RefreshCrawlerRangesCommand extends Command
{
    protected $signature = 'traffic:refresh-crawler-ranges
        {crawler?* : Crawlers to refresh, e.g. google bing (default: all configured)}
        {--show-prefixes : Print the downloaded CIDR prefixes}';

    protected $description = 'Refresh the official search engine crawler IP ranges used by traffic protection.';

    public function handle(): int
    {
        $configured = (array) config('traffic_protection.verified_crawlers', []);
        $crawlers = (array) $this->argument('crawler') ?: array_keys($configured);

        if ($crawlers === []) {
            $this->error('No verified crawlers are configured.');

            return self::FAILURE;
        }

        $failed = false;

        // One crawler's feed being down must not keep the others' ranges from refreshing.
        foreach ($crawlers as $crawler) {
            if (! is_array($configured[$crawler] ?? null)) {
                $this->error('Unknown crawler: '.$crawler);
                $failed = true;

                continue;
            }

            if (! $this->refresh((string) $crawler, $configured[$crawler])) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function refresh(string $crawler, array $definition): bool
    {
        $sources = array_values(array_filter(
            (array) ($definition['sources'] ?? []),
            static fn (mixed $source): bool => is_string($source) && $source !== '',
        ));

        if ($sources === []) {
            $this->error(sprintf('No %s crawler range sources are configured.', $crawler));

            return false;
        }

        $prefixes = [];
        $sourceMetadata = [];

        foreach ($sources as $source) {
            $this->line('Fetching '.$source);

            try {
                $response = Http::acceptJson()
                    ->timeout(max(1, (int) config('traffic_protection.crawler_ranges.refresh_timeout_seconds', 20)))
                    ->retry(
                        max(1, (int) config('traffic_protection.crawler_ranges.refresh_attempts', 3)),
                        500,
                        static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                        throw: false,
                    )
                    ->get($source);
            } catch (Throwable $exception) {
                $this->error('Failed to fetch '.$source.': '.$exception->getMessage());

                return false;
            }

            if (! $response->successful()) {
                $this->error(sprintf('Failed to fetch %s: HTTP %d', $source, $response->status()));

                return false;
            }

            $payload = $response->json();

            if (! is_array($payload) || ! is_array($payload['prefixes'] ?? null)) {
                $this->error(sprintf('Invalid %s crawler range payload from %s', $crawler, $source));

                return false;
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
            $this->error(sprintf('The %s crawler range refresh returned zero CIDR prefixes.', $crawler));

            return false;
        }

        $path = (string) ($definition['ranges_file'] ?? '');

        if ($path === '') {
            $this->error(sprintf('The %s crawler range file path is not configured.', $crawler));

            return false;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Could not create directory: '.$directory);

            return false;
        }

        try {
            $json = json_encode([
                'refreshed_at' => now()->toIso8601String(),
                'sources' => $sourceMetadata,
                'prefixes' => $prefixList,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (JsonException $exception) {
            $this->error(sprintf('Could not encode %s crawler ranges: %s', $crawler, $exception->getMessage()));

            return false;
        }

        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            $this->error(sprintf('Could not atomically write %s crawler range file: %s', $crawler, $path));

            return false;
        }

        $this->info(sprintf('Saved %d unique %s CIDR prefixes to %s', count($prefixList), $crawler, $path));

        if ($this->option('show-prefixes')) {
            foreach ($prefixList as $prefix) {
                $this->line($prefix);
            }
        }

        return true;
    }
}
