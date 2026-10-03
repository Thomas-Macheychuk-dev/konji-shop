<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Seo\ParentProductRedirectApprovalPolicy;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class GenerateLegacySeoProductRedirectMapCommand extends Command
{
    protected $signature = 'seo:generate-legacy-product-redirect-map
        {--manifest=resources/seo/ortezka/product-redirect-approvals.json : Repository-relative approved redirect manifest}
        {--output=docker/nginx/generated/legacy-seo-product-map.conf : Repository-relative generated Nginx map output}';

    protected $description = 'Generate the Nginx legacy product redirect map from the human-approved SEO manifest.';

    public function handle(): int
    {
        try {
            $manifestRelative = $this->safeRelativePath('manifest');
            $outputRelative = $this->safeRelativePath('output');
            $manifestPath = base_path($manifestRelative);
            $outputPath = base_path($outputRelative);

            if (! is_file($manifestPath)) {
                throw new RuntimeException('Approved redirect manifest does not exist: '.$manifestRelative);
            }

            $rawManifest = file_get_contents($manifestPath);

            if (! is_string($rawManifest)) {
                throw new RuntimeException('Unable to read approved redirect manifest: '.$manifestRelative);
            }

            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($rawManifest, true, flags: JSON_THROW_ON_ERROR);
            $rules = $this->approvedRules($manifest);
            $rendered = $this->renderMap($rules, hash('sha256', $rawManifest), $manifestRelative);

            $directory = dirname($outputPath);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create Nginx redirect map directory: '.$directory);
            }

            $tmp = $outputPath.'.tmp';

            if (file_put_contents($tmp, $rendered, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write temporary Nginx redirect map: '.$tmp);
            }

            if (! rename($tmp, $outputPath)) {
                @unlink($tmp);

                throw new RuntimeException('Unable to atomically publish Nginx redirect map: '.$outputRelative);
            }
        } catch (JsonException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Legacy product redirect Nginx map generated.');
        $this->line('Approved source paths: '.count($rules));
        $this->line('Runtime redirects enabled by this command: NO');
        $this->line('Output: '.$outputRelative);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function approvedRules(array $manifest): array
    {
        $schemaVersion = $manifest['schema_version'] ?? null;

        if ($schemaVersion === 3) {
            return $this->approvedV3Rules($manifest);
        }

        if (! in_array($schemaVersion, [1, 2], true)) {
            throw new RuntimeException('Unsupported redirect approval manifest schema version.');
        }

        if (($manifest['redirects_installed'] ?? null) !== 0) {
            throw new RuntimeException('Approval manifest must remain evidence-only with redirects_installed=0.');
        }

        if ($schemaVersion === 2 && ($manifest['validation_only'] ?? null) === true) {
            throw new RuntimeException('Validation-only schema v2 manifests cannot generate runtime redirects.');
        }

        $records = $manifest['records'] ?? null;

        if (! is_array($records)) {
            throw new RuntimeException('Approval manifest records must be an array.');
        }

        $rules = [];
        $approvedProducts = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Approval manifest contains a non-object record.');
            }

            if (($record['approved'] ?? false) !== true || ($record['decision'] ?? null) !== 'APPROVE_301') {
                throw new RuntimeException('Approval manifest contains a record that is not explicitly APPROVE_301.');
            }

            if ($schemaVersion === 1) {
                if (($record['target_product_status'] ?? null) !== 'active'
                    || ($record['matched_variant_status'] ?? null) !== 'active') {
                    throw new RuntimeException('Schema v1 approval manifest contains an inactive product or matched variant.');
                }
            } else {
                if (($record['approval_basis'] ?? null) !== 'exact_identifier_and_name') {
                    throw new RuntimeException('Schema v2 redirect generation requires approval_basis=exact_identifier_and_name.');
                }

                if (($record['target_product_status'] ?? null) !== 'active'
                    || ($record['target_storefront_reachable'] ?? null) !== true) {
                    throw new RuntimeException('Schema v2 redirect generation requires an active, storefront-reachable target product.');
                }
            }

            $target = $record['target_path'] ?? null;

            if (! is_string($target) || ! str_starts_with($target, '/products/')) {
                throw new RuntimeException('Every approved redirect target must be a /products/... path.');
            }

            $this->validatePath($target, 'target');

            $sourcePaths = $record['source_paths'] ?? null;

            if (! is_array($sourcePaths) || $sourcePaths === []) {
                throw new RuntimeException('Every approved redirect record must contain at least one source path.');
            }

            $approvedProducts++;

            foreach ($sourcePaths as $source) {
                if (! is_string($source)) {
                    throw new RuntimeException('Approved redirect source paths must be strings.');
                }

                $this->validatePath($source, 'source');

                if ($source === $target) {
                    throw new RuntimeException('Redirect loop detected because source and target are identical: '.$source);
                }

                if (array_key_exists($source, $rules)) {
                    throw new RuntimeException('Duplicate/conflicting approved redirect source path: '.$source);
                }

                $rules[$source] = $target;
            }
        }

        if ($schemaVersion === 1) {
            if (($manifest['approved_product_count'] ?? null) !== $approvedProducts) {
                throw new RuntimeException('approved_product_count does not match manifest records.');
            }

            if (($manifest['approved_source_path_count'] ?? null) !== count($rules)) {
                throw new RuntimeException('approved_source_path_count does not match expanded source paths.');
            }
        } else {
            if (($manifest['product_count'] ?? null) !== $approvedProducts) {
                throw new RuntimeException('product_count does not match schema v2 manifest records.');
            }

            if (($manifest['source_path_count'] ?? null) !== count($rules)) {
                throw new RuntimeException('source_path_count does not match schema v2 expanded source paths.');
            }
        }

        $sources = array_fill_keys(array_keys($rules), true);

        foreach ($rules as $source => $target) {
            if (isset($sources[$target])) {
                throw new RuntimeException('Redirect chain/cycle risk: approved target is also an approved source path: '.$source.' -> '.$target);
            }
        }

        ksort($rules, SORT_STRING);

        return $rules;
    }

    /**
     * Generate mappings only from a complete, explicitly approved
     * schema-v3 manifest.
     *
     * Original records must match the existing v1 manifest exactly.
     * Parent-product records must match frozen SEO-03B evidence and
     * contain an explicit individual approval decision.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function approvedV3Rules(array $manifest): array
    {
        if (($manifest['validation_only'] ?? null) !== false
            || ($manifest['redirects_installed'] ?? null) !== 0) {
            throw new RuntimeException(
                'Schema v3 requires an approved, non-validation-only manifest.',
            );
        }

        if (($manifest['parent_review_sha256'] ?? null)
                !== ParentProductRedirectApprovalPolicy::REVIEW_SHA256
            || ($manifest['parent_validation_sha256'] ?? null)
                !== ParentProductRedirectApprovalPolicy::VALIDATION_SHA256) {
            throw new RuntimeException(
                'Schema v3 SEO-03B evidence provenance mismatch.',
            );
        }

        $originalPath = base_path(
            'resources/seo/ortezka/product-redirect-approvals.json',
        );

        $originalRaw = @file_get_contents($originalPath);

        if (! is_string($originalRaw)
            || ($manifest['original_manifest_sha256'] ?? null)
                !== hash('sha256', $originalRaw)) {
            throw new RuntimeException(
                'Schema v3 original cohort provenance mismatch.',
            );
        }

        $original = json_decode(
            $originalRaw,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($original)
            || ($original['schema_version'] ?? null) !== 1
            || ($original['approved_product_count'] ?? null) !== 23
            || ($original['approved_source_path_count'] ?? null) !== 36) {
            throw new RuntimeException(
                'Original SEO redirect cohort is invalid.',
            );
        }

        // Reuse the established v1 validation rules. This also checks
        // approval state, active variants, duplicate paths and chains.
        if (count($this->approvedRules($original)) !== 36) {
            throw new RuntimeException(
                'Original cohort failed existing redirect validation.',
            );
        }

        $originalById = [];

        foreach ($original['records'] as $record) {
            if (! is_array($record)) {
                throw new RuntimeException(
                    'Invalid original redirect record.',
                );
            }

            $id = (string) ($record['legacy_product_id'] ?? '');

            if ($id === '' || isset($originalById[$id])) {
                throw new RuntimeException(
                    'Duplicate or missing original legacy ID.',
                );
            }

            $originalById[$id] = $record;
        }

        $policy = app(
            ParentProductRedirectApprovalPolicy::class,
        );

        $reviewed = $policy->reviewedRecords();

        $records = $manifest['records'] ?? null;

        if (! is_array($records)
            || count($records) !== 45
            || ($manifest['product_count'] ?? null) !== 45
            || ($manifest['source_path_count'] ?? null) !== 64) {
            throw new RuntimeException(
                'Schema v3 requires exactly 45 products and 64 source paths.',
            );
        }

        $rules = [];
        $targets = [];
        $seenOriginal = [];
        $seenParent = [];

        foreach ($records as $record) {
            if (! is_array($record)
                || ($record['approved'] ?? null) !== true
                || ($record['decision'] ?? null) !== 'APPROVE_301') {
                throw new RuntimeException(
                    'Schema v3 contains an unapproved redirect.',
                );
            }

            $basis = $record['approval_basis'] ?? null;

            if ($basis === 'exact_active_variant_sku_and_name') {
                $id = (string) ($record['legacy_product_id'] ?? '');

                if (! isset($originalById[$id])
                    || isset($seenOriginal[$id])) {
                    throw new RuntimeException(
                        'Invalid original-cohort identity.',
                    );
                }

                // The only permitted addition to an existing v1 record
                // is its explicit schema-v3 approval-basis discriminator.
                $comparison = $record;

                unset($comparison['approval_basis']);

                if ($comparison !== $originalById[$id]) {
                    throw new RuntimeException(
                        'Schema v3 original approval differs from its frozen record.',
                    );
                }

                $seenOriginal[$id] = true;
            } elseif (
                $basis
                === ParentProductRedirectApprovalPolicy::APPROVAL_BASIS
            ) {
                $id = (string) ($record['legacy_id'] ?? '');

                if (! isset($reviewed[$id])
                    || isset($seenParent[$id])) {
                    throw new RuntimeException(
                        'Invalid parent-product approval identity.',
                    );
                }

                $policy->assertApprovedRecord(
                    $record,
                    $reviewed[$id],
                );

                $seenParent[$id] = true;
            } else {
                throw new RuntimeException(
                    'Unsupported schema v3 approval basis.',
                );
            }

            $target = $record['target_path'] ?? null;

            if (! is_string($target)
                || ! str_starts_with($target, '/products/')) {
                throw new RuntimeException(
                    'Invalid schema v3 target path.',
                );
            }

            $this->validatePath($target, 'target');

            if (isset($targets[$target])) {
                throw new RuntimeException(
                    'Duplicate schema v3 target path: '.$target,
                );
            }

            $targets[$target] = true;

            $sourcePaths = $record['source_paths'] ?? null;

            if (! is_array($sourcePaths) || $sourcePaths === []) {
                throw new RuntimeException(
                    'Missing schema v3 source paths.',
                );
            }

            foreach ($sourcePaths as $source) {
                if (! is_string($source)) {
                    throw new RuntimeException(
                        'Invalid schema v3 source path type.',
                    );
                }

                $this->validatePath($source, 'source');

                if ($source === $target
                    || array_key_exists($source, $rules)) {
                    throw new RuntimeException(
                        'Duplicate or looping schema v3 source: '.$source,
                    );
                }

                $rules[$source] = $target;
            }
        }

        if (count($seenOriginal) !== 23
            || count($seenParent) !== 22
            || count($rules) !== 64) {
            throw new RuntimeException(
                'Incomplete schema v3 approval cohort.',
            );
        }

        // Reject indirect redirects and cycles across both cohorts.
        foreach ($rules as $source => $target) {
            if (array_key_exists($target, $rules)) {
                throw new RuntimeException(
                    'Schema v3 redirect chain: '.$source,
                );
            }
        }

        ksort($rules, SORT_STRING);

        return $rules;
    }

    private function validatePath(string $path, string $label): void
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new RuntimeException('Approved redirect '.$label.' must be an absolute-path reference: '.$path);
        }

        if (str_contains($path, '?') || str_contains($path, '#') || str_contains($path, "\n") || str_contains($path, "\r")) {
            throw new RuntimeException('Approved redirect '.$label.' must not contain query strings, fragments, or newlines: '.$path);
        }
    }

    /** @param array<string, string> $rules */
    private function renderMap(array $rules, string $manifestSha256, string $manifestRelative): string
    {
        $lines = [
            '# GENERATED FILE. DO NOT EDIT BY HAND.',
            '# Source: '.$manifestRelative,
            '# Manifest SHA-256: '.$manifestSha256,
            '# Runtime activation is controlled separately by LEGACY_SEO_REDIRECTS_ENABLED.',
            'map_hash_bucket_size 128;',
            'map $uri $legacy_seo_product_redirect_target {',
            '    default "";',
        ];

        foreach ($rules as $source => $target) {
            $lines[] = sprintf('    "%s" "%s";', $this->nginxString($source), $this->nginxString($target));
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function nginxString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
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
