<?php

declare(strict_types=1);

namespace App\Support\Seo;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class ParentProductRedirectApprovalPolicy
{
    public const APPROVAL_BASIS = 'exact_parent_sku_and_name_with_active_variants';

    public const REVIEW_SHA256 =
        'f124143b75f74deb3034b9d5544c1696b64254ebfab751dc0e86aaff33e96edc';

    public const VALIDATION_SHA256 =
        '811248a3708f7d3be504a330762fa04e4e7838062c72ca8050c198ffcafe69e3';

    private const REVIEW_PATH =
        'resources/seo/ortezka/review/parent-product-redirect-review-20261003.json';

    private const VALIDATION_PATH =
        'resources/seo/ortezka/review/parent-target-validation-20261003.json';

    /**
     * Return the 22 frozen, validated, still-unapproved parent-product records.
     *
     * @return array<string, array<string, mixed>>
     */
    public function reviewedRecords(): array
    {
        $review = $this->readVerifiedJson(
            self::REVIEW_PATH,
            self::REVIEW_SHA256,
        );

        $validation = $this->readVerifiedJson(
            self::VALIDATION_PATH,
            self::VALIDATION_SHA256,
        );

        if (
            ($review['validation_only'] ?? null) !== true
            || ($review['candidate_product_count'] ?? null) !== 22
            || ($review['candidate_source_path_count'] ?? null) !== 28
            || ($review['approved_product_count'] ?? null) !== 0
            || ($review['redirects_installed'] ?? null) !== 0
            || ($review['validation_sha256'] ?? null) !== self::VALIDATION_SHA256
        ) {
            throw new RuntimeException('SEO-03B frozen review metadata is invalid.');
        }

        if (
            ($validation['summary']['products'] ?? null) !== 22
            || ($validation['summary']['source_paths'] ?? null) !== 28
            || ($validation['summary']['passed'] ?? null) !== 22
            || ($validation['summary']['failed'] ?? null) !== 0
        ) {
            throw new RuntimeException('SEO-03B target validation summary is invalid.');
        }

        $validated = [];

        foreach ($validation['records'] ?? [] as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Invalid target validation record.');
            }

            $id = (string) ($record['legacy_id'] ?? '');

            if ($id === '' || isset($validated[$id])) {
                throw new RuntimeException('Duplicate or missing validation legacy ID.');
            }

            $validated[$id] = $record;
        }

        $records = [];
        $sources = [];
        $targets = [];

        foreach ($review['records'] ?? [] as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Invalid parent-product review record.');
            }

            $id = (string) ($record['legacy_id'] ?? '');

            if ($id === '' || isset($records[$id])) {
                throw new RuntimeException('Duplicate or missing review legacy ID.');
            }

            if (
                ($record['approval_basis'] ?? null) !== self::APPROVAL_BASIS
                || ($record['approved'] ?? null) !== false
                || ($record['decision'] ?? null) !== 'PENDING_HUMAN_APPROVAL'
                || ($record['target_validation'] ?? null) !== 'PASS'
                || ! is_numeric($record['active_variant_count'] ?? null)
                || (int) $record['active_variant_count'] < 1
            ) {
                throw new RuntimeException('Invalid pending approval evidence for legacy ID '.$id);
            }

            $legacyIndex = strtoupper(trim((string) ($record['legacy_index'] ?? '')));
            $parentSku = strtoupper(trim((string) ($record['target_parent_sku'] ?? '')));

            if ($legacyIndex === '' || $legacyIndex !== $parentSku) {
                throw new RuntimeException('Parent SKU identity mismatch for legacy ID '.$id);
            }

            $target = $record['target_path'] ?? null;

            if (
                ! is_string($target)
                || ! str_starts_with($target, '/products/')
                || isset($targets[$target])
            ) {
                throw new RuntimeException('Invalid or duplicate target for legacy ID '.$id);
            }

            $targets[$target] = true;

            $paths = $record['source_paths'] ?? null;

            if (! is_array($paths) || $paths === []) {
                throw new RuntimeException('Missing source paths for legacy ID '.$id);
            }

            foreach ($paths as $path) {
                if (
                    ! is_string($path)
                    || ! str_starts_with($path, '/')
                    || str_starts_with($path, '//')
                    || strpbrk($path, "?#\r\n") !== false
                    || isset($sources[$path])
                    || $path === $target
                ) {
                    throw new RuntimeException('Invalid or duplicate source for legacy ID '.$id);
                }

                $sources[$path] = true;
            }

            $http = $validated[$id] ?? null;

            if (
                ! is_array($http)
                || ($http['failures'] ?? null) !== []
                || ($http['http_status'] ?? null) !== '200'
                || ($http['target_path'] ?? null) !== $target
                || ($http['canonical'] ?? null) !== 'https://ortezka.pl'.$target
                || ($http['source_paths'] ?? null) !== $paths
            ) {
                throw new RuntimeException('Missing or invalid HTTP evidence for legacy ID '.$id);
            }

            $records[$id] = $record;
        }

        if (
            count($records) !== 22
            || count($validated) !== 22
            || count($sources) !== 28
            || count($targets) !== 22
        ) {
            throw new RuntimeException('SEO-03B frozen cohort counts are inconsistent.');
        }

        return $records;
    }

    /**
     * Validate an explicitly approved record against its unchanged frozen review.
     *
     * Approval metadata is deliberately separate from matching evidence.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $frozen
     */
    public function assertApprovedRecord(array $submitted, array $frozen): void
    {
        if (
            ($submitted['approved'] ?? null) !== true
            || ($submitted['decision'] ?? null) !== 'APPROVE_301'
            || ($submitted['approval_basis'] ?? null) !== self::APPROVAL_BASIS
        ) {
            throw new RuntimeException('Parent-product redirect lacks explicit approval.');
        }

        $reviewer = $submitted['approved_by'] ?? null;
        $approvedAt = $submitted['approved_at'] ?? null;
        $reference = $submitted['approval_reference'] ?? null;

        if (
            ! is_string($reviewer)
            || trim($reviewer) === ''
            || ! is_string($reference)
            || trim($reference) === ''
            || ! is_string($approvedAt)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
                $approvedAt,
            ) !== 1
            || DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $approvedAt) === false
        ) {
            throw new RuntimeException('Parent-product approval metadata is incomplete.');
        }

        // Only these three fields may be added or changed by the reviewer,
        // in addition to the explicit approved/decision transition.
        $comparison = $submitted;

        unset(
            $comparison['approved_by'],
            $comparison['approved_at'],
            $comparison['approval_reference'],
        );

        $comparison['approved'] = false;
        $comparison['decision'] = 'PENDING_HUMAN_APPROVAL';

        if ($comparison !== $frozen) {
            throw new RuntimeException(
                'Approved parent-product record differs from frozen review evidence.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function readVerifiedJson(string $relativePath, string $expectedHash): array
    {
        $path = base_path($relativePath);

        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Missing frozen SEO-03B evidence: '.$relativePath);
        }

        if (! hash_equals($expectedHash, hash('sha256', $contents))) {
            throw new RuntimeException('Frozen SEO-03B evidence hash mismatch: '.$relativePath);
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid frozen SEO-03B JSON: '.$relativePath);
        }

        return $decoded;
    }
}
