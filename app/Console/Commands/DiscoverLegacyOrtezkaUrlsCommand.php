<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seo\LegacyOrtezkaDiscoveryCrawler;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class DiscoverLegacyOrtezkaUrlsCommand extends Command
{
    /** @var array<int, string> */
    private const RECONCILIATION_MEDIA_EXTENSIONS = [
        'avif',
        'bmp',
        'gif',
        'ico',
        'jpeg',
        'jpg',
        'png',
        'svg',
        'webp',
    ];

    protected $signature = 'seo:legacy-discover
        {--start-url=https://ortezka.pl/ : Legacy storefront URL to crawl.}
        {--max-urls=50000 : Hard cap on unique same-site URLs admitted to the inventory.}
        {--max-sitemaps=100 : Hard cap on sitemap files processed.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts for network/429/5xx failures.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying.}
        {--request-delay-ms=250 : Milliseconds to pause before every request.}
        {--skip-sitemaps : Skip robots.txt and conventional sitemap discovery.}
        {--insecure : Disable TLS certificate verification for this discovery run.}
        {--no-progress : Suppress per-request progress output.}
        {--json : Print the complete discovery result as JSON.}
        {--checkpoint=scrapers/seo/ortezka/legacy-discovery.checkpoint.jsonl : Append-only recovery checkpoint under storage/app.}
        {--resume : Resume fetched/discovered URL state from an existing checkpoint.}
        {--sitemap-reconcile-only : Reconcile sitemap URLs against the checkpoint without recursively discovering new HTML links.}
        {--save=scrapers/seo/ortezka/legacy-inventory.json : JSON output path under storage/app.}
        {--csv=scrapers/seo/ortezka/legacy-inventory.csv : CSV output path under storage/app.}';

    protected $description = 'Build a read-only inventory of legacy Ortezka URLs for the old-to-new SEO migration map.';

    public function __construct(
        private readonly LegacyOrtezkaDiscoveryCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $insecure = (bool) $this->option('insecure');
        $resume = (bool) $this->option('resume');
        $sitemapReconcileOnly = (bool) $this->option('sitemap-reconcile-only');
        $checkpointPath = trim((string) $this->option('checkpoint'));

        $this->crawler
            ->withProgressCallback(null)
            ->withRecordCheckpointCallback(null)
            ->withResumeRecords([])
            ->withInitialReconciliationPrunedCounts(0, 0)
            ->withSitemapReconcileOnly($sitemapReconcileOnly)
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withAttempts($this->integerOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->integerOption('retry-delay-ms', 1500, 0))
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 250, 0));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        if (! $json) {
            $this->info('Building the read-only Ortezka legacy SEO URL inventory.');
            $this->line('Database writes: NO');
            $this->line('Redirect/runtime changes: NO');

            if ($sitemapReconcileOnly) {
                $this->line('Mode: sitemap reconciliation only (no recursive HTML-link discovery).');
            }

            if ($insecure) {
                $this->warn('TLS verification is disabled for this run. Do not use --insecure for the authoritative production crawl.');
            }
        }

        $checkpointHandle = null;

        if ($checkpointPath !== '') {
            $absoluteCheckpointPath = $this->storagePath($checkpointPath);
            $checkpointState = $resume
                ? $this->loadCheckpoint($absoluteCheckpointPath, $sitemapReconcileOnly)
                : ['records' => [], 'query_variants_pruned' => 0, 'media_urls_pruned' => 0];
            $resumeRecords = $checkpointState['records'];
            $this->crawler->withInitialReconciliationPrunedCounts(
                (int) $checkpointState['query_variants_pruned'],
                (int) $checkpointState['media_urls_pruned'],
            );

            if ($resumeRecords !== []) {
                if (! $json) {
                    $this->info('Resuming from '.count($resumeRecords).' checkpointed URL records retained in memory.');

                    if ($sitemapReconcileOnly) {
                        $this->line('Checkpoint query variants pruned before hydration: '.(int) $checkpointState['query_variants_pruned']);
                        $this->line('Checkpoint media URLs pruned before hydration: '.(int) $checkpointState['media_urls_pruned']);
                    }
                }

                $this->crawler->withResumeRecords($resumeRecords);
                unset($resumeRecords, $checkpointState);
                gc_collect_cycles();
            } elseif ($resume && ! $json) {
                $this->warn('No usable checkpoint records were found; starting a fresh crawl.');
            }

            unset($checkpointState);

            $checkpointHandle = fopen($absoluteCheckpointPath, $resume ? 'ab' : 'wb');

            if ($checkpointHandle === false) {
                throw new RuntimeException('Unable to open legacy SEO recovery checkpoint for writing.');
            }

            $checkpointWrites = 0;
            $this->crawler->withRecordCheckpointCallback(function (array $record) use ($checkpointHandle, &$checkpointWrites): void {
                $line = $this->encodeValue($record).PHP_EOL;

                if (fwrite($checkpointHandle, $line) === false) {
                    throw new RuntimeException('Unable to append legacy SEO recovery checkpoint.');
                }

                $checkpointWrites++;

                if ($checkpointWrites % 100 === 0) {
                    fflush($checkpointHandle);
                }
            });
        } elseif ($resume) {
            throw new RuntimeException('--resume requires a non-empty --checkpoint path.');
        }

        try {
            $result = $this->crawler->crawl(
                trim((string) $this->option('start-url')),
                $this->integerOption('max-urls', 50000, 1),
                ! (bool) $this->option('skip-sitemaps'),
                $this->integerOption('max-sitemaps', 100, 1),
            );
        } finally {
            if (is_resource($checkpointHandle)) {
                fflush($checkpointHandle);
                fclose($checkpointHandle);
            }
        }

        gc_collect_cycles();

        $savePath = trim((string) $this->option('save'));
        $csvPath = trim((string) $this->option('csv'));

        // CSV is written first because it is independently useful migration evidence
        // and is naturally streaming. A JSON export problem must not discard it.
        if ($csvPath !== '') {
            $this->writeCsv($csvPath, $result['urls'] ?? []);
        }

        if ($savePath !== '') {
            $this->writeJson($savePath, $result);
        }

        if ($json) {
            $handle = fopen('php://stdout', 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open stdout for legacy SEO JSON output.');
            }

            try {
                $this->streamJson($handle, $result);
                fwrite($handle, PHP_EOL);
            } finally {
                fclose($handle);
            }
        } else {
            $this->renderSummary($result);

            if ($savePath !== '') {
                $this->info('JSON inventory: storage/app/'.ltrim($savePath, '/'));
            }

            if ($csvPath !== '') {
                $this->info('CSV inventory: storage/app/'.ltrim($csvPath, '/'));
            }

            if ($checkpointPath !== '') {
                $this->info('Recovery checkpoint: storage/app/'.ltrim($checkpointPath, '/'));
            }
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        return (int) ($summary['html_pages'] ?? 0) > 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function renderSummary(array $result): void
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $this->newLine();
        $this->info('Legacy SEO discovery summary');
        $this->line('Unique URLs discovered: '.(int) ($summary['urls_discovered'] ?? 0));
        $this->line('URL cap reached: '.(($summary['url_limit_reached'] ?? false) ? 'YES' : 'NO'));
        $this->line('URLs fetched: '.(int) ($summary['urls_fetched'] ?? 0));
        $this->line('HTML pages: '.(int) ($summary['html_pages'] ?? 0));
        $this->line('Products: '.(int) ($summary['products'] ?? 0));
        $this->line('Categories: '.(int) ($summary['categories'] ?? 0));
        $this->line('Content pages: '.(int) ($summary['content_pages'] ?? 0));
        $this->line('Content assets/documents: '.(int) ($summary['assets'] ?? 0));
        $this->line('Media/image URLs: '.(int) ($summary['media_urls'] ?? 0));
        $this->line('Operational URLs (inventory only): '.(int) ($summary['operational_urls'] ?? 0));
        $this->line('Other URLs: '.(int) ($summary['other_urls'] ?? 0));
        $this->line('Query variants discovered: '.(int) ($summary['query_variants_discovered'] ?? 0));
        $this->line('Query variants fetched: '.(int) ($summary['query_variants_fetched'] ?? 0));
        $this->line('Existing redirects observed: '.(int) ($summary['redirects'] ?? 0));
        $this->line('HTTP 4xx observed: '.(int) ($summary['http_4xx'] ?? 0));
        $this->line('HTTP 5xx observed: '.(int) ($summary['http_5xx'] ?? 0));
        $this->line('Network failures: '.(int) ($summary['network_failures'] ?? 0));
        $this->line('Sitemaps available: '.(int) ($summary['sitemaps_available'] ?? 0));
        $this->line('Sitemap entries parsed: '.(int) ($summary['sitemap_entries_parsed'] ?? 0));
        $this->line('URLs discovered from sitemap: '.(int) ($summary['sitemap_urls_discovered'] ?? 0));
        $this->line('Reconciliation query variants pruned: '.(int) ($summary['reconciliation_query_variants_pruned'] ?? 0));
        $this->line('Reconciliation media URLs pruned: '.(int) ($summary['reconciliation_media_urls_pruned'] ?? 0));
        $this->line('Peak PHP memory: '.number_format(((int) ($summary['peak_memory_bytes'] ?? 0)) / 1048576, 1).' MiB');
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max($minimum, (int) $value) : $default;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $relativePath, array $data): void
    {
        $path = $this->storagePath($relativePath);
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open legacy SEO discovery JSON for writing.');
        }

        try {
            $this->streamJson($handle, $data);
            fwrite($handle, PHP_EOL);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stream the large top-level inventory instead of encoding the complete
     * 50k-record structure into a second in-memory JSON string.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $data
     */
    private function streamJson($handle, array $data): void
    {
        fwrite($handle, "{\n");
        $keys = array_keys($data);
        $lastKey = array_key_last($keys);

        foreach ($keys as $keyIndex => $key) {
            fwrite($handle, '  '.$this->encodeValue((string) $key).': ');
            $value = $data[$key];

            if ($key === 'urls' && is_array($value)) {
                fwrite($handle, "[\n");
                $lastRecord = array_key_last($value);

                foreach ($value as $recordIndex => $record) {
                    fwrite($handle, '    '.$this->encodeValue($record));
                    fwrite($handle, $recordIndex === $lastRecord ? "\n" : ",\n");
                }

                fwrite($handle, '  ]');
            } else {
                fwrite($handle, $this->encodeValue($value));
            }

            fwrite($handle, $keyIndex === $lastKey ? "\n" : ",\n");
        }

        fwrite($handle, '}');
    }

    /**
     * @param  array<int, mixed>  $records
     */
    private function writeCsv(string $relativePath, array $records): void
    {
        $path = $this->storagePath($relativePath);
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open legacy SEO discovery CSV for writing.');
        }

        try {
            fputcsv($handle, [
                'url',
                'path',
                'query',
                'type',
                'legacy_id',
                'fetched',
                'status',
                'redirect_target',
                'canonical',
                'title',
                'h1',
                'meta_robots',
                'index',
                'brand',
                'breadcrumbs',
                'discovery_methods',
                'discovered_from',
                'internal_link_count',
                'fetch_error',
            ]);

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                fputcsv($handle, [
                    $record['url'] ?? '',
                    $record['path'] ?? '',
                    $record['query'] ?? '',
                    $record['type'] ?? '',
                    $record['legacy_id'] ?? '',
                    ($record['fetched'] ?? false) ? '1' : '0',
                    $record['status'] ?? '',
                    $record['redirect_target'] ?? '',
                    $record['canonical'] ?? '',
                    $record['title'] ?? '',
                    $record['h1'] ?? '',
                    $record['meta_robots'] ?? '',
                    $record['index'] ?? '',
                    $record['brand'] ?? '',
                    implode(' > ', $this->stringList($record['breadcrumbs'] ?? [])),
                    implode('|', $this->stringList($record['discovery_methods'] ?? [])),
                    implode('|', $this->stringList($record['discovered_from'] ?? [])),
                    $record['internal_link_count'] ?? 0,
                    $record['fetch_error'] ?? '',
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    private function storagePath(string $relativePath): string
    {
        $relativePath = ltrim(trim($relativePath), '/');

        if ($relativePath === '') {
            throw new RuntimeException('Legacy SEO output path cannot be empty.');
        }

        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create legacy SEO output directory: '.$directory);
        }

        return $path;
    }

    private function encodeValue(mixed $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode legacy SEO discovery JSON.', previous: $exception);
        }
    }

    /**
     * Stream the append-only checkpoint and keep only the latest record for each
     * URL that is useful to the requested resume mode. In sitemap reconciliation
     * mode this pruning happens before records are hydrated into the main crawler
     * array, avoiding the 65k-record memory spike that previously exhausted the
     * 512 MiB PHP process before DomCrawler could parse the next page.
     *
     * @return array{
     *     records: array<string, array<string, mixed>>,
     *     query_variants_pruned: int,
     *     media_urls_pruned: int
     * }
     */
    private function loadCheckpoint(string $path, bool $sitemapReconcileOnly = false): array
    {
        if (! is_file($path)) {
            return [
                'records' => [],
                'query_variants_pruned' => 0,
                'media_urls_pruned' => 0,
            ];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open legacy SEO recovery checkpoint for reading.');
        }

        $records = [];
        $queryVariantPrunedUrls = [];
        $mediaPrunedUrls = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                try {
                    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    // A process killed during fwrite may leave one incomplete tail
                    // line. Ignore malformed checkpoint lines; all prior complete
                    // lines remain independently recoverable.
                    continue;
                }

                if (! is_array($record)) {
                    continue;
                }

                $url = $record['url'] ?? null;

                if (! is_string($url) || $url === '') {
                    continue;
                }

                if ($sitemapReconcileOnly) {
                    if ($this->checkpointRecordIsMedia($record, $url)) {
                        unset($records[$url]);
                        $mediaPrunedUrls[$url] = true;

                        continue;
                    }

                    if ($this->checkpointRecordIsNonSitemapQueryVariant($record)) {
                        unset($records[$url]);
                        $queryVariantPrunedUrls[$url] = true;

                        continue;
                    }
                }

                unset($queryVariantPrunedUrls[$url], $mediaPrunedUrls[$url]);
                $records[$url] = $record;
            }
        } finally {
            fclose($handle);
        }

        return [
            'records' => $records,
            'query_variants_pruned' => count($queryVariantPrunedUrls),
            'media_urls_pruned' => count($mediaPrunedUrls),
        ];
    }

    /** @param array<string, mixed> $record */
    private function checkpointRecordIsNonSitemapQueryVariant(array $record): bool
    {
        $query = $record['query'] ?? null;

        if (! is_string($query) || $query === '') {
            return false;
        }

        $methods = is_array($record['discovery_methods'] ?? null)
            ? $record['discovery_methods']
            : [];

        return ! in_array('sitemap', $methods, true);
    }

    /** @param array<string, mixed> $record */
    private function checkpointRecordIsMedia(array $record, string $url): bool
    {
        if (($record['type'] ?? null) === 'media') {
            return true;
        }

        $path = is_string($record['path'] ?? null)
            ? (string) $record['path']
            : (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::RECONCILIATION_MEDIA_EXTENSIONS, true);
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => is_scalar($entry) ? (string) $entry : '',
            $value,
        ), static fn (string $entry): bool => $entry !== ''));
    }
}
