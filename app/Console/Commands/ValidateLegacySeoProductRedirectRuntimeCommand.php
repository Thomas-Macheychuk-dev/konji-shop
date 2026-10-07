<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Security\HumanVerificationCookie;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request as StorefrontRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ValidateLegacySeoProductRedirectRuntimeCommand extends Command
{
    protected $signature = 'seo:validate-legacy-product-redirect-runtime
        {--manifest=resources/seo/ortezka/product-redirect-approvals.json : Repository-relative approved redirect manifest}
        {--base-url= : Absolute HTTP(S) base URL of the staging storefront}
        {--output=storage/app/seo/ortezka/legacy-product-redirect-runtime-validation.json : Repository-relative JSON evidence output}
        {--control-path=/robots.txt : Unrelated direct path that must remain a non-redirecting HTTP 200}
        {--timeout=15 : Per-request timeout in seconds}
        {--allow-noindex : Allow noindex responses only for explicitly isolated staging validation}';

    protected $description = 'Validate the enabled staging runtime for every approved legacy product redirect without changing redirect activation.';

    public function handle(): int
    {
        try {
            $manifestRelative = $this->safeRelativePath('manifest');
            $outputRelative = $this->safeRelativePath('output');
            $baseUrl = $this->validatedBaseUrl();
            $controlPath = $this->validatedControlPath();
            $timeout = $this->validatedTimeout();
            $allowNoindex = (bool) $this->option('allow-noindex');
            $manifestPath = base_path($manifestRelative);

            if (! is_file($manifestPath)) {
                throw new RuntimeException('Approved redirect manifest does not exist: '.$manifestRelative);
            }

            $rawManifest = file_get_contents($manifestPath);

            if (! is_string($rawManifest)) {
                throw new RuntimeException('Unable to read approved redirect manifest: '.$manifestRelative);
            }

            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($rawManifest, true, flags: JSON_THROW_ON_ERROR);
            $mappings = $this->approvedMappings($manifest);
            $humanVerificationCookie = $this->humanVerificationCookieForBaseUrl($baseUrl);

            $records = [];

            foreach ($mappings as $mapping) {
                $records[] = $this->validateMapping(
                    $baseUrl,
                    $mapping,
                    $timeout,
                    $humanVerificationCookie,
                );
            }

            $control = $this->validateControlPath(
                $baseUrl,
                $controlPath,
                $timeout,
                $humanVerificationCookie,
            );

            $summary = $this->summary($mappings, $records, $control);
            $result = $this->passes($summary, $allowNoindex) ? 'PASS' : 'FAIL';

            $report = [
                'schema_version' => 1,
                'purpose' => 'Validate the explicitly enabled staging Nginx runtime for the human-approved legacy Ortezka product redirect cohort.',
                'generated_at' => now()->toIso8601String(),
                'manifest' => $manifestRelative,
                'manifest_sha256' => hash('sha256', $rawManifest),
                'base_url' => $baseUrl,
                'allow_noindex' => $allowNoindex,
                'control_path' => $controlPath,
                'redirect_activation_changed_by_this_command' => false,
                'human_verification_cookie_used' => $humanVerificationCookie !== null,
                'human_verification_cookie_name' => $humanVerificationCookie['name'] ?? null,
                'summary' => $summary,
                'result' => $result,
                'control' => $control,
                'records' => $records,
            ];

            $this->writeReport($outputRelative, $report);
            $this->renderSummary(
                $summary,
                $result,
                $outputRelative,
                $humanVerificationCookie !== null,
            );

            return $result === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (JsonException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{source_path: string, target_product_id: string, target_product_name: string, target_path: string}>
     */
    private function approvedMappings(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) === 5) {
            return $this->approvedSchemaV5Mappings($manifest);
        }

        if (($manifest['schema_version'] ?? null) === 4) {
            return $this->approvedSchemaV4Mappings($manifest);
        }

        if (($manifest['schema_version'] ?? null) === 3) {
            return $this->approvedSchemaV3Mappings($manifest);
        }

        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported redirect approval manifest schema version.');
        }

        if (($manifest['redirects_installed'] ?? null) !== 0) {
            throw new RuntimeException('Approval manifest must remain evidence-only with redirects_installed=0.');
        }

        $records = $manifest['records'] ?? null;

        if (! is_array($records) || $records === []) {
            throw new RuntimeException('Approval manifest records must be a non-empty array.');
        }

        $mappings = [];
        $targets = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Approval manifest contains a non-object record.');
            }

            if (($record['approved'] ?? false) !== true || ($record['decision'] ?? null) !== 'APPROVE_301') {
                throw new RuntimeException('Approval manifest contains a record that is not explicitly APPROVE_301.');
            }

            if (($record['target_product_status'] ?? null) !== 'active' || ($record['matched_variant_status'] ?? null) !== 'active') {
                throw new RuntimeException('Approval manifest contains an inactive target product or matched variant.');
            }

            $targetProductId = $record['target_product_id'] ?? null;
            $targetProductName = $record['target_product_name'] ?? null;
            $targetPath = $record['target_path'] ?? null;
            $sources = $record['source_paths'] ?? null;

            if (! is_string($targetProductId) || trim($targetProductId) === '') {
                throw new RuntimeException('Every approved target must contain target_product_id.');
            }

            if (! is_string($targetProductName) || trim($targetProductName) === '') {
                throw new RuntimeException('Every approved target must contain target_product_name.');
            }

            if (! is_string($targetPath) || ! str_starts_with($targetPath, '/products/')) {
                throw new RuntimeException('Every approved target must be a /products/... path.');
            }

            $this->validatePath($targetPath, 'target');

            if (isset($targets[$targetPath]) && $targets[$targetPath] !== $targetProductId) {
                throw new RuntimeException('Conflicting approved target path: '.$targetPath);
            }

            $targets[$targetPath] = $targetProductId;

            if (! is_array($sources) || $sources === []) {
                throw new RuntimeException('Every approved redirect record must contain at least one source path.');
            }

            foreach ($sources as $sourcePath) {
                if (! is_string($sourcePath)) {
                    throw new RuntimeException('Approved redirect source paths must be strings.');
                }

                $this->validatePath($sourcePath, 'source');

                if ($sourcePath === $targetPath) {
                    throw new RuntimeException('Approved source path must not equal its target path: '.$sourcePath);
                }

                if (isset($mappings[$sourcePath])) {
                    throw new RuntimeException('Duplicate/conflicting approved redirect source path: '.$sourcePath);
                }

                $mappings[$sourcePath] = [
                    'source_path' => $sourcePath,
                    'target_product_id' => $targetProductId,
                    'target_product_name' => $targetProductName,
                    'target_path' => $targetPath,
                ];
            }
        }

        if (($manifest['approved_product_count'] ?? null) !== count($targets)) {
            throw new RuntimeException('approved_product_count does not match unique approved target records.');
        }

        if (($manifest['approved_source_path_count'] ?? null) !== count($mappings)) {
            throw new RuntimeException('approved_source_path_count does not match unique approved source paths.');
        }

        ksort($mappings, SORT_STRING);

        return array_values($mappings);
    }

    /**
     * Resolve all 64 approved schema-v3 source mappings.
     *
     * The shared generator gate performs approval, provenance,
     * identity, collision and redirect-chain validation first.
     *
     * No Nginx configuration is generated by this method.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{
     *     source_path: string,
     *     target_product_id: string,
     *     target_product_name: string,
     *     target_path: string
     * }>
     */
    private function approvedSchemaV3Mappings(array $manifest): array
    {
        $records = app(
            GenerateLegacySeoProductRedirectMapCommand::class,
        )->validatedSchemaV3Records($manifest);

        $mappings = [];
        $targets = [];

        foreach ($records as $record) {
            $productId = $record['target_product_id'] ?? null;
            $productName = $record['target_product_name'] ?? null;
            $targetPath = $record['target_path'] ?? null;
            $sourcePaths = $record['source_paths'] ?? null;

            if ((! is_string($productId) && ! is_int($productId))
                || trim((string) $productId) === '') {
                throw new RuntimeException(
                    'Invalid schema v3 target product ID.',
                );
            }

            if (! is_string($productName)
                || trim($productName) === '') {
                throw new RuntimeException(
                    'Invalid schema v3 target product name.',
                );
            }

            if (! is_string($targetPath)
                || ! str_starts_with($targetPath, '/products/')) {
                throw new RuntimeException(
                    'Invalid schema v3 target path.',
                );
            }

            $this->validatePath($targetPath, 'target');

            if (isset($targets[$targetPath])) {
                throw new RuntimeException(
                    'Duplicate schema v3 target: '.$targetPath,
                );
            }

            $targets[$targetPath] = true;

            if (! is_array($sourcePaths) || $sourcePaths === []) {
                throw new RuntimeException(
                    'Missing schema v3 source paths.',
                );
            }

            foreach ($sourcePaths as $sourcePath) {
                if (! is_string($sourcePath)) {
                    throw new RuntimeException(
                        'Invalid schema v3 source path type.',
                    );
                }

                $this->validatePath($sourcePath, 'source');

                if ($sourcePath === $targetPath
                    || isset($mappings[$sourcePath])) {
                    throw new RuntimeException(
                        'Duplicate or looping schema v3 source: '.$sourcePath,
                    );
                }

                $mappings[$sourcePath] = [
                    'source_path' => $sourcePath,
                    'target_product_id' => (string) $productId,
                    'target_product_name' => $productName,
                    'target_path' => $targetPath,
                ];
            }
        }

        if (count($targets) !== 45 || count($mappings) !== 64) {
            throw new RuntimeException(
                'Schema v3 runtime validation requires 45 products and 64 ledger-approved source paths.',
            );
        }

        ksort($mappings, SORT_STRING);

        return array_values($mappings);
    }

    private function approvedSchemaV5Mappings(array $manifest): array
    {
        $records = app(
            GenerateLegacySeoProductRedirectMapCommand::class,
        )->validatedSchemaV5Records($manifest);

        $mappings = [];
        $targets = [];

        foreach ($records as $record) {
            $productId = $record['target_product_id'] ?? null;
            $productName = $record['target_product_name'] ?? null;
            $targetPath = $record['target_path'] ?? null;
            $sourcePaths = $record['source_paths'] ?? null;

            if ((! is_string($productId) && ! is_int($productId))
                || trim((string) $productId) === '') {
                throw new RuntimeException(
                    'Invalid schema v5 target product ID.',
                );
            }

            if (! is_string($productName)
                || trim($productName) === '') {
                throw new RuntimeException(
                    'Invalid schema v5 target product name.',
                );
            }

            if (! is_string($targetPath)
                || ! str_starts_with($targetPath, '/products/')) {
                throw new RuntimeException(
                    'Invalid schema v5 target path.',
                );
            }

            $this->validatePath($targetPath, 'target');

            if (isset($targets[$targetPath])) {
                throw new RuntimeException(
                    'Duplicate schema v5 target: '.$targetPath,
                );
            }

            $targets[$targetPath] = true;

            if (! is_array($sourcePaths) || $sourcePaths === []) {
                throw new RuntimeException(
                    'Missing schema v5 source paths.',
                );
            }

            foreach ($sourcePaths as $sourcePath) {
                if (! is_string($sourcePath)) {
                    throw new RuntimeException(
                        'Invalid schema v5 source path type.',
                    );
                }

                $this->validatePath($sourcePath, 'source');

                if ($sourcePath === $targetPath
                    || isset($mappings[$sourcePath])) {
                    throw new RuntimeException(
                        'Duplicate or looping schema v5 source: '.$sourcePath,
                    );
                }

                $mappings[$sourcePath] = [
                    'source_path' => $sourcePath,
                    'target_product_id' => (string) $productId,
                    'target_product_name' => $productName,
                    'target_path' => $targetPath,
                ];
            }
        }

        if (count($targets) !== 415 || count($mappings) !== 659) {
            throw new RuntimeException(
                'Schema v4 runtime validation requires 415 products and 659 owner-approved source paths.',
            );
        }

        ksort($mappings, SORT_STRING);

        return array_values($mappings);
    }


    private function approvedSchemaV4Mappings(array $manifest): array
    {
        $records = app(
            GenerateLegacySeoProductRedirectMapCommand::class,
        )->validatedSchemaV4Records($manifest);

        $mappings = [];
        $targets = [];

        foreach ($records as $record) {
            $productId = $record['target_product_id'] ?? null;
            $productName = $record['target_product_name'] ?? null;
            $targetPath = $record['target_path'] ?? null;
            $sourcePaths = $record['source_paths'] ?? null;

            if ((! is_string($productId) && ! is_int($productId))
                || trim((string) $productId) === '') {
                throw new RuntimeException(
                    'Invalid schema v4 target product ID.',
                );
            }

            if (! is_string($productName)
                || trim($productName) === '') {
                throw new RuntimeException(
                    'Invalid schema v4 target product name.',
                );
            }

            if (! is_string($targetPath)
                || ! str_starts_with($targetPath, '/products/')) {
                throw new RuntimeException(
                    'Invalid schema v4 target path.',
                );
            }

            $this->validatePath($targetPath, 'target');

            if (isset($targets[$targetPath])) {
                throw new RuntimeException(
                    'Duplicate schema v4 target: '.$targetPath,
                );
            }

            $targets[$targetPath] = true;

            if (! is_array($sourcePaths) || $sourcePaths === []) {
                throw new RuntimeException(
                    'Missing schema v4 source paths.',
                );
            }

            foreach ($sourcePaths as $sourcePath) {
                if (! is_string($sourcePath)) {
                    throw new RuntimeException(
                        'Invalid schema v4 source path type.',
                    );
                }

                $this->validatePath($sourcePath, 'source');

                if ($sourcePath === $targetPath
                    || isset($mappings[$sourcePath])) {
                    throw new RuntimeException(
                        'Duplicate or looping schema v4 source: '.$sourcePath,
                    );
                }

                $mappings[$sourcePath] = [
                    'source_path' => $sourcePath,
                    'target_product_id' => (string) $productId,
                    'target_product_name' => $productName,
                    'target_path' => $targetPath,
                ];
            }
        }

        if (count($targets) !== 266 || count($mappings) !== 400) {
            throw new RuntimeException(
                'Schema v4 runtime validation requires 266 products and 400 owner-approved source paths.',
            );
        }

        ksort($mappings, SORT_STRING);

        return array_values($mappings);
    }

    /**
     * @param  array{source_path: string, target_product_id: string, target_product_name: string, target_path: string}  $mapping
     * @param  array{name: string, value: string, domain: string}|null  $humanVerificationCookie
     * @return array<string, mixed>
     */
    private function validateMapping(
        string $baseUrl,
        array $mapping,
        int $timeout,
        ?array $humanVerificationCookie,
    ): array {
        $sourceUrl = $baseUrl.$mapping['source_path'];
        $probeUrl = $sourceUrl.'?__konji_seo_redirect_probe=1';
        $expectedTargetUrl = $baseUrl.$mapping['target_path'];

        $record = [
            ...$mapping,
            'source_url' => $sourceUrl,
            'probe_url' => $probeUrl,
            'expected_target_url' => $expectedTargetUrl,
            'source_http_status' => null,
            'source_location' => null,
            'source_redirect_correct' => false,
            'query_string_dropped' => false,
            'target_http_status' => null,
            'target_location' => null,
            'target_canonical' => null,
            'target_canonical_correct' => false,
            'target_indexable' => false,
            'target_identity_correct' => false,
            'observed_h1' => null,
            'redirect_chain' => false,
            'redirect_loop' => false,
            'failures' => [],
        ];

        try {
            $sourceResponse = $this->request($timeout, $humanVerificationCookie)->get($probeUrl);
        } catch (Throwable $exception) {
            $record['failures'][] = 'source_request_failed: '.$exception->getMessage();

            return $record;
        }

        $record['source_http_status'] = $sourceResponse->status();
        $record['source_location'] = $this->headerOrNull($sourceResponse->header('Location'));

        if ($sourceResponse->status() !== 301) {
            $record['failures'][] = 'expected_source_http_301';
        }

        $location = $record['source_location'];

        if (is_string($location) && $location !== '') {
            $absoluteLocation = $this->absoluteUrl($baseUrl, $location);
            $record['source_redirect_correct'] = $this->normalizeComparableUrl($absoluteLocation)
                === $this->normalizeComparableUrl($expectedTargetUrl);
            $record['query_string_dropped'] = parse_url($absoluteLocation, PHP_URL_QUERY) === null;
            $record['redirect_loop'] = $this->normalizeComparableUrl($absoluteLocation)
                === $this->normalizeComparableUrl($sourceUrl);
        }

        if (! $record['source_redirect_correct']) {
            $record['failures'][] = 'wrong_redirect_destination';
        }

        if (! $record['query_string_dropped']) {
            $record['failures'][] = 'query_string_not_dropped';
        }

        if ($record['redirect_loop']) {
            $record['failures'][] = 'redirect_loop';
        }

        if ($sourceResponse->status() !== 301 || ! $record['source_redirect_correct']) {
            return $record;
        }

        try {
            $targetResponse = $this->request($timeout, $humanVerificationCookie)->get($expectedTargetUrl);
        } catch (Throwable $exception) {
            $record['failures'][] = 'target_request_failed: '.$exception->getMessage();

            return $record;
        }

        $record['target_http_status'] = $targetResponse->status();
        $record['target_location'] = $this->headerOrNull($targetResponse->header('Location'));

        if ($targetResponse->status() >= 300 && $targetResponse->status() < 400) {
            $record['redirect_chain'] = true;
            $targetLocation = $record['target_location'];

            if (is_string($targetLocation) && $targetLocation !== '') {
                $absoluteTargetLocation = $this->absoluteUrl($baseUrl, $targetLocation);
                $record['redirect_loop'] = $record['redirect_loop']
                    || $this->normalizeComparableUrl($absoluteTargetLocation) === $this->normalizeComparableUrl($sourceUrl)
                    || $this->normalizeComparableUrl($absoluteTargetLocation) === $this->normalizeComparableUrl($expectedTargetUrl);
            }
        }

        if ($targetResponse->status() !== 200) {
            $record['failures'][] = 'expected_target_http_200';

            if ($record['redirect_chain']) {
                $record['failures'][] = 'redirect_chain';
            }

            if ($record['redirect_loop'] && ! in_array('redirect_loop', $record['failures'], true)) {
                $record['failures'][] = 'redirect_loop';
            }

            return $record;
        }

        $crawler = $this->utf8HtmlCrawler(
            $targetResponse->body(),
            $expectedTargetUrl,
        );
        $canonical = $this->canonicalHref($crawler);
        $record['target_canonical'] = $canonical;
        $record['target_canonical_correct'] = $canonical !== null
            && $this->normalizeComparableUrl($this->absoluteUrl($baseUrl, $canonical))
                === $this->normalizeComparableUrl($expectedTargetUrl);

        if (! $record['target_canonical_correct']) {
            $record['failures'][] = 'canonical_mismatch';
        }

        $record['target_indexable'] = ! $this->containsNoindex($crawler, $targetResponse->header('X-Robots-Tag'));

        if (! $record['target_indexable']) {
            $record['failures'][] = 'noindex';
        }

        $observedH1 = $this->firstH1($crawler);
        $record['observed_h1'] = $observedH1;
        $record['target_identity_correct'] = $observedH1 !== null
            && $this->normalizeText($observedH1) === $this->normalizeText($mapping['target_product_name']);

        if (! $record['target_identity_correct']) {
            $record['failures'][] = 'product_identity_mismatch';
        }

        return $record;
    }

    /**
     * @param  array{name: string, value: string, domain: string}|null  $humanVerificationCookie
     * @return array<string, mixed>
     */
    private function validateControlPath(
        string $baseUrl,
        string $controlPath,
        int $timeout,
        ?array $humanVerificationCookie,
    ): array {
        $url = $baseUrl.$controlPath.'?__konji_seo_redirect_control=1';
        $record = [
            'path' => $controlPath,
            'url' => $url,
            'http_status' => null,
            'location' => null,
            'unchanged' => false,
            'failures' => [],
        ];

        try {
            $response = $this->request($timeout, $humanVerificationCookie)->get($url);
        } catch (Throwable $exception) {
            $record['failures'][] = 'control_request_failed: '.$exception->getMessage();

            return $record;
        }

        $record['http_status'] = $response->status();
        $record['location'] = $this->headerOrNull($response->header('Location'));
        $record['unchanged'] = $response->status() === 200 && $record['location'] === null;

        if (! $record['unchanged']) {
            $record['failures'][] = 'unrelated_control_path_changed';
        }

        return $record;
    }

    /**
     * @param  array{name: string, value: string, domain: string}|null  $humanVerificationCookie
     */
    private function request(int $timeout, ?array $humanVerificationCookie): PendingRequest
    {
        $request = Http::withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
            'User-Agent' => 'KonjiShop-SEO-Redirect-Runtime-Validator/1.0',
        ])->withOptions([
            'allow_redirects' => false,
        ]);

        if ($humanVerificationCookie !== null) {
            $request = $request->withCookies(
                [$humanVerificationCookie['name'] => $humanVerificationCookie['value']],
                $humanVerificationCookie['domain'],
            );
        }

        return $request->timeout($timeout);
    }

    private function headerOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }


    private function utf8HtmlCrawler(string $html, string $url): Crawler
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new RuntimeException(
                'Storefront HTML response is not valid UTF-8: '.$url
            );
        }

        /*
         * Symfony 8 DomCrawler uses the PHP HTML5 DOM parser.
         *
         * On the current PHP/Symfony production-compatible stack, a real
         * valid UTF-8 storefront response can lose a UTF-8 lead byte while
         * crossing that parser boundary.
         *
         * Convert every non-ASCII Unicode code point to a numeric HTML
         * entity first. The HTML presented to legacy DOMDocument is then
         * ASCII-safe, while DOM decoding restores the original Unicode
         * text values.
         */
        $asciiSafeHtml = mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8',
        );

        $document = new \DOMDocument('1.0', 'UTF-8');

        $previousInternalErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                $asciiSafeHtml,
                LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors(
                $previousInternalErrors,
            );
        }

        if ($loaded !== true) {
            throw new RuntimeException(
                'Unable to parse storefront HTML response: '.$url
            );
        }

        return new Crawler($document, $url);
    }

    private function canonicalHref(Crawler $crawler): ?string
    {
        $nodes = $crawler->filterXPath(
            "//link[contains(concat(' ', normalize-space(translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')), ' '), ' canonical ')]"
        );

        if ($nodes->count() !== 1) {
            return null;
        }

        $href = trim((string) $nodes->first()->attr('href'));

        return $href !== '' ? $href : null;
    }

    private function firstH1(Crawler $crawler): ?string
    {
        $nodes = $crawler->filter('h1');

        if ($nodes->count() === 0) {
            return null;
        }

        $text = trim($nodes->first()->text('', true));

        return $text !== '' ? $text : null;
    }

    private function containsNoindex(Crawler $crawler, ?string $xRobotsTag): bool
    {
        if (is_string($xRobotsTag) && $this->robotsValueContainsNoindex($xRobotsTag)) {
            return true;
        }

        $nodes = $crawler->filterXPath(
            "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='robots' or translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='googlebot']"
        );

        foreach ($nodes as $node) {
            $content = $node->attributes?->getNamedItem('content')?->nodeValue;

            if (is_string($content) && $this->robotsValueContainsNoindex($content)) {
                return true;
            }
        }

        return false;
    }

    private function robotsValueContainsNoindex(string $value): bool
    {
        return preg_match('/(?:^|[\s,;])noindex(?:$|[\s,;])/i', $value) === 1;
    }

    private function absoluteUrl(string $baseUrl, string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);

            return $scheme.':'.$url;
        }

        return $baseUrl.'/'.ltrim($url, '/');
    }

    private function normalizeComparableUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;
        $portSuffix = ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)))
            ? ':'.$port
            : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$host.$portSuffix.$path;
    }

    private function normalizeText(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }

    /**
     * @param  list<array{source_path: string, target_product_id: string, target_product_name: string, target_path: string}>  $mappings
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $control
     * @return array<string, int>
     */
    private function summary(array $mappings, array $records, array $control): array
    {
        $source301 = 0;
        $correctDestinations = 0;
        $queryStringsDropped = 0;
        $target200 = 0;
        $canonicalCorrect = 0;
        $indexable = 0;
        $identityCorrect = 0;
        $wrongDestinations = 0;
        $missingLocations = 0;
        $redirectChains = 0;
        $redirectLoops = 0;
        $queryStringsPreserved = 0;
        $sourceRequestFailures = 0;
        $targetRequestFailures = 0;
        $otherSourceStatuses = 0;
        $otherTargetStatuses = 0;
        $canonicalMismatches = 0;
        $noindexTargets = 0;
        $identityMismatches = 0;

        foreach ($records as $record) {
            $sourceStatus = $record['source_http_status'];
            $targetStatus = $record['target_http_status'];

            if ($sourceStatus === 301) {
                $source301++;
            } elseif ($sourceStatus === null) {
                $sourceRequestFailures++;
            } else {
                $otherSourceStatuses++;
            }

            if (($record['source_location'] ?? null) === null) {
                $missingLocations++;
            }

            if (($record['source_redirect_correct'] ?? false) === true) {
                $correctDestinations++;
            } elseif ($sourceStatus !== null) {
                $wrongDestinations++;
            }

            if (($record['query_string_dropped'] ?? false) === true) {
                $queryStringsDropped++;
            } elseif (($record['source_location'] ?? null) !== null) {
                $queryStringsPreserved++;
            }

            if ($targetStatus === 200) {
                $target200++;
            } elseif ($targetStatus === null) {
                if ($sourceStatus === 301 && ($record['source_redirect_correct'] ?? false) === true) {
                    $targetRequestFailures++;
                }
            } else {
                $otherTargetStatuses++;
            }

            if (($record['redirect_chain'] ?? false) === true) {
                $redirectChains++;
            }

            if (($record['redirect_loop'] ?? false) === true) {
                $redirectLoops++;
            }

            if (($record['target_canonical_correct'] ?? false) === true) {
                $canonicalCorrect++;
            } elseif ($targetStatus === 200) {
                $canonicalMismatches++;
            }

            if (($record['target_indexable'] ?? false) === true) {
                $indexable++;
            } elseif ($targetStatus === 200) {
                $noindexTargets++;
            }

            if (($record['target_identity_correct'] ?? false) === true) {
                $identityCorrect++;
            } elseif ($targetStatus === 200) {
                $identityMismatches++;
            }
        }

        return [
            'approved_source_paths' => count($mappings),
            'source_http_301' => $source301,
            'correct_destinations' => $correctDestinations,
            'query_strings_dropped' => $queryStringsDropped,
            'target_http_200' => $target200,
            'canonical_correct' => $canonicalCorrect,
            'indexable' => $indexable,
            'product_identity_correct' => $identityCorrect,
            'wrong_destinations' => $wrongDestinations,
            'missing_location_headers' => $missingLocations,
            'redirect_chains' => $redirectChains,
            'redirect_loops' => $redirectLoops,
            'query_strings_preserved' => $queryStringsPreserved,
            'source_request_failures' => $sourceRequestFailures,
            'target_request_failures' => $targetRequestFailures,
            'other_source_statuses' => $otherSourceStatuses,
            'other_target_statuses' => $otherTargetStatuses,
            'canonical_mismatches' => $canonicalMismatches,
            'noindex_targets' => $noindexTargets,
            'identity_mismatches' => $identityMismatches,
            'unrelated_control_failures' => ($control['unchanged'] ?? false) === true ? 0 : 1,
            'duplicate_or_conflicting_sources' => 0,
        ];
    }

    /** @param array<string, int> $summary */
    private function passes(array $summary, bool $allowNoindex): bool
    {
        $expected = $summary['approved_source_paths'];

        return $expected > 0
            && $summary['source_http_301'] === $expected
            && $summary['correct_destinations'] === $expected
            && $summary['query_strings_dropped'] === $expected
            && $summary['target_http_200'] === $expected
            && $summary['canonical_correct'] === $expected
            && ($allowNoindex || $summary['indexable'] === $expected)
            && $summary['product_identity_correct'] === $expected
            && $summary['wrong_destinations'] === 0
            && $summary['missing_location_headers'] === 0
            && $summary['redirect_chains'] === 0
            && $summary['redirect_loops'] === 0
            && $summary['query_strings_preserved'] === 0
            && $summary['source_request_failures'] === 0
            && $summary['target_request_failures'] === 0
            && $summary['other_source_statuses'] === 0
            && $summary['other_target_statuses'] === 0
            && $summary['canonical_mismatches'] === 0
            && ($allowNoindex || $summary['noindex_targets'] === 0)
            && $summary['identity_mismatches'] === 0
            && $summary['unrelated_control_failures'] === 0
            && $summary['duplicate_or_conflicting_sources'] === 0;
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $outputRelative, array $report): void
    {
        $outputPath = base_path($outputRelative);
        $directory = dirname($outputPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create redirect-runtime validation evidence directory: '.$directory);
        }

        try {
            $json = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode redirect-runtime validation report: '.$exception->getMessage(), previous: $exception);
        }

        $tmp = $outputPath.'.tmp';

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary redirect-runtime validation report: '.$tmp);
        }

        if (! rename($tmp, $outputPath)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to atomically publish redirect-runtime validation report: '.$outputRelative);
        }
    }

    /** @param array<string, int> $summary */
    private function renderSummary(
        array $summary,
        string $result,
        string $outputRelative,
        bool $humanVerificationCookieUsed,
    ): void {
        $this->newLine();
        $this->line('Approved legacy source paths:   '.$summary['approved_source_paths']);
        $this->newLine();
        $this->line('Source HTTP 301:                 '.$summary['source_http_301']);
        $this->line('Correct destinations:            '.$summary['correct_destinations']);
        $this->line('Query strings dropped:           '.$summary['query_strings_dropped']);
        $this->line('Final target HTTP 200:            '.$summary['target_http_200']);
        $this->line('Canonical correct:                '.$summary['canonical_correct']);
        $this->line('Indexable:                        '.$summary['indexable']);
        $this->line('Product identity correct:         '.$summary['product_identity_correct']);
        $this->newLine();
        $this->line('Wrong destinations:               '.$summary['wrong_destinations']);
        $this->line('Missing Location headers:         '.$summary['missing_location_headers']);
        $this->line('Redirect chains:                  '.$summary['redirect_chains']);
        $this->line('Redirect loops:                   '.$summary['redirect_loops']);
        $this->line('Query strings preserved:          '.$summary['query_strings_preserved']);
        $this->line('Source request failures:          '.$summary['source_request_failures']);
        $this->line('Target request failures:          '.$summary['target_request_failures']);
        $this->line('Other source statuses:            '.$summary['other_source_statuses']);
        $this->line('Other target statuses:            '.$summary['other_target_statuses']);
        $this->line('Canonical mismatches:             '.$summary['canonical_mismatches']);
        $this->line('Noindex targets:                  '.$summary['noindex_targets']);
        $this->line('Identity mismatches:               '.$summary['identity_mismatches']);
        $this->line('Unrelated control failures:       '.$summary['unrelated_control_failures']);
        $this->line('Duplicate/conflicting sources:    '.$summary['duplicate_or_conflicting_sources']);
        $this->newLine();
        $this->line('Evidence: '.$outputRelative);
        $this->line('Human verification cookie used: '.($humanVerificationCookieUsed ? 'YES' : 'NO'));
        $this->line('Redirect activation changed by this command: NO');
        $this->line('RESULT: '.$result);
    }

    /** @return array{name: string, value: string, domain: string}|null */
    private function humanVerificationCookieForBaseUrl(string $baseUrl): ?array
    {
        if (! (bool) config('traffic_protection.enabled', false)) {
            return null;
        }

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $appUrl = trim((string) config('app.url', ''));
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($baseHost) || $baseHost === '' || ! is_string($appHost) || $appHost === '') {
            throw new RuntimeException(
                'Traffic protection is enabled, but the configured APP_URL does not contain a valid host.'
            );
        }

        if (! hash_equals(strtolower($appHost), strtolower($baseHost))) {
            throw new RuntimeException(sprintf(
                'Traffic protection is enabled; --base-url host (%s) must match configured APP_URL host (%s) before a signed human-verification cookie can be sent.',
                $baseHost,
                $appHost,
            ));
        }

        $storefrontRequest = StorefrontRequest::create($baseUrl.'/', 'GET');
        $cookie = app(HumanVerificationCookie::class)->make($storefrontRequest);
        $cookieName = $cookie->getName();
        $cookieValue = $cookie->getValue();

        if ($cookieName === '' || ! is_string($cookieValue) || $cookieValue === '') {
            throw new RuntimeException('Unable to mint the signed human-verification cookie for redirect-runtime validation.');
        }

        $encrypter = app(Encrypter::class);
        $wireCookieValue = $encrypter->encrypt(
            CookieValuePrefix::create($cookieName, $encrypter->getKey()).$cookieValue,
            EncryptCookies::serialized($cookieName),
        );

        return [
            'name' => $cookieName,
            'value' => $wireCookieValue,
            'domain' => strtolower($baseHost),
        ];
    }

    private function validatedBaseUrl(): string
    {
        $value = trim((string) $this->option('base-url'));

        if ($value === '') {
            throw new RuntimeException('Option --base-url is required.');
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Option --base-url must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Option --base-url must use http or https.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Option --base-url must not contain a query string or fragment.');
        }

        $path = (string) ($parts['path'] ?? '');

        if ($path !== '' && $path !== '/') {
            throw new RuntimeException('Option --base-url must not contain a path.');
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.strtolower((string) $parts['host']).$port;
    }

    private function validatedControlPath(): string
    {
        $path = trim((string) $this->option('control-path'));
        $this->validatePath($path, 'control');

        if (str_starts_with($path, '/products/')) {
            throw new RuntimeException('Option --control-path must be unrelated to product redirect targets.');
        }

        return $path;
    }

    private function validatedTimeout(): int
    {
        $raw = $this->option('timeout');

        if (! is_numeric($raw)) {
            throw new RuntimeException('Option --timeout must be an integer between 1 and 120.');
        }

        $timeout = (int) $raw;

        if ($timeout < 1 || $timeout > 120) {
            throw new RuntimeException('Option --timeout must be an integer between 1 and 120.');
        }

        return $timeout;
    }

    private function safeRelativePath(string $option): string
    {
        $value = trim((string) $this->option($option));

        if ($value === '' || str_starts_with($value, '/') || str_contains($value, '..')) {
            throw new RuntimeException('Option --'.$option.' must be a safe repository-relative path.');
        }

        return $value;
    }

    private function validatePath(string $path, string $label): void
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new RuntimeException('Approved '.$label.' path must begin with /.');
        }

        if (str_contains($path, '?') || str_contains($path, '#') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new RuntimeException('Approved '.$label.' path must not contain query strings, fragments, or line breaks: '.$path);
        }

        if (str_starts_with($path, '//')) {
            throw new RuntimeException('Approved '.$label.' path must be origin-relative, not scheme-relative: '.$path);
        }
    }
}
