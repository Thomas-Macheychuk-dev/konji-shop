<?php

declare(strict_types=1);

namespace App\Support\Seo;

use RuntimeException;

/**
 * Frozen SEO-06 owner approval:
 * 221 identifier-agreement identities / 336 legacy source paths.
 *
 * This class validates evidence and decision provenance only.
 * It does not install redirects or authorise deployment.
 */
final class SemanticSupportRedirectDecisionLedger
{
    public const REVIEW_SHA256 =
        '0dd5beb4952dab4d812a404d937fddde801777f74fff74cca4bf771142cc55f5';

    public const DECISION_SHA256 =
        'a9ffee7788002bd3abd11c854a89cf9d5fb7b83c7c5096189d73ca015eea4bfd';

    public const BASE_MANIFEST_SHA256 =
        'cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7';

    public const APPROVED_PRODUCTS = 221;

    public const APPROVED_SOURCE_PATHS = 336;

    public const PROSPECTIVE_PRODUCTS = 266;

    public const PROSPECTIVE_SOURCE_PATHS = 400;

    public const APPROVAL_BASIS =
        'unique_identifier_agreement_with_semantic_support_and_healthy_active_target';

    private const REVIEW_PATH =
        'resources/seo/ortezka/review/seo-06b-20261006/'
        .'semantic-support-approved-221.json';

    private const DECISION_PATH =
        'resources/seo/ortezka/review/seo-06b-20261006/'
        .'owner-decision-221.json';

    private const BASE_MANIFEST_PATH =
        'resources/seo/ortezka/review/seo-05h-20261006/'
        .'approved-64-manifest.json';

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedRecords(): array
    {
        $baseRaw = $this->verifiedFile(
            self::BASE_MANIFEST_PATH,
            self::BASE_MANIFEST_SHA256,
        );

        $base = json_decode(
            $baseRaw,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            ! is_array($base)
            || ($base['schema_version'] ?? null) !== 3
            || ($base['product_count'] ?? null) !== 45
            || ($base['source_path_count'] ?? null) !== 64
        ) {
            throw new RuntimeException(
                'SEO-06 base approved-64 manifest is invalid.',
            );
        }

        $reviewRaw = $this->verifiedFile(
            self::REVIEW_PATH,
            self::REVIEW_SHA256,
        );

        $review = json_decode(
            $reviewRaw,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            ! is_array($review)
            || ($review['schema_version'] ?? null) !== 1
            || ($review['phase'] ?? null) !== 'SEO-06B'
            || ($review['validation_only'] ?? null) !== false
            || ($review['deployment_authorized'] ?? null) !== false
            || ($review['redirects_installed'] ?? null) !== 0
            || ($review['classification'] ?? null)
                !== 'identifier_agreement'
            || ($review['review_class'] ?? null)
                !== 'review_identifier_agreement_semantic_support'
            || ($review['approval_basis'] ?? null)
                !== self::APPROVAL_BASIS
            || ($review['identity_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($review['source_path_count'] ?? null)
                !== self::APPROVED_SOURCE_PATHS
            || ($review['target_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || (
                $review['source_provenance']
                    ['approved_64_manifest_sha256'] ?? null
            ) !== self::BASE_MANIFEST_SHA256
        ) {
            throw new RuntimeException(
                'SEO-06 frozen semantic-support evidence is invalid.',
            );
        }

        $decisionRaw = $this->verifiedFile(
            self::DECISION_PATH,
            self::DECISION_SHA256,
        );

        $decision = json_decode(
            $decisionRaw,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            ! is_array($decision)
            || ($decision['schema_version'] ?? null) !== 1
            || ($decision['phase'] ?? null) !== 'SEO-06C'
            || ($decision['decision_record_date'] ?? null)
                !== '2026-10-06'
            || ($decision['decision_source'] ?? null)
                !== 'User message: APPROVE SEO-06 221/336'
            || ($decision['decision_reference'] ?? null)
                !== 'SEO-06-OWNER-DECISION-20261006'
            || ($decision['approved_by'] ?? null)
                !== 'Tomasz Maciejczuk'
            || ($decision['deployment_authorized'] ?? null) !== false
            || ($decision['redirects_installed_by_this_record'] ?? null)
                !== 0
            || ($decision['frozen_review_sha256'] ?? null)
                !== self::REVIEW_SHA256
            || ($decision['decision'] ?? null) !== 'APPROVE_301'
        ) {
            throw new RuntimeException(
                'SEO-06 owner decision provenance is invalid.',
            );
        }

        $counts = $decision['counts'] ?? null;

        if (
            ! is_array($counts)
            || ($counts['approved_identities'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($counts['approved_source_paths'] ?? null)
                !== self::APPROVED_SOURCE_PATHS
            || ($counts['held_name_divergence_identities'] ?? null) !== 32
            || ($counts['held_name_divergence_source_paths'] ?? null) !== 53
            || ($counts['held_inactive_variant_identities'] ?? null) !== 205
            || ($counts['held_inactive_variant_source_paths'] ?? null) !== 331
            || ($counts['current_approved_products'] ?? null) !== 45
            || ($counts['current_approved_source_paths'] ?? null) !== 64
            || ($counts['prospective_combined_products'] ?? null)
                !== self::PROSPECTIVE_PRODUCTS
            || ($counts['prospective_combined_source_paths'] ?? null)
                !== self::PROSPECTIVE_SOURCE_PATHS
        ) {
            throw new RuntimeException(
                'SEO-06 owner decision cohort counts are invalid.',
            );
        }

        $records = $review['records'] ?? null;

        if (
            ! is_array($records)
            || count($records) !== self::APPROVED_PRODUCTS
        ) {
            throw new RuntimeException(
                'SEO-06 frozen review must contain exactly 221 records.',
            );
        }

        $decisionIds = $decision['approved_legacy_ids'] ?? null;

        if (
            ! is_array($decisionIds)
            || count($decisionIds) !== self::APPROVED_PRODUCTS
        ) {
            throw new RuntimeException(
                'SEO-06 owner decision identity list is invalid.',
            );
        }

        $seenIds = [];
        $seenSources = [];
        $seenTargets = [];
        $recordIds = [];
        $sourceCount = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException(
                    'Invalid SEO-06 frozen approval record.',
                );
            }

            $id = (string) ($record['legacy_id'] ?? '');

            if ($id === '' || isset($seenIds[$id])) {
                throw new RuntimeException(
                    'Missing or duplicate SEO-06 legacy identity: '.$id,
                );
            }

            $seenIds[$id] = true;
            $recordIds[] = $id;

            if (
                ($record['classification'] ?? null)
                    !== 'identifier_agreement'
                || ($record['evidence_tier'] ?? null)
                    !== 'TIER_B_IDENTIFIER_AGREEMENT'
                || ($record['review_class'] ?? null)
                    !== 'review_identifier_agreement_semantic_support'
                || ($record['decision'] ?? null) !== 'APPROVE_301'
                || ($record['approved'] ?? null) !== true
                || ($record['approval_basis'] ?? null)
                    !== self::APPROVAL_BASIS
                || ($record['approved_by'] ?? null)
                    !== 'Tomasz Maciejczuk'
                || ($record['approval_reference'] ?? null)
                    !== 'SEO-06-OWNER-DECISION-20261006'
                || ($record['target_product_status'] ?? null) !== 'active'
                || ($record['target_storefront_reachable'] ?? null) !== true
                || ! is_numeric(
                    $record['shared_semantic_token_count'] ?? null,
                )
                || (int) $record['shared_semantic_token_count'] < 1
            ) {
                throw new RuntimeException(
                    'Invalid SEO-06 approved evidence for legacy ID '.$id,
                );
            }

            $legacyIndex = strtoupper(
                trim((string) ($record['legacy_index'] ?? '')),
            );

            $targetSku = strtoupper(
                trim((string) (
                    $record['target_external_parent_sku'] ?? ''
                )),
            );

            if ($legacyIndex === '' || $legacyIndex !== $targetSku) {
                throw new RuntimeException(
                    'SEO-06 identifier disagreement for legacy ID '.$id,
                );
            }

            $activeVariantCounts =
                $record['active_variant_counts'] ?? null;

            if (
                ! is_array($activeVariantCounts)
                || $activeVariantCounts === []
            ) {
                throw new RuntimeException(
                    'SEO-06 active variant evidence is missing for '.$id,
                );
            }

            foreach ($activeVariantCounts as $count) {
                if (! is_int($count) || $count < 1) {
                    throw new RuntimeException(
                        'SEO-06 target lacks an active variant for '.$id,
                    );
                }
            }

            $target = $record['target_path'] ?? null;

            if (
                ! is_string($target)
                || ! str_starts_with($target, '/products/')
                || isset($seenTargets[$target])
            ) {
                throw new RuntimeException(
                    'Invalid or duplicate SEO-06 target for '.$id,
                );
            }

            $seenTargets[$target] = true;

            $paths = $record['source_paths'] ?? null;

            if (
                ! is_array($paths)
                || $paths === []
                || ($record['source_path_count'] ?? null)
                    !== count($paths)
            ) {
                throw new RuntimeException(
                    'Invalid SEO-06 source paths for '.$id,
                );
            }

            foreach ($paths as $source) {
                if (
                    ! is_string($source)
                    || $source === ''
                    || ! str_starts_with($source, '/')
                    || str_starts_with($source, '//')
                    || strpbrk($source, "?#\r\n") !== false
                    || $source === $target
                    || isset($seenSources[$source])
                ) {
                    throw new RuntimeException(
                        'Invalid or duplicate SEO-06 source: '
                        .(string) $source,
                    );
                }

                $seenSources[$source] = true;
                $sourceCount++;
            }
        }

        if ($recordIds !== $decisionIds) {
            throw new RuntimeException(
                'SEO-06 owner decision membership differs from frozen evidence.',
            );
        }

        if (
            count($seenTargets) !== self::APPROVED_PRODUCTS
            || $sourceCount !== self::APPROVED_SOURCE_PATHS
        ) {
            throw new RuntimeException(
                'SEO-06 approved cohort totals are inconsistent.',
            );
        }

        foreach ($seenTargets as $target => $_) {
            if (isset($seenSources[$target])) {
                throw new RuntimeException(
                    'SEO-06 redirect chain/cycle risk: '.$target,
                );
            }
        }

        /** @var list<array<string, mixed>> $records */
        return $records;
    }

    private function verifiedFile(
        string $relativePath,
        string $expectedHash,
    ): string {
        $raw = @file_get_contents(base_path($relativePath));

        if (
            ! is_string($raw)
            || ! hash_equals(
                $expectedHash,
                hash('sha256', $raw),
            )
        ) {
            throw new RuntimeException(
                'Missing or modified SEO-06 evidence: '.$relativePath,
            );
        }

        return $raw;
    }
}
