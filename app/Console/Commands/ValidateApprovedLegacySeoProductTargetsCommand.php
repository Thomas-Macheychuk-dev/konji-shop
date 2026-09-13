<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class ValidateApprovedLegacySeoProductTargetsCommand extends Command
{
    protected $signature = 'seo:validate-approved-product-targets
        {--manifest=resources/seo/ortezka/product-redirect-approvals.json : Repository-relative approved redirect manifest}
        {--base-url= : Absolute HTTP(S) base URL of the Konji storefront to validate}
        {--output=storage/app/seo/ortezka/approved-product-target-validation.json : Repository-relative JSON evidence output}
        {--timeout=15 : Per-request timeout in seconds}';

    protected $description = 'Validate every human-approved legacy SEO product redirect target before redirect activation.';

    public function handle(): int
    {
        try {
            $manifestRelative = $this->safeRelativePath('manifest');
            $outputRelative = $this->safeRelativePath('output');
            $baseUrl = $this->validatedBaseUrl();
            $timeout = $this->validatedTimeout();
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
            $targets = $this->approvedTargets($manifest);

            $records = [];

            foreach ($targets as $target) {
                $records[] = $this->validateTarget($baseUrl, $target, $timeout);
            }

            $summary = $this->summary($targets, $records);
            $result = $this->passes($summary) ? 'PASS' : 'FAIL';

            $report = [
                'schema_version' => 1,
                'purpose' => 'Validate the final storefront targets for the human-approved legacy Ortezka product redirect cohort before any redirect activation.',
                'generated_at' => now()->toIso8601String(),
                'manifest' => $manifestRelative,
                'manifest_sha256' => hash('sha256', $rawManifest),
                'base_url' => $baseUrl,
                'redirects_enabled_by_this_command' => false,
                'summary' => $summary,
                'result' => $result,
                'records' => $records,
            ];

            $this->writeReport($outputRelative, $report);
            $this->renderSummary($summary, $result, $outputRelative);

            return $result === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (JsonException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{target_product_id: string, target_product_name: string, target_path: string}>
     */
    private function approvedTargets(array $manifest): array
    {
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

        $targets = [];
        $sourcePaths = [];

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

            if (isset($targets[$targetPath])) {
                throw new RuntimeException('Duplicate/conflicting approved target path: '.$targetPath);
            }

            if (! is_array($sources) || $sources === []) {
                throw new RuntimeException('Every approved redirect record must contain at least one source path.');
            }

            foreach ($sources as $sourcePath) {
                if (! is_string($sourcePath)) {
                    throw new RuntimeException('Approved redirect source paths must be strings.');
                }

                $this->validatePath($sourcePath, 'source');

                if (isset($sourcePaths[$sourcePath])) {
                    throw new RuntimeException('Duplicate/conflicting approved redirect source path: '.$sourcePath);
                }

                $sourcePaths[$sourcePath] = true;
            }

            $targets[$targetPath] = [
                'target_product_id' => $targetProductId,
                'target_product_name' => $targetProductName,
                'target_path' => $targetPath,
            ];
        }

        if (($manifest['approved_product_count'] ?? null) !== count($targets)) {
            throw new RuntimeException('approved_product_count does not match unique approved target records.');
        }

        if (($manifest['approved_source_path_count'] ?? null) !== count($sourcePaths)) {
            throw new RuntimeException('approved_source_path_count does not match unique approved source paths.');
        }

        ksort($targets, SORT_STRING);

        return array_values($targets);
    }

    /**
     * @param  array{target_product_id: string, target_product_name: string, target_path: string}  $target
     * @return array<string, mixed>
     */
    private function validateTarget(string $baseUrl, array $target, int $timeout): array
    {
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
            $response = Http::withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => 'KonjiShop-SEO-Target-Validator/1.0',
            ])->withOptions([
                'allow_redirects' => false,
            ])->timeout($timeout)->get($url);
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

        $html = $response->body();
        $crawler = new Crawler($html, $url);
        $canonical = $this->canonicalHref($crawler);
        $record['canonical'] = $canonical;
        $record['canonical_correct'] = $canonical !== null
            && $this->normalizeComparableUrl($this->absoluteUrl($baseUrl, $canonical)) === $this->normalizeComparableUrl($url);

        if (! $record['canonical_correct']) {
            $record['failures'][] = 'canonical_mismatch';
        }

        $record['indexable'] = ! $this->containsNoindex($crawler, $response->header('X-Robots-Tag'));

        if (! $record['indexable']) {
            $record['failures'][] = 'noindex';
        }

        $observedH1 = $this->firstH1($crawler);
        $record['observed_h1'] = $observedH1;
        $record['identity_correct'] = $observedH1 !== null
            && $this->normalizeText($observedH1) === $this->normalizeText($target['target_product_name']);

        if (! $record['identity_correct']) {
            $record['failures'][] = 'product_identity_mismatch';
        }

        return $record;
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
        $tokens = preg_split('/[\s,;]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) && in_array('noindex', $tokens, true);
    }

    private function absoluteUrl(string $baseUrl, string $url): string
    {
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

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
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
     * @param  list<array{target_product_id: string, target_product_name: string, target_path: string}>  $targets
     * @param  list<array<string, mixed>>  $records
     * @return array<string, int>
     */
    private function summary(array $targets, array $records): array
    {
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
            } elseif (is_int($status) && $status >= 300 && $status < 400) {
                $redirectedTargets++;
            } elseif (in_array($status, [404, 410], true)) {
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
            'approved_target_products' => count($targets),
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
            'duplicate_or_conflicting_targets' => 0,
        ];
    }

    /** @param array<string, int> $summary */
    private function passes(array $summary): bool
    {
        $expected = $summary['approved_target_products'];

        return $expected > 0
            && $summary['http_200'] === $expected
            && $summary['canonical_correct'] === $expected
            && $summary['indexable'] === $expected
            && $summary['product_identity_correct'] === $expected
            && $summary['missing_targets'] === 0
            && $summary['redirected_targets'] === 0
            && $summary['canonical_mismatches'] === 0
            && $summary['noindex_targets'] === 0
            && $summary['identity_mismatches'] === 0
            && $summary['request_failures'] === 0
            && $summary['other_http_failures'] === 0
            && $summary['duplicate_or_conflicting_targets'] === 0;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $outputRelative, array $report): void
    {
        $outputPath = base_path($outputRelative);
        $directory = dirname($outputPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create target-validation evidence directory: '.$directory);
        }

        try {
            $json = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode target-validation report: '.$exception->getMessage(), previous: $exception);
        }

        $tmp = $outputPath.'.tmp';

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary target-validation report: '.$tmp);
        }

        if (! rename($tmp, $outputPath)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to atomically publish target-validation report: '.$outputRelative);
        }
    }

    /** @param array<string, int> $summary */
    private function renderSummary(array $summary, string $result, string $outputRelative): void
    {
        $this->newLine();
        $this->line('Approved target products:       '.$summary['approved_target_products']);
        $this->newLine();
        $this->line('HTTP 200:                       '.$summary['http_200']);
        $this->line('Canonical correct:              '.$summary['canonical_correct']);
        $this->line('Indexable:                      '.$summary['indexable']);
        $this->line('Product identity correct:       '.$summary['product_identity_correct']);
        $this->newLine();
        $this->line('Missing targets:                 '.$summary['missing_targets']);
        $this->line('Redirected targets:              '.$summary['redirected_targets']);
        $this->line('Canonical mismatches:            '.$summary['canonical_mismatches']);
        $this->line('Noindex targets:                 '.$summary['noindex_targets']);
        $this->line('Identity mismatches:              '.$summary['identity_mismatches']);
        $this->line('Request failures:                 '.$summary['request_failures']);
        $this->line('Other HTTP failures:              '.$summary['other_http_failures']);
        $this->line('Duplicate/conflicting targets:    '.$summary['duplicate_or_conflicting_targets']);
        $this->newLine();
        $this->line('Evidence: '.$outputRelative);
        $this->line('Redirects enabled by this command: NO');
        $this->line('RESULT: '.$result);
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

    private function validatedTimeout(): int
    {
        $raw = trim((string) $this->option('timeout'));

        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            throw new RuntimeException('Option --timeout must be an integer between 1 and 60 seconds.');
        }

        $timeout = (int) $raw;

        if ($timeout < 1 || $timeout > 60) {
            throw new RuntimeException('Option --timeout must be an integer between 1 and 60 seconds.');
        }

        return $timeout;
    }

    private function validatePath(string $path, string $label): void
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new RuntimeException('Approved '.$label.' path must be an absolute-path reference: '.$path);
        }

        if (str_contains($path, '?') || str_contains($path, '#') || str_contains($path, "\n") || str_contains($path, "\r")) {
            throw new RuntimeException('Approved '.$label.' path must not contain query strings, fragments, or newlines: '.$path);
        }
    }

    private function safeRelativePath(string $option): string
    {
        $path = trim((string) $this->option($option));

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException(sprintf('Option --%s must be a safe repository-relative path.', $option));
        }

        return $path;
    }
}
