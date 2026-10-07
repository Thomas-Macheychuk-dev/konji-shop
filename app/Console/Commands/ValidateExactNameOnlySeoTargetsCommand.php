<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Security\HumanVerificationCookie;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request as StorefrontRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ValidateExactNameOnlySeoTargetsCommand extends Command
{
    public const MANIFEST = 'resources/seo/ortezka/review/seo-07b-20261007/exact-name-only-candidates-149.json';

    public const MANIFEST_SHA256 = 'f2330ca1e58677bcd1603e61ea7db6622d17aa0765c5c78e02128813e27fb54b';

    public const SOURCE_REVIEW_SHA256 = 'a2000a334104a862b73f3ddcdb548d4b297accfb7c607038cd82fbd0e0c3db5d';

    public const SOURCE_EVIDENCE_SHA256 = '2abbf5fae0e65f6750a83ab37e7a31a4d18266f518718ca8045e915e5779c9d5';

    public const PRODUCTION_MAP_SHA256 = '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009';

    private const PRODUCT_COUNT = 149;

    private const SOURCE_PATH_COUNT = 259;

    protected $signature = 'seo:validate-exact-name-only-targets
        {--manifest='.self::MANIFEST.' : Repository-relative frozen SEO-07B review-only manifest}
        {--base-url= : Absolute HTTP(S) storefront base URL}
        {--output=storage/app/private/scrapers/seo/ortezka/audits/seo07c-20261007/target-validation-149.json : Repository-relative validation evidence output}
        {--timeout=15 : Per-request timeout in seconds}
        {--allow-noindex : Permit noindex only for explicitly isolated staging validation}';

    protected $description = 'Validate the frozen SEO-07 exact-name-only target cohort without approving or installing redirects.';

    public function handle(): int
    {
        $this->info('Validating frozen SEO-07 exact-name-only review targets.');
        $this->line('Database writes: NO');
        $this->line('Redirect/runtime changes: NO');
        $this->line('Redirects approved by this command: 0');
        $this->line('Approval state: REVIEW_ONLY');

        try {
            $manifestRelative = $this->safeRelativePath('manifest');
            $outputRelative = $this->safeRelativePath('output');
            $baseUrl = $this->validatedBaseUrl();
            $timeout = $this->validatedTimeout();
            $allowNoindex = (bool) $this->option('allow-noindex');

            $manifestPath = base_path($manifestRelative);

            if (! is_file($manifestPath)) {
                throw new RuntimeException(
                    'SEO-07 review-only manifest does not exist: '.$manifestRelative,
                );
            }

            $rawManifest = file_get_contents($manifestPath);

            if (! is_string($rawManifest)) {
                throw new RuntimeException(
                    'Unable to read SEO-07 review-only manifest: '.$manifestRelative,
                );
            }

            $manifestSha = hash('sha256', $rawManifest);

            if (! hash_equals(self::MANIFEST_SHA256, $manifestSha)) {
                throw new RuntimeException(
                    'SEO-07 review-only manifest checksum mismatch.',
                );
            }

            /** @var array<string, mixed> $manifest */
            $manifest = json_decode(
                $rawManifest,
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $targets = $this->validatedCandidates($manifest);
            $humanVerificationCookie = $this->humanVerificationCookieForBaseUrl(
                $baseUrl,
            );

            $records = [];

            foreach ($targets as $target) {
                $records[] = $this->validateTarget(
                    $baseUrl,
                    $target,
                    $timeout,
                    $humanVerificationCookie,
                );
            }

            $summary = $this->summary($targets, $records);
            $result = $this->passes($summary, $allowNoindex)
                ? 'PASS'
                : 'FAIL';

            $report = [
                'schema_version' => 1,
                'phase' => 'SEO-07C',
                'purpose' => 'Validate storefront behavior for the frozen exact-name-only review cohort without approving or installing redirects.',
                'generated_at' => now()->toIso8601String(),
                'validation_only' => true,
                'approval_state' => 'REVIEW_ONLY',
                'classification' => 'exact_name_only',
                'redirects_approved' => 0,
                'redirects_enabled_by_this_command' => false,
                'manifest' => $manifestRelative,
                'manifest_sha256' => $manifestSha,
                'base_url' => $baseUrl,
                'allow_noindex' => $allowNoindex,
                'human_verification_cookie_used' => $humanVerificationCookie !== null,
                'human_verification_cookie_name' => $humanVerificationCookie['name'] ?? null,
                'summary' => $summary,
                'result' => $result,
                'records' => $records,
            ];

            $this->writeReport($outputRelative, $report);
            $this->renderSummary(
                $summary,
                $result,
                $outputRelative,
                $humanVerificationCookie !== null,
            );

            return $result === 'PASS'
                ? self::SUCCESS
                : self::FAILURE;
        } catch (JsonException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{
     *     legacy_id: string,
     *     target_product_id: string,
     *     target_product_name: string,
     *     target_path: string
     * }>
     */
    private function validatedCandidates(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException(
                'SEO-07 manifest must use schema_version=1.',
            );
        }

        if (($manifest['phase'] ?? null) !== 'SEO-07B') {
            throw new RuntimeException(
                'SEO-07 manifest must be the frozen SEO-07B cohort.',
            );
        }

        if (($manifest['classification'] ?? null) !== 'exact_name_only') {
            throw new RuntimeException(
                'SEO-07 manifest must contain only exact_name_only evidence.',
            );
        }

        if (($manifest['approval_state'] ?? null) !== 'REVIEW_ONLY') {
            throw new RuntimeException(
                'SEO-07 manifest must remain REVIEW_ONLY.',
            );
        }

        if (($manifest['redirects_approved'] ?? null) !== 0) {
            throw new RuntimeException(
                'SEO-07 manifest must contain redirects_approved=0.',
            );
        }

        if (($manifest['redirects_installed'] ?? null) !== 0) {
            throw new RuntimeException(
                'SEO-07 manifest must contain redirects_installed=0.',
            );
        }

        if (($manifest['database_writes'] ?? null) !== false) {
            throw new RuntimeException(
                'SEO-07 manifest must declare database_writes=false.',
            );
        }

        if (($manifest['runtime_changes'] ?? null) !== false) {
            throw new RuntimeException(
                'SEO-07 manifest must declare runtime_changes=false.',
            );
        }

        if (($manifest['source_review_sha256'] ?? null) !== self::SOURCE_REVIEW_SHA256) {
            throw new RuntimeException(
                'SEO-07 source review checksum provenance mismatch.',
            );
        }

        if (($manifest['source_evidence_sha256'] ?? null) !== self::SOURCE_EVIDENCE_SHA256) {
            throw new RuntimeException(
                'SEO-07 source evidence checksum provenance mismatch.',
            );
        }

        if (($manifest['production_map_sha256'] ?? null) !== self::PRODUCTION_MAP_SHA256) {
            throw new RuntimeException(
                'SEO-07 production map checksum provenance mismatch.',
            );
        }

        $summary = $manifest['summary'] ?? null;

        if (! is_array($summary)) {
            throw new RuntimeException(
                'SEO-07 manifest summary is missing.',
            );
        }

        $expectedSummary = [
            'product_count' => self::PRODUCT_COUNT,
            'source_path_count' => self::SOURCE_PATH_COUNT,
            'unique_target_count' => self::PRODUCT_COUNT,
            'active_target_count' => self::PRODUCT_COUNT,
            'storefront_reachable_count' => self::PRODUCT_COUNT,
            'current_production_rule_count' => 400,
            'static_blocker_count' => 0,
        ];

        foreach ($expectedSummary as $key => $expected) {
            if (($summary[$key] ?? null) !== $expected) {
                throw new RuntimeException(
                    sprintf(
                        'SEO-07 manifest summary mismatch for %s.',
                        $key,
                    ),
                );
            }
        }

        $collisionChecks = $manifest['static_collision_checks'] ?? null;

        if (! is_array($collisionChecks) || $collisionChecks === []) {
            throw new RuntimeException(
                'SEO-07 static collision evidence is missing.',
            );
        }

        foreach ($collisionChecks as $name => $collisions) {
            if (! is_array($collisions) || $collisions !== []) {
                throw new RuntimeException(
                    'SEO-07 static collision blocker is present: '.(string) $name,
                );
            }
        }

        $records = $manifest['records'] ?? null;

        if (! is_array($records) || count($records) !== self::PRODUCT_COUNT) {
            throw new RuntimeException(
                'SEO-07 manifest must contain exactly 149 review records.',
            );
        }

        $ids = [];
        $targets = [];
        $sources = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException(
                    'SEO-07 manifest contains a non-object record.',
                );
            }

            if (($record['classification'] ?? null) !== 'exact_name_only'
                || ($record['review_class'] ?? null) !== 'review_active_exact_name_only'
                || ($record['decision'] ?? null) !== 'REVIEW_EXACT_NAME_ONLY') {
                throw new RuntimeException(
                    'SEO-07 record is outside the frozen exact-name-only review cohort.',
                );
            }

            if (($record['approved'] ?? null) !== false
                || ($record['redirect_approved'] ?? null) !== false
                || ($record['manual_review_required'] ?? null) !== true) {
                throw new RuntimeException(
                    'SEO-07 record must remain unapproved and manual-review-only.',
                );
            }

            if (($record['target_product_status'] ?? null) !== 'active'
                || ($record['target_storefront_reachable'] ?? null) !== true) {
                throw new RuntimeException(
                    'SEO-07 target must remain active and storefront-reachable.',
                );
            }

            if (($record['legacy_name_unique'] ?? null) !== true) {
                throw new RuntimeException(
                    'SEO-07 record must preserve unique legacy-name evidence.',
                );
            }

            $legacyId = $record['legacy_id'] ?? null;
            $productId = $record['target_product_id'] ?? null;
            $productName = $record['target_product_name'] ?? null;
            $targetPath = $record['target_path'] ?? null;
            $sourcePaths = $record['source_paths'] ?? null;

            if (! is_string($legacyId) || trim($legacyId) === '') {
                throw new RuntimeException(
                    'SEO-07 record is missing legacy_id.',
                );
            }

            if (! is_string($productId) || trim($productId) === '') {
                throw new RuntimeException(
                    'SEO-07 record is missing target_product_id.',
                );
            }

            if (! is_string($productName) || trim($productName) === '') {
                throw new RuntimeException(
                    'SEO-07 record is missing target_product_name.',
                );
            }

            if (! is_string($targetPath)
                || ! str_starts_with($targetPath, '/products/')) {
                throw new RuntimeException(
                    'SEO-07 target path must use /products/...',
                );
            }

            $this->validatePath($targetPath, 'target');

            if (isset($ids[$legacyId])) {
                throw new RuntimeException(
                    'Duplicate SEO-07 legacy ID: '.$legacyId,
                );
            }

            if (isset($targets[$targetPath])) {
                throw new RuntimeException(
                    'Duplicate SEO-07 target path: '.$targetPath,
                );
            }

            if (! is_array($sourcePaths) || $sourcePaths === []) {
                throw new RuntimeException(
                    'SEO-07 record must contain source paths.',
                );
            }

            if (($record['source_path_count'] ?? null) !== count($sourcePaths)) {
                throw new RuntimeException(
                    'SEO-07 source_path_count mismatch for legacy ID '.$legacyId.'.',
                );
            }

            foreach ($sourcePaths as $sourcePath) {
                if (! is_string($sourcePath)) {
                    throw new RuntimeException(
                        'SEO-07 source paths must be strings.',
                    );
                }

                $this->validatePath($sourcePath, 'source');

                if ($sourcePath === $targetPath) {
                    throw new RuntimeException(
                        'SEO-07 source path equals its target path.',
                    );
                }

                if (isset($sources[$sourcePath])) {
                    throw new RuntimeException(
                        'Duplicate SEO-07 source path: '.$sourcePath,
                    );
                }

                $sources[$sourcePath] = true;
            }

            $ids[$legacyId] = true;

            $targets[$targetPath] = [
                'legacy_id' => $legacyId,
                'target_product_id' => $productId,
                'target_product_name' => $productName,
                'target_path' => $targetPath,
            ];
        }

        if (count($ids) !== self::PRODUCT_COUNT
            || count($targets) !== self::PRODUCT_COUNT
            || count($sources) !== self::SOURCE_PATH_COUNT) {
            throw new RuntimeException(
                'SEO-07 frozen cohort cardinality mismatch.',
            );
        }

        ksort($targets, SORT_STRING);

        return array_values($targets);
    }

    /**
     * @param array{
     *     legacy_id: string,
     *     target_product_id: string,
     *     target_product_name: string,
     *     target_path: string
     * } $target
     * @param array{name: string, value: string, domain: string}|null $humanVerificationCookie
     * @return array<string, mixed>
     */
    private function validateTarget(
        string $baseUrl,
        array $target,
        int $timeout,
        ?array $humanVerificationCookie,
    ): array {
        $url = $baseUrl.$target['target_path'];

        $record = [
            ...$target,
            'url' => $url,
            'http_status' => null,
            'redirect_location' => null,
            'canonical' => null,
            'canonical_correct' => false,
            'indexable' => false,
            'identity_correct' => false,
            'observed_h1' => null,
            'failures' => [],
        ];

        try {
            $request = Http::withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => 'KonjiShop-SEO07-ExactNameTargetValidator/1.0',
            ])->withOptions([
                'allow_redirects' => false,
            ]);

            if ($humanVerificationCookie !== null) {
                $request = $request->withCookies(
                    [$humanVerificationCookie['name'] => $humanVerificationCookie['value']],
                    $humanVerificationCookie['domain'],
                );
            }

            $response = $request
                ->timeout($timeout)
                ->get($url);
        } catch (ConnectionException $exception) {
            $record['failures'][] = 'request_failed: '.$exception->getMessage();

            return $record;
        } catch (Throwable $exception) {
            $record['failures'][] = 'request_failed: '.$exception->getMessage();

            return $record;
        }

        $record['http_status'] = $response->status();
        $record['redirect_location'] = $response->header('Location');

        if ($response->status() !== 200) {
            $record['failures'][] = 'expected_http_200';

            return $record;
        }

        $urlForError = $url;

        try {
            $crawler = $this->utf8HtmlCrawler(
                $response->body(),
                $urlForError,
            );
        } catch (Throwable $exception) {
            $record['failures'][] = 'html_parse_failed: '.$exception->getMessage();

            return $record;
        }

        $canonical = $this->canonicalHref($crawler);

        $record['canonical'] = $canonical;
        $record['canonical_correct'] = $canonical !== null
            && $this->normalizeComparableUrl(
                $this->absoluteUrl($baseUrl, $canonical),
            ) === $this->normalizeComparableUrl($url);

        if (! $record['canonical_correct']) {
            $record['failures'][] = 'canonical_mismatch';
        }

        $record['indexable'] = ! $this->containsNoindex(
            $crawler,
            $response->header('X-Robots-Tag'),
        );

        if (! $record['indexable']) {
            $record['failures'][] = 'noindex';
        }

        $observedH1 = $this->firstH1($crawler);

        $record['observed_h1'] = $observedH1;
        $record['identity_correct'] = $observedH1 !== null
            && $this->normalizeText($observedH1)
                === $this->normalizeText($target['target_product_name']);

        if (! $record['identity_correct']) {
            $record['failures'][] = 'product_identity_mismatch';
        }

        return $record;
    }

    private function utf8HtmlCrawler(string $html, string $url): Crawler
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new RuntimeException(
                'Storefront HTML response is not valid UTF-8: '.$url,
            );
        }

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
                'Unable to parse storefront HTML response: '.$url,
            );
        }

        return new Crawler($document, $url);
    }

    private function canonicalHref(Crawler $crawler): ?string
    {
        $nodes = $crawler->filterXPath(
            "//link[contains(concat(' ', normalize-space(translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')), ' '), ' canonical ')]",
        );

        if ($nodes->count() !== 1) {
            return null;
        }

        $href = trim(
            (string) $nodes->first()->attr('href'),
        );

        return $href !== ''
            ? $href
            : null;
    }

    private function firstH1(Crawler $crawler): ?string
    {
        $nodes = $crawler->filter('h1');

        if ($nodes->count() === 0) {
            return null;
        }

        $text = trim(
            $nodes->first()->text('', true),
        );

        return $text !== ''
            ? $text
            : null;
    }

    private function containsNoindex(
        Crawler $crawler,
        ?string $xRobotsTag,
    ): bool {
        if (is_string($xRobotsTag)
            && $this->robotsValueContainsNoindex($xRobotsTag)) {
            return true;
        }

        $nodes = $crawler->filterXPath(
            "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='robots' or translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='googlebot']",
        );

        foreach ($nodes as $node) {
            $content = $node->attributes
                ?->getNamedItem('content')
                ?->nodeValue;

            if (is_string($content)
                && $this->robotsValueContainsNoindex($content)) {
                return true;
            }
        }

        return false;
    }

    private function robotsValueContainsNoindex(string $value): bool
    {
        $tokens = preg_split(
            '/[\s,;]+/u',
            mb_strtolower($value),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        return is_array($tokens)
            && in_array('noindex', $tokens, true);
    }

    private function absoluteUrl(
        string $baseUrl,
        string $url,
    ): string {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $baseUrl.$url;
        }

        return $baseUrl.'/'.ltrim($url, '/');
    }

    private function normalizeComparableUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower(
            (string) $parts['scheme'],
        );

        $host = strtolower(
            (string) $parts['host'],
        );

        if (! in_array(
            $scheme,
            ['http', 'https'],
            true,
        )) {
            return null;
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : null;

        $portSuffix = (
            $port !== null
            && ! (
                ($scheme === 'http' && $port === 80)
                || ($scheme === 'https' && $port === 443)
            )
        )
            ? ':'.$port
            : '';

        $path = (string) (
            $parts['path'] ?? '/'
        );

        $path = $path === ''
            ? '/'
            : $path;

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$host.$portSuffix.$path;
    }

    private function normalizeText(string $value): string
    {
        $value = str_replace(
            "\u{00A0}",
            ' ',
            $value,
        );

        $value = preg_replace(
            '/\s+/u',
            ' ',
            trim($value),
        ) ?? trim($value);

        return mb_strtolower(
            $value,
            'UTF-8',
        );
    }

    /**
     * @param list<array{
     *     legacy_id: string,
     *     target_product_id: string,
     *     target_product_name: string,
     *     target_path: string
     * }> $targets
     * @param list<array<string, mixed>> $records
     * @return array<string, int>
     */
    private function summary(
        array $targets,
        array $records,
    ): array {
        $http200 = 0;
        $canonicalCorrect = 0;
        $indexable = 0;
        $identityCorrect = 0;
        $missingTargets = 0;
        $redirectedTargets = 0;
        $canonicalMismatches = 0;
        $noindexTargets = 0;
        $identityMismatches = 0;
        $requestFailures = 0;
        $otherHttpFailures = 0;

        foreach ($records as $record) {
            $status = $record['http_status'];

            if ($status === 200) {
                $http200++;
            } elseif (is_int($status)
                && $status >= 300
                && $status < 400) {
                $redirectedTargets++;
            } elseif (in_array(
                $status,
                [404, 410],
                true,
            )) {
                $missingTargets++;
            } elseif ($status === null) {
                $requestFailures++;
            } else {
                $otherHttpFailures++;
            }

            if (($record['canonical_correct'] ?? false) === true) {
                $canonicalCorrect++;
            } elseif ($status === 200) {
                $canonicalMismatches++;
            }

            if (($record['indexable'] ?? false) === true) {
                $indexable++;
            } elseif ($status === 200) {
                $noindexTargets++;
            }

            if (($record['identity_correct'] ?? false) === true) {
                $identityCorrect++;
            } elseif ($status === 200) {
                $identityMismatches++;
            }
        }

        return [
            'candidate_target_products' => count($targets),
            'http_200' => $http200,
            'canonical_correct' => $canonicalCorrect,
            'indexable' => $indexable,
            'product_identity_correct' => $identityCorrect,
            'missing_targets' => $missingTargets,
            'redirected_targets' => $redirectedTargets,
            'canonical_mismatches' => $canonicalMismatches,
            'noindex_targets' => $noindexTargets,
            'identity_mismatches' => $identityMismatches,
            'request_failures' => $requestFailures,
            'other_http_failures' => $otherHttpFailures,
        ];
    }

    /**
     * @param array<string, int> $summary
     */
    private function passes(
        array $summary,
        bool $allowNoindex,
    ): bool {
        $expected = $summary['candidate_target_products'];

        return $expected === self::PRODUCT_COUNT
            && $summary['http_200'] === $expected
            && $summary['canonical_correct'] === $expected
            && (
                $allowNoindex
                || $summary['indexable'] === $expected
            )
            && $summary['product_identity_correct'] === $expected
            && $summary['missing_targets'] === 0
            && $summary['redirected_targets'] === 0
            && $summary['canonical_mismatches'] === 0
            && (
                $allowNoindex
                || $summary['noindex_targets'] === 0
            )
            && $summary['identity_mismatches'] === 0
            && $summary['request_failures'] === 0
            && $summary['other_http_failures'] === 0;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeReport(
        string $outputRelative,
        array $report,
    ): void {
        $outputPath = base_path(
            $outputRelative,
        );

        $directory = dirname(
            $outputPath,
        );

        if (! is_dir($directory)
            && ! mkdir(
                $directory,
                0775,
                true,
            )
            && ! is_dir($directory)) {
            throw new RuntimeException(
                'Unable to create SEO-07 target-validation evidence directory: '
                .$directory,
            );
        }

        try {
            $json = json_encode(
                $report,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode SEO-07 target-validation report: '
                .$exception->getMessage(),
                previous: $exception,
            );
        }

        $temporary = $outputPath.'.tmp';

        if (file_put_contents(
            $temporary,
            $json,
            LOCK_EX,
        ) === false) {
            throw new RuntimeException(
                'Unable to write temporary SEO-07 target-validation report: '
                .$temporary,
            );
        }

        if (! rename(
            $temporary,
            $outputPath,
        )) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to atomically publish SEO-07 target-validation report: '
                .$outputRelative,
            );
        }
    }

    /**
     * @param array<string, int> $summary
     */
    private function renderSummary(
        array $summary,
        string $result,
        string $outputRelative,
        bool $humanVerificationCookieUsed,
    ): void {
        $this->newLine();
        $this->line(
            'Candidate target products:      '
            .$summary['candidate_target_products'],
        );

        $this->newLine();
        $this->line(
            'HTTP 200:                       '
            .$summary['http_200'],
        );
        $this->line(
            'Canonical correct:              '
            .$summary['canonical_correct'],
        );
        $this->line(
            'Indexable:                      '
            .$summary['indexable'],
        );
        $this->line(
            'Product identity correct:       '
            .$summary['product_identity_correct'],
        );

        $this->newLine();
        $this->line(
            'Missing targets:                 '
            .$summary['missing_targets'],
        );
        $this->line(
            'Redirected targets:              '
            .$summary['redirected_targets'],
        );
        $this->line(
            'Canonical mismatches:            '
            .$summary['canonical_mismatches'],
        );
        $this->line(
            'Noindex targets:                 '
            .$summary['noindex_targets'],
        );
        $this->line(
            'Identity mismatches:              '
            .$summary['identity_mismatches'],
        );
        $this->line(
            'Request failures:                 '
            .$summary['request_failures'],
        );
        $this->line(
            'Other HTTP failures:              '
            .$summary['other_http_failures'],
        );

        $this->newLine();
        $this->line(
            'Evidence: '.$outputRelative,
        );
        $this->line(
            'Human verification cookie used: '
            .($humanVerificationCookieUsed ? 'YES' : 'NO'),
        );
        $this->line(
            'Approval state: REVIEW_ONLY',
        );
        $this->line(
            'Redirects approved by this command: 0',
        );
        $this->line(
            'Redirects enabled by this command: NO',
        );
        $this->line(
            'RESULT: '.$result,
        );
    }

    /**
     * @return array{name: string, value: string, domain: string}|null
     */
    private function humanVerificationCookieForBaseUrl(
        string $baseUrl,
    ): ?array {
        if (! (bool) config(
            'traffic_protection.enabled',
            false,
        )) {
            return null;
        }

        $baseHost = parse_url(
            $baseUrl,
            PHP_URL_HOST,
        );

        $appUrl = trim(
            (string) config('app.url', ''),
        );

        $appHost = parse_url(
            $appUrl,
            PHP_URL_HOST,
        );

        if (! is_string($baseHost)
            || $baseHost === ''
            || ! is_string($appHost)
            || $appHost === '') {
            throw new RuntimeException(
                'Traffic protection is enabled, but the configured APP_URL does not contain a valid host.',
            );
        }

        if (! hash_equals(
            strtolower($appHost),
            strtolower($baseHost),
        )) {
            throw new RuntimeException(
                sprintf(
                    'Traffic protection is enabled; --base-url host (%s) must match configured APP_URL host (%s) before a signed human-verification cookie can be sent.',
                    $baseHost,
                    $appHost,
                ),
            );
        }

        $storefrontRequest = StorefrontRequest::create(
            $baseUrl.'/',
            'GET',
        );

        $cookie = app(
            HumanVerificationCookie::class,
        )->make($storefrontRequest);

        $cookieName = $cookie->getName();
        $cookieValue = $cookie->getValue();

        if ($cookieName === ''
            || ! is_string($cookieValue)
            || $cookieValue === '') {
            throw new RuntimeException(
                'Unable to mint signed human-verification cookie for SEO-07 validation.',
            );
        }

        $encrypter = app(
            Encrypter::class,
        );

        $wireCookieValue = $encrypter->encrypt(
            CookieValuePrefix::create(
                $cookieName,
                $encrypter->getKey(),
            ).$cookieValue,
            EncryptCookies::serialized(
                $cookieName,
            ),
        );

        return [
            'name' => $cookieName,
            'value' => $wireCookieValue,
            'domain' => strtolower($baseHost),
        ];
    }

    private function validatedBaseUrl(): string
    {
        $value = trim(
            (string) $this->option('base-url'),
        );

        if ($value === '') {
            throw new RuntimeException(
                'Option --base-url is required.',
            );
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! isset(
                $parts['scheme'],
                $parts['host'],
            )) {
            throw new RuntimeException(
                'Option --base-url must be an absolute HTTP(S) URL.',
            );
        }

        $scheme = strtolower(
            (string) $parts['scheme'],
        );

        if (! in_array(
            $scheme,
            ['http', 'https'],
            true,
        )) {
            throw new RuntimeException(
                'Option --base-url must use http or https.',
            );
        }

        if (isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException(
                'Option --base-url must not contain a query string or fragment.',
            );
        }

        $path = (string) (
            $parts['path'] ?? ''
        );

        if ($path !== '' && $path !== '/') {
            throw new RuntimeException(
                'Option --base-url must not contain a path.',
            );
        }

        $port = isset($parts['port'])
            ? ':'.(int) $parts['port']
            : '';

        return $scheme.'://'
            .strtolower((string) $parts['host'])
            .$port;
    }

    private function validatedTimeout(): int
    {
        $raw = trim(
            (string) $this->option('timeout'),
        );

        if ($raw === ''
            || preg_match('/^\d+$/', $raw) !== 1) {
            throw new RuntimeException(
                'Option --timeout must be an integer between 1 and 60 seconds.',
            );
        }

        $timeout = (int) $raw;

        if ($timeout < 1 || $timeout > 60) {
            throw new RuntimeException(
                'Option --timeout must be an integer between 1 and 60 seconds.',
            );
        }

        return $timeout;
    }

    private function validatePath(
        string $path,
        string $label,
    ): void {
        if ($path === ''
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')) {
            throw new RuntimeException(
                sprintf(
                    'SEO-07 %s path must be an absolute-path reference: %s',
                    $label,
                    $path,
                ),
            );
        }

        if (str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, "\n")
            || str_contains($path, "\r")) {
            throw new RuntimeException(
                sprintf(
                    'SEO-07 %s path must not contain query strings, fragments, or newlines: %s',
                    $label,
                    $path,
                ),
            );
        }
    }

    private function safeRelativePath(
        string $option,
    ): string {
        $path = trim(
            (string) $this->option($option),
        );

        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '..')) {
            throw new RuntimeException(
                sprintf(
                    'Option --%s must be a safe repository-relative path.',
                    $option,
                ),
            );
        }

        return $path;
    }
}
