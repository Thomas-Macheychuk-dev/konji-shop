<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class LegacyOrtezkaDiscoveryCrawler
{
    public const DEFAULT_START_URL = 'https://ortezka.pl/';

    /** @var array<int, string> */
    private const DEFAULT_SITEMAP_PATHS = [
        '/sitemap.xml',
        '/1_index_sitemap.xml',
        '/sitemap_index.xml',
    ];

    /** @var array<int, string> */
    private const CRAWLABLE_QUERY_KEYS = [
        'page',
        'p',
    ];

    /** @var array<int, string> */
    private const TRACKING_QUERY_KEYS = [
        'fbclid',
        'gclid',
        'msclkid',
    ];

    /** @var array<int, string> */
    private const CONTENT_ASSET_EXTENSIONS = [
        'csv',
        'doc',
        'docx',
        'ods',
        'odt',
        'pdf',
        'rtf',
        'xls',
        'xlsx',
        'zip',
    ];

    /** @var array<int, string> */
    private const MEDIA_ASSET_EXTENSIONS = [
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

    private ?Closure $progressCallback = null;

    private ?Closure $recordCheckpointCallback = null;

    /** @var array<string, array<string, mixed>> */
    private array $resumeRecords = [];

    private int $timeoutSeconds = 20;

    private int $attempts = 3;

    private int $retryDelayMilliseconds = 1500;

    private int $requestDelayMilliseconds = 250;

    private bool $verifyTls = true;

    private bool $sitemapReconcileOnly = false;

    /** @var array{query_variants: int, media_urls: int} */
    private array $reconciliationPruned = [
        'query_variants' => 0,
        'media_urls' => 0,
    ];

    /** @var array{query_variants: int, media_urls: int} */
    private array $initialReconciliationPruned = [
        'query_variants' => 0,
        'media_urls' => 0,
    ];

    public function withProgressCallback(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    public function withRecordCheckpointCallback(?Closure $callback): self
    {
        $this->recordCheckpointCallback = $callback;

        return $this;
    }

    /** @param array<string, array<string, mixed>> $records */
    public function withResumeRecords(array $records): self
    {
        $this->resumeRecords = $records;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    public function withAttempts(int $attempts): self
    {
        $this->attempts = max(1, $attempts);

        return $this;
    }

    public function withRetryDelayMilliseconds(int $milliseconds): self
    {
        $this->retryDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withTlsVerification(bool $verify): self
    {
        $this->verifyTls = $verify;

        return $this;
    }

    public function withSitemapReconcileOnly(bool $enabled): self
    {
        $this->sitemapReconcileOnly = $enabled;

        return $this;
    }

    public function withInitialReconciliationPrunedCounts(int $queryVariants, int $mediaUrls): self
    {
        $this->initialReconciliationPruned = [
            'query_variants' => max(0, $queryVariants),
            'media_urls' => max(0, $mediaUrls),
        ];

        return $this;
    }

    /**
     * Crawl the current Ortezka site without writing to the application database.
     *
     * @return array<string, mixed>
     */
    public function crawl(
        string $startUrl = self::DEFAULT_START_URL,
        int $maxUrls = 50000,
        bool $discoverSitemaps = true,
        int $maxSitemaps = 100,
    ): array {
        $startUrl = $this->normalizeStartUrl($startUrl);
        $canonicalHost = $this->canonicalHost($startUrl);
        $maxUrls = max(1, $maxUrls);
        $maxSitemaps = max(1, $maxSitemaps);

        /** @var array<string, array<string, mixed>> $records */
        $records = $this->resumeRecords;
        $this->resumeRecords = [];
        $this->reconciliationPruned = $this->initialReconciliationPruned;
        $this->initialReconciliationPruned = [
            'query_variants' => 0,
            'media_urls' => 0,
        ];

        if ($this->sitemapReconcileOnly && $records !== []) {
            $this->pruneResumeRecordsForSitemapReconciliation($records);
            gc_collect_cycles();
        }

        /** @var array<int, string> $queue */
        $queue = [];
        /** @var array<string, true> $queued */
        $queued = [];

        if (! $this->sitemapReconcileOnly) {
            foreach ($records as $url => $record) {
                if (($record['fetched'] ?? false) === true || ! $this->shouldFetchRecord($record)) {
                    continue;
                }

                $queued[$url] = true;
                $queue[] = $url;
            }
        }
        /** @var array<string, string> $networkFailures */
        $networkFailures = [];
        /** @var array<int, array<string, mixed>> $sitemaps */
        $sitemaps = [];
        /** @var array<int, string> $sitemapCandidates */
        $sitemapCandidates = [];

        if (! $this->sitemapReconcileOnly) {
            $this->discoverUrl(
                $startUrl,
                'start_url',
                null,
                $canonicalHost,
                $records,
                $queue,
                $queued,
                $maxUrls,
            );
        }

        if ($discoverSitemaps) {
            $sitemapCandidates = $this->discoverSitemapCandidates($startUrl, $canonicalHost, $networkFailures);
            $sitemapUrls = $this->crawlSitemaps(
                $sitemapCandidates,
                $canonicalHost,
                $records,
                $queue,
                $queued,
                $networkFailures,
                $maxUrls,
                $maxSitemaps,
                $sitemaps,
            );

            foreach ($sitemapUrls as $url) {
                $this->discoverUrl(
                    $url,
                    'sitemap',
                    null,
                    $canonicalHost,
                    $records,
                    $queue,
                    $queued,
                    $maxUrls,
                );
            }
        }

        $fetchedCount = 0;

        foreach ($records as $record) {
            if (($record['fetched'] ?? false) === true) {
                $fetchedCount++;
            }
        }

        while ($queue !== []) {
            $url = array_shift($queue);

            if (! is_string($url) || ! isset($records[$url])) {
                continue;
            }

            if (($records[$url]['fetched'] ?? false) === true) {
                continue;
            }

            if (! $this->shouldFetchRecord($records[$url])) {
                continue;
            }

            $this->emit(sprintf(
                'Fetching legacy URL %d/%d discovered: %s',
                $fetchedCount + 1,
                count($records),
                $url,
            ));

            $response = $this->fetch($url, $networkFailures);

            if ($response === null) {
                $records[$url]['fetch_error'] = $networkFailures[$url] ?? 'Unknown network failure';
                $this->checkpointRecord($records[$url]);

                continue;
            }

            $fetchedCount++;
            $records[$url]['fetched'] = true;
            $records[$url]['fetch_error'] = null;
            unset($networkFailures[$url]);
            $records[$url]['status'] = $response->status();
            $records[$url]['content_type'] = $this->contentType($response);

            if ($response->redirect()) {
                $location = trim((string) $response->header('Location'));
                $target = $location !== ''
                    ? $this->normalizeInternalUrl($location, $url, $canonicalHost)
                    : null;

                $records[$url]['redirect_target'] = $target;

                if ($target !== null) {
                    $this->discoverUrl(
                        $target,
                        'redirect',
                        $url,
                        $canonicalHost,
                        $records,
                        $queue,
                        $queued,
                        $maxUrls,
                    );
                }

                $this->checkpointRecord($records[$url]);

                continue;
            }

            if (! $response->successful() || ! $this->looksLikeHtml($response)) {
                $this->checkpointRecord($records[$url]);

                continue;
            }

            $records[$url]['is_html'] = true;
            $body = $response->body();
            unset($response);
            $metadata = $this->extractHtmlMetadata(
                $body,
                $url,
                $canonicalHost,
                ! $this->sitemapReconcileOnly,
            );
            unset($body);

            foreach ($metadata['metadata'] as $key => $value) {
                $records[$url][$key] = $value;
            }

            $records[$url]['internal_link_count'] = count($metadata['internal_links']);

            if (! $this->sitemapReconcileOnly) {
                foreach ($metadata['internal_links'] as $linkedUrl) {
                    $this->discoverUrl(
                        $linkedUrl,
                        'html_link',
                        $url,
                        $canonicalHost,
                        $records,
                        $queue,
                        $queued,
                        $maxUrls,
                    );
                }
            }

            $this->checkpointRecord($records[$url]);
        }

        // These structures are only needed while crawling. Release them before
        // sorting/materialising the final inventory so the exporter has as much
        // headroom as possible under the production-like 512 MiB PHP limit.
        unset($queue, $queued);

        ksort($records);
        ksort($networkFailures);

        $urlRecords = array_values($records);
        unset($records);

        gc_collect_cycles();

        return [
            'source' => 'ortezka-legacy',
            'generated_at' => now()->toIso8601String(),
            'database_writes' => false,
            'start_url' => $startUrl,
            'canonical_host' => $canonicalHost,
            'crawl_policy' => [
                'same_site_only' => true,
                'max_urls' => $maxUrls,
                'max_sitemaps' => $maxSitemaps,
                'sitemap_discovery_enabled' => $discoverSitemaps,
                'crawlable_query_keys' => self::CRAWLABLE_QUERY_KEYS,
                'tracking_query_parameters_removed' => array_merge(self::TRACKING_QUERY_KEYS, ['utm_*']),
                'content_assets_are_inventory_only' => true,
                'operational_urls_are_inventory_only' => true,
            ],
            'summary' => $this->summarize($urlRecords, $sitemaps, $networkFailures, $maxUrls),
            'sitemap_candidates' => $sitemapCandidates,
            'sitemaps' => $sitemaps,
            'urls' => $urlRecords,
            'network_failures' => $networkFailures,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<int, string>  $queue
     * @param  array<string, true>  $queued
     */
    private function discoverUrl(
        string $candidate,
        string $method,
        ?string $from,
        string $canonicalHost,
        array &$records,
        array &$queue,
        array &$queued,
        int $maxUrls,
    ): void {
        $url = $this->normalizeInternalUrl($candidate, self::DEFAULT_START_URL, $canonicalHost);

        if ($url === null) {
            return;
        }

        $changed = false;

        if (! isset($records[$url])) {
            if (count($records) >= $maxUrls) {
                return;
            }

            $parts = parse_url($url);
            $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
            $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';
            $classification = $this->classifyPath($path);

            $records[$url] = [
                'url' => $url,
                'path' => $path === '' ? '/' : $path,
                'query' => $query !== '' ? $query : null,
                'type' => $classification['type'],
                'legacy_id' => $classification['legacy_id'],
                'fetched' => false,
                'status' => null,
                'content_type' => null,
                'is_html' => false,
                'redirect_target' => null,
                'canonical' => null,
                'title' => null,
                'h1' => null,
                'meta_robots' => null,
                'index' => null,
                'brand' => null,
                'breadcrumbs' => [],
                'internal_link_count' => 0,
                'discovery_methods' => [],
                'discovered_from' => [],
                'fetch_error' => null,
            ];
            $changed = true;
        }

        if (! in_array($method, $records[$url]['discovery_methods'], true)) {
            $records[$url]['discovery_methods'][] = $method;
            $changed = true;
        }

        if ($from !== null && ! in_array($from, $records[$url]['discovered_from'], true)) {
            if (count($records[$url]['discovered_from']) < 5) {
                $records[$url]['discovered_from'][] = $from;
                $changed = true;
            }
        }

        if (! isset($queued[$url]) && ($records[$url]['fetched'] ?? false) !== true && $this->shouldFetchRecord($records[$url])) {
            $queued[$url] = true;
            $queue[] = $url;
        }

        if ($changed) {
            $this->checkpointRecord($records[$url]);
        }
    }

    /**
     * @param  array<int, string>  $candidates
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<int, string>  $queue
     * @param  array<string, true>  $queued
     * @param  array<string, string>  $networkFailures
     * @param  array<int, array<string, mixed>>  $sitemaps
     * @return array<int, string>
     */
    private function crawlSitemaps(
        array $candidates,
        string $canonicalHost,
        array &$records,
        array &$queue,
        array &$queued,
        array &$networkFailures,
        int $maxUrls,
        int $maxSitemaps,
        array &$sitemaps,
    ): array {
        $pending = $candidates;
        $seen = [];
        $pageUrls = [];

        while ($pending !== [] && count($seen) < $maxSitemaps) {
            $url = array_shift($pending);

            if (! is_string($url) || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $this->emit('Probing legacy sitemap: '.$url);
            $response = $this->fetch($url, $networkFailures, false);

            if ($response === null) {
                $sitemaps[] = [
                    'url' => $url,
                    'status' => null,
                    'type' => 'unavailable',
                    'entries' => 0,
                ];

                continue;
            }

            if (! $response->successful()) {
                $sitemaps[] = [
                    'url' => $url,
                    'status' => $response->status(),
                    'type' => 'unavailable',
                    'entries' => 0,
                ];

                continue;
            }

            $body = trim($response->body());
            $parsedSitemap = $this->parseSitemap($body);
            $sitemapType = $parsedSitemap['type'];
            $locations = $parsedSitemap['locations'];

            if ($sitemapType === null) {
                $sitemaps[] = [
                    'url' => $url,
                    'status' => $response->status(),
                    'type' => 'not_sitemap_xml',
                    'entries' => 0,
                ];

                continue;
            }

            $sitemaps[] = [
                'url' => $url,
                'status' => $response->status(),
                'type' => $sitemapType,
                'entries' => count($locations),
            ];

            if ($sitemapType === 'index') {
                foreach ($locations as $location) {
                    $normalized = $this->normalizeInternalUrl($location, $url, $canonicalHost);

                    if ($normalized !== null && ! isset($seen[$normalized])) {
                        $pending[] = $normalized;
                    }
                }

                continue;
            }

            foreach ($locations as $location) {
                $normalized = $this->normalizeInternalUrl($location, $url, $canonicalHost);

                if ($normalized === null) {
                    continue;
                }

                $pageUrls[$normalized] = true;
                $this->discoverUrl(
                    $normalized,
                    'sitemap',
                    $url,
                    $canonicalHost,
                    $records,
                    $queue,
                    $queued,
                    $maxUrls,
                );
            }
        }

        return array_keys($pageUrls);
    }

    /**
     * @param  array<string, string>  $networkFailures
     * @return array<int, string>
     */
    private function discoverSitemapCandidates(string $startUrl, string $canonicalHost, array &$networkFailures): array
    {
        $parts = parse_url($startUrl);
        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] : 'https';
        $origin = $scheme.'://'.$canonicalHost;
        $candidates = [];
        $robotsUrl = $origin.'/robots.txt';

        $this->emit('Probing legacy robots.txt: '.$robotsUrl);
        $robots = $this->fetch($robotsUrl, $networkFailures, false);

        if ($robots !== null && $robots->successful()) {
            foreach (preg_split('/\R/u', $robots->body()) ?: [] as $line) {
                if (! preg_match('/^\s*Sitemap\s*:\s*(\S+)\s*$/i', $line, $matches)) {
                    continue;
                }

                $normalized = $this->normalizeInternalUrl($matches[1], $robotsUrl, $canonicalHost);

                if ($normalized !== null) {
                    $candidates[$normalized] = true;
                }
            }
        }

        foreach (self::DEFAULT_SITEMAP_PATHS as $path) {
            $candidates[$origin.$path] = true;
        }

        return array_keys($candidates);
    }

    /**
     * @param  array<string, string>  $networkFailures
     */
    private function fetch(string $url, array &$networkFailures, bool $recordFailure = true): ?Response
    {
        $lastFailure = 'Unknown network failure';

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->pauseBeforeRequest();

            try {
                $response = Http::connectTimeout(min(10, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withOptions([
                        'allow_redirects' => false,
                        'verify' => $this->verifyTls,
                    ])
                    ->withHeaders($this->headers())
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();

                if ($attempt < $this->attempts) {
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                $lastFailure = 'HTTP '.$response->status();

                if ($attempt < $this->attempts) {
                    $this->pauseBeforeRetry();

                    continue;
                }
            }

            unset($networkFailures[$url]);

            return $response;
        }

        if ($recordFailure) {
            $networkFailures[$url] = $lastFailure;
        }

        return null;
    }

    /**
     * @return array{metadata: array<string, mixed>, internal_links: array<int, string>}
     */
    private function extractHtmlMetadata(
        string $html,
        string $pageUrl,
        string $canonicalHost,
        bool $extractInternalLinks = true,
    ): array {
        $crawler = new Crawler($html, $pageUrl);
        $links = [];

        if ($extractInternalLinks) {
            $crawler->filter('a[href]')->each(function (Crawler $link) use (&$links, $pageUrl, $canonicalHost): void {
                $href = $link->attr('href');

                if (! is_string($href)) {
                    return;
                }

                $url = $this->normalizeInternalUrl($href, $pageUrl, $canonicalHost);

                if ($url !== null) {
                    $links[$url] = true;
                }
            });
        }

        $canonical = $this->attributeFromFirst($crawler, 'link[rel="canonical"]', 'href');
        $canonical = $canonical !== null
            ? $this->normalizeInternalUrl($canonical, $pageUrl, $canonicalHost) ?? $canonical
            : null;

        return [
            'metadata' => [
                'canonical' => $canonical,
                'title' => $this->textFromFirst($crawler, 'title'),
                'h1' => $this->textFromFirst($crawler, 'h1'),
                'meta_robots' => $this->attributeFromFirst($crawler, 'meta[name="robots"]', 'content'),
                'index' => $this->extractProductIndex($crawler),
                'brand' => $this->extractBrand($crawler),
                'breadcrumbs' => $this->extractBreadcrumbs($crawler),
            ],
            'internal_links' => array_keys($links),
        ];
    }

    private function extractProductIndex(Crawler $crawler): ?string
    {
        foreach ([
            '[itemprop="sku"]',
            '.product-reference span',
            '.product-reference',
            '#product-reference',
            '[class*="product-reference"]',
        ] as $selector) {
            $value = $this->textFromFirst($crawler, $selector);
            $value = $value !== null ? $this->cleanIndexValue($value) : null;

            if ($value !== null) {
                return $value;
            }
        }

        $body = $this->normalizeText($crawler->filter('body')->first()->text(''));

        if (preg_match('/(?:^|\s)(?:Indeks|Reference|Kod(?:\s+produktu)?)\s*[:#]?\s*([\p{L}\p{N}][\p{L}\p{N}._\/-]{1,80})/ui', $body, $matches)) {
            return $this->cleanIndexValue($matches[1]);
        }

        return null;
    }

    private function extractBrand(Crawler $crawler): ?string
    {
        foreach ([
            '[itemprop="brand"]',
            '.product-manufacturer',
            '.manufacturer-name',
            '[class*="manufacturer"]',
        ] as $selector) {
            $value = $this->textFromFirst($crawler, $selector);

            if ($value !== null) {
                return $value;
            }
        }

        foreach ([
            'meta[property="product:brand"]',
            'meta[name="product:brand"]',
        ] as $selector) {
            $value = $this->attributeFromFirst($crawler, $selector, 'content');

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function extractBreadcrumbs(Crawler $crawler): array
    {
        $values = [];

        foreach ([
            'nav.breadcrumb a',
            '.breadcrumb a',
            '[itemprop="itemListElement"] [itemprop="name"]',
            '[itemprop="itemListElement"] a',
        ] as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $crawler->filter($selector)->each(function (Crawler $node) use (&$values): void {
                $value = $this->normalizeText($node->text(''));

                if ($value !== '' && ! in_array($value, $values, true)) {
                    $values[] = $value;
                }
            });

            if ($values !== []) {
                break;
            }
        }

        return $values;
    }

    private function textFromFirst(Crawler $crawler, string $selector): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        $value = $this->normalizeText($nodes->first()->text(''));

        return $value !== '' ? $value : null;
    }

    private function attributeFromFirst(Crawler $crawler, string $selector, string $attribute): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        $value = $nodes->first()->attr($attribute);
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function cleanIndexValue(string $value): ?string
    {
        $value = preg_replace('/^(?:Indeks|Reference|Kod(?:\s+produktu)?)\s*[:#]?\s*/ui', '', $value) ?? $value;
        $value = $this->normalizeText($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^([^\s,;|]{2,100})/u', $value, $matches)) {
            return $matches[1];
        }

        return mb_substr($value, 0, 100);
    }

    /**
     * @return array{type: string, legacy_id: int|null}
     */
    private function classifyPath(string $path): array
    {
        $normalized = '/'.ltrim($path, '/');
        $lower = mb_strtolower($normalized, 'UTF-8');

        if ($normalized === '/') {
            return ['type' => 'home', 'legacy_id' => null];
        }

        if ($this->isMediaAssetPath($lower)) {
            return ['type' => 'media', 'legacy_id' => null];
        }

        if ($this->isContentAssetPath($lower)) {
            return ['type' => 'asset', 'legacy_id' => null];
        }

        if (preg_match('#(?:^|/)content/(\d+)(?:[-/]|$)#i', $normalized, $matches)) {
            return ['type' => 'content', 'legacy_id' => (int) $matches[1]];
        }

        if (preg_match('#(?:^|/)[^/?]*-cat-(\d+)(?:/|$)#i', $normalized, $matches)) {
            return ['type' => 'category', 'legacy_id' => (int) $matches[1]];
        }

        if (preg_match('#(?:^|/)[^/?]*-id-(\d+)(?:/|$)#i', $normalized, $matches)) {
            return ['type' => 'product', 'legacy_id' => (int) $matches[1]];
        }

        if ($this->isOperationalPath($lower)) {
            return ['type' => 'operational', 'legacy_id' => null];
        }

        return ['type' => 'other', 'legacy_id' => null];
    }

    /** @param array<string, mixed> $record */
    private function shouldFetchRecord(array $record): bool
    {
        $path = is_string($record['path'] ?? null) ? (string) $record['path'] : '';

        if ($this->isMediaAssetPath($path) || in_array($record['type'] ?? null, ['asset', 'media', 'operational'], true)) {
            return false;
        }

        $query = $record['query'] ?? null;

        if (! is_string($query) || $query === '') {
            return true;
        }

        parse_str($query, $parameters);

        if ($parameters === []) {
            return true;
        }

        foreach (array_keys($parameters) as $key) {
            if (! in_array((string) $key, self::CRAWLABLE_QUERY_KEYS, true)) {
                return false;
            }
        }

        return true;
    }

    private function isOperationalPath(string $path): bool
    {
        foreach ([
            '/adres',
            '/addresses',
            '/authentication',
            '/cart',
            '/checkout',
            '/contact-form',
            '/guest-tracking',
            '/historia-zamowien',
            '/identity',
            '/login',
            '/logout',
            '/module/',
            '/order',
            '/password',
            '/search',
            '/szukaj',
            '/zamowienie',
        ] as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isMediaAssetPath(string $path): bool
    {
        $extension = mb_strtolower((string) pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION), 'UTF-8');

        return in_array($extension, self::MEDIA_ASSET_EXTENSIONS, true);
    }

    private function isContentAssetPath(string $path): bool
    {
        if (str_starts_with($path, '/dane/')) {
            return true;
        }

        $extension = mb_strtolower((string) pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION), 'UTF-8');

        return in_array($extension, self::CONTENT_ASSET_EXTENSIONS, true);
    }

    private function normalizeStartUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Legacy SEO start URL must be an absolute HTTP(S) URL.');
        }

        $scheme = mb_strtolower((string) $parts['scheme'], 'UTF-8');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Legacy SEO start URL must use HTTP or HTTPS.');
        }

        return $this->normalizeInternalUrl($url, $url, $this->canonicalHost($url))
            ?? throw new InvalidArgumentException('Legacy SEO start URL could not be normalized.');
    }

    private function canonicalHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            throw new InvalidArgumentException('Legacy SEO start URL must contain a host.');
        }

        $host = mb_strtolower(trim($host), 'UTF-8');

        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }

    private function normalizeInternalUrl(string $candidate, string $baseUrl, string $canonicalHost): ?string
    {
        $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($candidate === '' || str_starts_with($candidate, '#')) {
            return null;
        }

        foreach (['mailto:', 'tel:', 'javascript:', 'data:'] as $scheme) {
            if (str_starts_with(mb_strtolower($candidate, 'UTF-8'), $scheme)) {
                return null;
            }
        }

        $absolute = $this->resolveUrl($candidate, $baseUrl);
        $parts = parse_url($absolute);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host'], 'UTF-8');
        $normalizedHost = str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;

        if ($normalizedHost !== $canonicalHost) {
            return null;
        }

        $scheme = isset($parts['scheme']) && in_array(mb_strtolower((string) $parts['scheme'], 'UTF-8'), ['http', 'https'], true)
            ? mb_strtolower((string) $parts['scheme'], 'UTF-8')
            : 'https';
        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $query = isset($parts['query']) && is_string($parts['query']) ? $this->normalizeQuery($parts['query']) : '';

        return $scheme.'://'.$canonicalHost.$path.($query !== '' ? '?'.$query : '');
    }

    private function resolveUrl(string $candidate, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        $base = parse_url($baseUrl);
        $scheme = isset($base['scheme']) && is_string($base['scheme']) ? $base['scheme'] : 'https';
        $host = isset($base['host']) && is_string($base['host']) ? $base['host'] : '';

        if (str_starts_with($candidate, '//')) {
            return $scheme.':'.$candidate;
        }

        if (str_starts_with($candidate, '?')) {
            $basePath = isset($base['path']) && is_string($base['path']) && $base['path'] !== '' ? $base['path'] : '/';

            return $scheme.'://'.$host.$basePath.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return $scheme.'://'.$host.$candidate;
        }

        $basePath = isset($base['path']) && is_string($base['path']) ? $base['path'] : '/';
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath).'/';
        $path = $directory.$candidate;
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $scheme.'://'.$host.'/'.implode('/', $segments);
    }

    private function normalizeQuery(string $query): string
    {
        parse_str($query, $parameters);

        foreach (array_keys($parameters) as $key) {
            $normalized = mb_strtolower((string) $key, 'UTF-8');

            if (str_starts_with($normalized, 'utm_') || in_array($normalized, self::TRACKING_QUERY_KEYS, true)) {
                unset($parameters[$key]);
            }
        }

        if ($parameters === []) {
            return '';
        }

        ksort($parameters);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Parse sitemap XML without one document-wide capture expression. The live
     * Ortezka child sitemap is multi-megabyte and contains more than 11k <loc>
     * elements; capturing every location in one preg_match_all() can hit PCRE/JIT
     * limits and return zero matches. Incremental offset scanning keeps matching
     * bounded and does not require an additional PHP XML extension.
     *
     * @return array{type: 'index'|'urlset'|null, locations: array<int, string>}
     */
    private function parseSitemap(string $xml): array
    {
        if ($xml === '') {
            return ['type' => null, 'locations' => []];
        }

        $type = null;

        if (preg_match('/<(?:(?:[A-Za-z_][A-Za-z0-9_.-]*):)?(sitemapindex|urlset)\b[^>]*>/i', $xml, $rootMatch)) {
            $rootName = mb_strtolower((string) ($rootMatch[1] ?? ''), 'UTF-8');
            $type = $rootName === 'sitemapindex' ? 'index' : ($rootName === 'urlset' ? 'urlset' : null);
        }

        if ($type === null) {
            return ['type' => null, 'locations' => []];
        }

        $locations = [];
        $offset = 0;
        $xmlLength = strlen($xml);
        $openPattern = '/<(?:(?!(?:image|video|news):)(?:[A-Za-z_][A-Za-z0-9_.-]*):)?loc\b[^>]*>/i';
        $closePattern = '/<\/(?:(?!(?:image|video|news):)(?:[A-Za-z_][A-Za-z0-9_.-]*):)?loc\s*>/i';

        while ($offset < $xmlLength && preg_match($openPattern, $xml, $openMatch, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $openTag = (string) $openMatch[0][0];
            $openPosition = (int) $openMatch[0][1];
            $contentStart = $openPosition + strlen($openTag);

            if (preg_match($closePattern, $xml, $closeMatch, PREG_OFFSET_CAPTURE, $contentStart) !== 1) {
                break;
            }

            $closeTag = (string) $closeMatch[0][0];
            $closePosition = (int) $closeMatch[0][1];
            $rawValue = trim(substr($xml, $contentStart, $closePosition - $contentStart));

            if (str_starts_with($rawValue, '<![CDATA[') && str_ends_with($rawValue, ']]>')) {
                $rawValue = substr($rawValue, 9, -3);
            }

            $value = trim(html_entity_decode(strip_tags($rawValue), ENT_QUOTES | ENT_XML1, 'UTF-8'));

            if ($value !== '') {
                $locations[$value] = true;
            }

            $offset = $closePosition + strlen($closeTag);
        }

        return [
            'type' => $type,
            'locations' => array_keys($locations),
        ];
    }

    private function contentType(Response $response): ?string
    {
        $value = trim((string) $response->header('Content-Type'));

        return $value !== '' ? $value : null;
    }

    private function looksLikeHtml(Response $response): bool
    {
        $contentType = mb_strtolower((string) $response->header('Content-Type'), 'UTF-8');

        if ($contentType !== '') {
            return str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml');
        }

        $body = ltrim($response->body());

        return str_starts_with(mb_strtolower($body, 'UTF-8'), '<!doctype html')
            || str_starts_with(mb_strtolower($body, 'UTF-8'), '<html');
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, array<string, mixed>>  $sitemaps
     * @param  array<string, string>  $networkFailures
     * @return array<string, int|bool>
     */
    private function summarize(array $records, array $sitemaps, array $networkFailures, int $maxUrls): array
    {
        $summary = [
            'urls_discovered' => count($records),
            'url_limit_reached' => count($records) >= $maxUrls,
            'urls_fetched' => 0,
            'html_pages' => 0,
            'redirects' => 0,
            'products' => 0,
            'categories' => 0,
            'content_pages' => 0,
            'assets' => 0,
            'media_urls' => 0,
            'operational_urls' => 0,
            'other_urls' => 0,
            'query_variants_discovered' => 0,
            'query_variants_fetched' => 0,
            'http_4xx' => 0,
            'http_5xx' => 0,
            'network_failures' => count($networkFailures),
            'sitemaps_available' => 0,
            'sitemap_entries_parsed' => 0,
            'sitemap_urls_discovered' => 0,
            'reconciliation_query_variants_pruned' => $this->reconciliationPruned['query_variants'],
            'reconciliation_media_urls_pruned' => $this->reconciliationPruned['media_urls'],
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];

        foreach ($sitemaps as $sitemap) {
            if (($sitemap['type'] ?? 'unavailable') !== 'unavailable' && ($sitemap['type'] ?? null) !== 'not_sitemap_xml') {
                $summary['sitemaps_available']++;
                $summary['sitemap_entries_parsed'] += (int) ($sitemap['entries'] ?? 0);
            }
        }

        foreach ($records as $record) {
            if (in_array('sitemap', $record['discovery_methods'] ?? [], true)) {
                $summary['sitemap_urls_discovered']++;
            }

            $type = (string) ($record['type'] ?? 'other');

            match ($type) {
                'product' => $summary['products']++,
                'category' => $summary['categories']++,
                'content' => $summary['content_pages']++,
                'asset' => $summary['assets']++,
                'media' => $summary['media_urls']++,
                'operational' => $summary['operational_urls']++,
                default => $summary['other_urls']++,
            };

            if (($record['fetched'] ?? false) === true) {
                $summary['urls_fetched']++;
            }

            if (($record['query'] ?? null) !== null) {
                $summary['query_variants_discovered']++;

                if (($record['fetched'] ?? false) === true) {
                    $summary['query_variants_fetched']++;
                }
            }

            $status = $record['status'] ?? null;

            if (is_int($status) && $status >= 300 && $status < 400) {
                $summary['redirects']++;
            }

            if (is_int($status) && $status >= 400 && $status < 500) {
                $summary['http_4xx']++;
            }

            if (is_int($status) && $status >= 500) {
                $summary['http_5xx']++;
            }

            if (($record['fetched'] ?? false) === true
                && ($record['is_html'] ?? false) === true
                && is_int($status)
                && $status >= 200
                && $status < 300) {
                $summary['html_pages']++;
            }
        }

        return $summary;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.6',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopLegacySeoDiscovery/1.0; +https://ortezka.pl/)',
        ];
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function pauseBeforeRequest(): void
    {
        if ($this->requestDelayMilliseconds > 0) {
            usleep($this->requestDelayMilliseconds * 1000);
        }
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }

    /** @param array<string, mixed> $record */

    /**
     * Query variants and image/media URLs were useful during the original broad
     * discovery pass, but keeping tens of thousands of those records active
     * during sitemap reconciliation wastes memory and can consume the URL cap.
     * The append-only checkpoint remains the audit trail; reconciliation keeps
     * canonical/path-level records and sitemap-backed URLs in memory.
     *
     * @param  array<string, array<string, mixed>>  $records
     */
    private function pruneResumeRecordsForSitemapReconciliation(array &$records): void
    {
        foreach ($records as $url => $record) {
            $methods = is_array($record['discovery_methods'] ?? null) ? $record['discovery_methods'] : [];
            $fromSitemap = in_array('sitemap', $methods, true);
            $query = $record['query'] ?? null;
            $path = is_string($record['path'] ?? null)
                ? (string) $record['path']
                : (string) (parse_url($url, PHP_URL_PATH) ?: '');

            if ($this->isMediaAssetPath($path)) {
                unset($records[$url]);
                $this->reconciliationPruned['media_urls']++;

                continue;
            }

            if (! $fromSitemap && is_string($query) && $query !== '') {
                unset($records[$url]);
                $this->reconciliationPruned['query_variants']++;
            }
        }
    }

    private function checkpointRecord(array $record): void
    {
        if ($this->recordCheckpointCallback !== null) {
            ($this->recordCheckpointCallback)($record);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }
}
