<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
            $rendered = $this->renderMap($rules, hash('sha256', $rawManifest));

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
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported redirect approval manifest schema version.');
        }

        if (($manifest['redirects_installed'] ?? null) !== 0) {
            throw new RuntimeException('Approval manifest must remain evidence-only with redirects_installed=0.');
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

            if (($record['target_product_status'] ?? null) !== 'active' || ($record['matched_variant_status'] ?? null) !== 'active') {
                throw new RuntimeException('Approval manifest contains an inactive product or matched variant.');
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

        if (($manifest['approved_product_count'] ?? null) !== $approvedProducts) {
            throw new RuntimeException('approved_product_count does not match manifest records.');
        }

        if (($manifest['approved_source_path_count'] ?? null) !== count($rules)) {
            throw new RuntimeException('approved_source_path_count does not match expanded source paths.');
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
    private function renderMap(array $rules, string $manifestSha256): string
    {
        $lines = [
            '# GENERATED FILE. DO NOT EDIT BY HAND.',
            '# Source: resources/seo/ortezka/product-redirect-approvals.json',
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
