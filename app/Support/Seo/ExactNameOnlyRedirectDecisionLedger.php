<?php

declare(strict_types=1);

namespace App\Support\Seo;

use JsonException;
use RuntimeException;

/**
 * Frozen SEO-07 owner approval:
 * 149 exact-name-only identities / 259 legacy source paths.
 *
 * This class validates immutable evidence and owner-decision provenance.
 * It does not install redirects and does not authorise deployment.
 */
final class ExactNameOnlyRedirectDecisionLedger
{
    public const SOURCE_MANIFEST_SHA256 =
        'f2330ca1e58677bcd1603e61ea7db6622d17aa0765c5c78e02128813e27fb54b';

    public const REVIEW_SHA256 =
        '77962aef8a2b970a8d43c9bf47c592fca366b4d865eac3f126565b8614cc6735';

    public const DECISION_SHA256 =
        'd6ac552b40d1c800665b88b4830b618ed121008f3266009cbac9a2af430768ee';

    public const BASE_MANIFEST_SHA256 =
        '285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa';

    public const STAGING_VALIDATION_SHA256 =
        '57a0520730907f5d367f93b05a47a1134ef3dc6d8e78600a9ea833b747c820ef';

    public const PRODUCTION_MAP_SHA256 =
        '52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009';

    public const APPROVED_PRODUCTS = 149;

    public const APPROVED_SOURCE_PATHS = 259;

    public const PROSPECTIVE_PRODUCTS = 415;

    public const PROSPECTIVE_SOURCE_PATHS = 659;

    public const APPROVAL_BASIS =
        'unique_exact_name_identity_with_manual_review_and_verified_staging_target';

    public const DECISION_REFERENCE =
        'SEO-07-OWNER-DECISION-20261007';

    private const SOURCE_MANIFEST_PATH =
        'resources/seo/ortezka/review/seo-07b-20261007/'
        .'exact-name-only-candidates-149.json';

    private const REVIEW_PATH =
        'resources/seo/ortezka/review/seo-07d-20261007/'
        .'review-safe-149.json';

    private const DECISION_PATH =
        'resources/seo/ortezka/review/seo-07e-20261007/'
        .'owner-decision-149.json';

    private const BASE_MANIFEST_PATH =
        'resources/seo/ortezka/review/seo-06c-20261006/'
        .'approved-400-manifest.json';

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedRecords(): array
    {
        $source = $this->decodeVerifiedFile(
            self::SOURCE_MANIFEST_PATH,
            self::SOURCE_MANIFEST_SHA256,
        );

        $review = $this->decodeVerifiedFile(
            self::REVIEW_PATH,
            self::REVIEW_SHA256,
        );

        $decision = $this->decodeVerifiedFile(
            self::DECISION_PATH,
            self::DECISION_SHA256,
        );

        $base = $this->decodeVerifiedFile(
            self::BASE_MANIFEST_PATH,
            self::BASE_MANIFEST_SHA256,
        );

        if (
            ($source['schema_version'] ?? null) !== 1
            || ($source['phase'] ?? null) !== 'SEO-07B'
            || ($source['classification'] ?? null) !== 'exact_name_only'
            || ($source['approval_state'] ?? null) !== 'REVIEW_ONLY'
            || ($source['redirects_approved'] ?? null) !== 0
            || ($source['redirects_installed'] ?? null) !== 0
            || ($source['database_writes'] ?? null) !== false
            || ($source['runtime_changes'] ?? null) !== false
            || (
                $source['production_map_sha256'] ?? null
            ) !== self::PRODUCTION_MAP_SHA256
        ) {
            throw new RuntimeException(
                'SEO-07B frozen source manifest is invalid.',
            );
        }

        $sourceSummary = $source['summary'] ?? null;

        if (
            ! is_array($sourceSummary)
            || ($sourceSummary['product_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($sourceSummary['source_path_count'] ?? null)
                !== self::APPROVED_SOURCE_PATHS
            || ($sourceSummary['unique_target_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($sourceSummary['active_target_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($sourceSummary['storefront_reachable_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($sourceSummary['current_production_rule_count'] ?? null)
                !== 400
            || ($sourceSummary['static_blocker_count'] ?? null) !== 0
        ) {
            throw new RuntimeException(
                'SEO-07B frozen source totals are invalid.',
            );
        }

        if (
            ($review['schema_version'] ?? null) !== 1
            || ($review['phase'] ?? null) !== 'SEO-07D'
            || ($review['classification'] ?? null) !== 'exact_name_only'
            || ($review['owner_decision'] ?? null) !== 'PENDING'
            || ($review['approval_state'] ?? null) !== 'REVIEW_ONLY'
            || ($review['redirects_approved'] ?? null) !== 0
            || ($review['redirects_installed'] ?? null) !== 0
            || (
                $review['source_manifest_sha256'] ?? null
            ) !== self::SOURCE_MANIFEST_SHA256
            || (
                $review['staging_validation_report_sha256'] ?? null
            ) !== self::STAGING_VALIDATION_SHA256
        ) {
            throw new RuntimeException(
                'SEO-07D frozen review ledger is invalid.',
            );
        }

        $reviewSummary = $review['summary'] ?? null;

        if (
            ! is_array($reviewSummary)
            || ($reviewSummary['product_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($reviewSummary['source_path_count'] ?? null)
                !== self::APPROVED_SOURCE_PATHS
            || ($reviewSummary['unique_target_count'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($reviewSummary['safe_for_owner_decision'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($reviewSummary['hold'] ?? null) !== 0
            || ($reviewSummary['approved'] ?? null) !== 0
            || ($reviewSummary['manual_focus_review_count'] ?? null) !== 4
            || ($reviewSummary['zero_active_variant_count'] ?? null) !== 75
        ) {
            throw new RuntimeException(
                'SEO-07D frozen review totals are invalid.',
            );
        }

        if (
            ($decision['schema_version'] ?? null) !== 1
            || ($decision['phase'] ?? null) !== 'SEO-07E'
            || ($decision['decision_record_date'] ?? null)
                !== '2026-10-07'
            || ($decision['decision_source'] ?? null)
                !== 'User message: APPROVE SEO-07 149/259'
            || ($decision['decision_reference'] ?? null)
                !== self::DECISION_REFERENCE
            || ($decision['approved_by'] ?? null)
                !== 'Tomasz Maciejczuk'
            || ($decision['classification'] ?? null)
                !== 'exact_name_only'
            || ($decision['decision'] ?? null)
                !== 'APPROVE_301'
            || ($decision['approval_basis'] ?? null)
                !== self::APPROVAL_BASIS
            || ($decision['deployment_authorized'] ?? null) !== false
            || ($decision['redirects_installed_by_this_record'] ?? null)
                !== 0
            || ($decision['nginx_map_changed_by_this_record'] ?? null)
                !== false
            || ($decision['runtime_changed_by_this_record'] ?? null)
                !== false
            || (
                $decision['frozen_review_sha256'] ?? null
            ) !== self::REVIEW_SHA256
            || (
                $decision['staging_validation_report_sha256'] ?? null
            ) !== self::STAGING_VALIDATION_SHA256
        ) {
            throw new RuntimeException(
                'SEO-07E owner-decision provenance is invalid.',
            );
        }

        $decisionCounts = $decision['counts'] ?? null;

        if (
            ! is_array($decisionCounts)
            || ($decisionCounts['approved_identities'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($decisionCounts['approved_source_paths'] ?? null)
                !== self::APPROVED_SOURCE_PATHS
            || ($decisionCounts['approved_unique_targets'] ?? null)
                !== self::APPROVED_PRODUCTS
            || ($decisionCounts['held_identities'] ?? null) !== 0
            || ($decisionCounts['manual_focus_reviewed_identities'] ?? null)
                !== 4
            || ($decisionCounts['zero_active_variant_identities'] ?? null)
                !== 75
            || ($decisionCounts['current_approved_products'] ?? null)
                !== 266
            || ($decisionCounts['current_approved_source_paths'] ?? null)
                !== 400
            || ($decisionCounts['prospective_combined_products'] ?? null)
                !== self::PROSPECTIVE_PRODUCTS
            || ($decisionCounts['prospective_combined_source_paths'] ?? null)
                !== self::PROSPECTIVE_SOURCE_PATHS
        ) {
            throw new RuntimeException(
                'SEO-07E owner-decision totals are invalid.',
            );
        }

        $governance = $decision['governance'] ?? null;

        if (
            ! is_array($governance)
            || ($governance['owner_approval_received'] ?? null) !== true
            || ($governance['redirect_evidence_authorized'] ?? null)
                !== true
            || ($governance['candidate_map_generation_authorized'] ?? null)
                !== true
            || ($governance['staging_candidate_activation_authorized'] ?? null)
                !== false
            || ($governance['production_deployment_authorized'] ?? null)
                !== false
            || ($governance['production_redirect_activation_authorized'] ?? null)
                !== false
        ) {
            throw new RuntimeException(
                'SEO-07E governance state is invalid.',
            );
        }

        if (
            ($base['schema_version'] ?? null) !== 4
            || ($base['product_count'] ?? null) !== 266
            || ($base['source_path_count'] ?? null) !== 400
            || ($base['deployment_authorized'] ?? null) !== false
            || ($base['redirects_installed'] ?? null) !== 0
        ) {
            throw new RuntimeException(
                'SEO-07 approved-400 base manifest is invalid.',
            );
        }

        $sourceRecords = $source['records'] ?? null;
        $reviewRecords = $review['records'] ?? null;
        $decisionIds = $decision['approved_legacy_ids'] ?? null;
        $baseRecords = $base['records'] ?? null;

        if (
            ! is_array($sourceRecords)
            || count($sourceRecords) !== self::APPROVED_PRODUCTS
            || ! is_array($reviewRecords)
            || count($reviewRecords) !== self::APPROVED_PRODUCTS
            || ! is_array($decisionIds)
            || count($decisionIds) !== self::APPROVED_PRODUCTS
            || ! is_array($baseRecords)
            || count($baseRecords) !== 266
        ) {
            throw new RuntimeException(
                'SEO-07 frozen record membership is invalid.',
            );
        }

        $sourceById = [];
        $sourceIdOrder = [];

        foreach ($sourceRecords as $record) {
            if (! is_array($record)) {
                throw new RuntimeException(
                    'Invalid SEO-07B source record.',
                );
            }

            $id = (string) ($record['legacy_id'] ?? '');

            if ($id === '' || isset($sourceById[$id])) {
                throw new RuntimeException(
                    'Duplicate or missing SEO-07B legacy ID.',
                );
            }

            $sourceById[$id] = $record;
            $sourceIdOrder[] = $id;
        }

        $baseSources = [];
        $baseTargets = [];

        foreach ($baseRecords as $record) {
            if (! is_array($record)) {
                throw new RuntimeException(
                    'Invalid approved-400 base record.',
                );
            }

            $target = $record['target_path'] ?? null;
            $paths = $record['source_paths'] ?? null;

            if (! is_string($target) || ! is_array($paths)) {
                throw new RuntimeException(
                    'Invalid approved-400 redirect record.',
                );
            }

            $baseTargets[$target] = true;

            foreach ($paths as $path) {
                if (! is_string($path)) {
                    throw new RuntimeException(
                        'Invalid approved-400 source path.',
                    );
                }

                $baseSources[$path] = true;
            }
        }

        if (
            count($baseTargets) !== 266
            || count($baseSources) !== 400
        ) {
            throw new RuntimeException(
                'Approved-400 base cardinality mismatch.',
            );
        }

        $approved = [];
        $reviewIds = [];
        $reviewIdOrder = [];
        $newSources = [];
        $newTargets = [];
        $zeroVariantCount = 0;
        $manualFocusCount = 0;

        foreach ($reviewRecords as $reviewRecord) {
            if (! is_array($reviewRecord)) {
                throw new RuntimeException(
                    'Invalid SEO-07D review record.',
                );
            }

            $id = (string) ($reviewRecord['legacy_id'] ?? '');

            if ($id === ''
                || isset($reviewIds[$id])
                || ! isset($sourceById[$id])) {
                throw new RuntimeException(
                    'Invalid SEO-07 review membership for legacy ID '.$id,
                );
            }

            $sourceRecord = $sourceById[$id];

            if (
                ($sourceRecord['classification'] ?? null)
                    !== 'exact_name_only'
                || ($sourceRecord['review_class'] ?? null)
                    !== 'review_active_exact_name_only'
                || ($sourceRecord['decision'] ?? null)
                    !== 'REVIEW_EXACT_NAME_ONLY'
                || ($sourceRecord['approved'] ?? null) !== false
                || ($sourceRecord['redirect_approved'] ?? null) !== false
                || ($sourceRecord['manual_review_required'] ?? null)
                    !== true
                || ($sourceRecord['legacy_name_unique'] ?? null) !== true
                || ($sourceRecord['target_product_status'] ?? null)
                    !== 'active'
                || ($sourceRecord['target_storefront_reachable'] ?? null)
                    !== true
            ) {
                throw new RuntimeException(
                    'SEO-07B source evidence invalid for legacy ID '.$id,
                );
            }

            foreach ([
                'legacy_h1',
                'legacy_index',
                'target_product_id',
                'target_product_name',
                'target_path',
                'source_paths',
                'source_path_count',
                'shared_semantic_token_count',
                'target_active_variant_count',
            ] as $key) {
                if (
                    ($reviewRecord[$key] ?? null)
                    !== ($sourceRecord[$key] ?? null)
                ) {
                    throw new RuntimeException(
                        'SEO-07B/SEO-07D evidence mismatch for '
                        .$key.' on legacy ID '.$id,
                    );
                }
            }

            if (
                ($reviewRecord['review_decision'] ?? null)
                    !== 'SAFE_FOR_OWNER_DECISION'
                || ($reviewRecord['hold'] ?? null) !== false
                || ($reviewRecord['approved'] ?? null) !== false
                || ($reviewRecord['redirect_approved'] ?? null) !== false
                || ($reviewRecord['owner_decision'] ?? null) !== 'PENDING'
            ) {
                throw new RuntimeException(
                    'SEO-07D review state invalid for legacy ID '.$id,
                );
            }

            $target = $sourceRecord['target_path'] ?? null;
            $paths = $sourceRecord['source_paths'] ?? null;

            if (
                ! is_string($target)
                || ! str_starts_with($target, '/products/')
                || ! is_array($paths)
                || $paths === []
                || ($sourceRecord['source_path_count'] ?? null)
                    !== count($paths)
            ) {
                throw new RuntimeException(
                    'SEO-07 target/source evidence invalid for legacy ID '.$id,
                );
            }

            $this->validatePath($target, 'target');

            if (
                isset($newTargets[$target])
                || isset($baseTargets[$target])
                || isset($baseSources[$target])
            ) {
                throw new RuntimeException(
                    'SEO-07 target collision or chain risk: '.$target,
                );
            }

            $newTargets[$target] = true;

            foreach ($paths as $path) {
                if (! is_string($path)) {
                    throw new RuntimeException(
                        'SEO-07 source path must be a string.',
                    );
                }

                $this->validatePath($path, 'source');

                if (
                    $path === $target
                    || isset($newSources[$path])
                    || isset($baseSources[$path])
                    || isset($baseTargets[$path])
                ) {
                    throw new RuntimeException(
                        'SEO-07 source collision or chain risk: '.$path,
                    );
                }

                $newSources[$path] = true;
            }

            if (
                (int) ($sourceRecord['target_active_variant_count'] ?? -1)
                === 0
            ) {
                $zeroVariantCount++;
            }

            if (($reviewRecord['manual_focus_review'] ?? null) === true) {
                $manualFocusCount++;
            }

            $reviewIds[$id] = true;
            $reviewIdOrder[] = $id;

            $approved[] = [
                'legacy_id' => $id,
                'legacy_index' => (string) $sourceRecord['legacy_index'],
                'legacy_h1' => (string) $sourceRecord['legacy_h1'],
                'source_paths' => array_values($paths),
                'source_path_count' => count($paths),
                'classification' => 'exact_name_only',
                'evidence_tier' =>
                    'TIER_C_EXACT_NAME_ONLY_MANUAL_REVIEW',
                'review_class' =>
                    'review_exact_name_only_owner_approved',
                'name_relationship' =>
                    (string) $reviewRecord['name_relationship'],
                'shared_semantic_token_count' =>
                    (int) $sourceRecord['shared_semantic_token_count'],
                'target_product_id' =>
                    (string) $sourceRecord['target_product_id'],
                'target_product_name' =>
                    (string) $sourceRecord['target_product_name'],
                'target_path' =>
                    $target,
                'target_external_source' =>
                    (string) ($sourceRecord['target_external_source'] ?? ''),
                'target_external_parent_sku' =>
                    (string) ($sourceRecord['target_external_parent_sku'] ?? ''),
                'target_product_status' =>
                    'active',
                'target_storefront_reachable' =>
                    true,
                'target_active_variant_count' =>
                    (int) $sourceRecord['target_active_variant_count'],
                'staging_target_validation' =>
                    'PASS',
                'decision' =>
                    'APPROVE_301',
                'approved' =>
                    true,
                'redirect_approved' =>
                    true,
                'approval_basis' =>
                    self::APPROVAL_BASIS,
                'approved_by' =>
                    'Tomasz Maciejczuk',
                'approval_reference' =>
                    self::DECISION_REFERENCE,
            ];
        }

        if (
            count($approved) !== self::APPROVED_PRODUCTS
            || count($newTargets) !== self::APPROVED_PRODUCTS
            || count($newSources) !== self::APPROVED_SOURCE_PATHS
            || $zeroVariantCount !== 75
            || $manualFocusCount !== 4
        ) {
            throw new RuntimeException(
                'SEO-07 approved cohort totals are inconsistent.',
            );
        }

        foreach ($newTargets as $target => $_) {
            if (isset($newSources[$target])) {
                throw new RuntimeException(
                    'SEO-07 internal redirect chain/cycle risk: '.$target,
                );
            }
        }

        $orderedDecisionIds = array_map(
            static fn (mixed $id): string => (string) $id,
            array_values($decisionIds),
        );

        if (
            $orderedDecisionIds !== $reviewIdOrder
            || $sourceIdOrder !== $reviewIdOrder
        ) {
            throw new RuntimeException(
                'SEO-07 owner-decision membership differs from frozen evidence.',
            );
        }

        if (
            count($baseTargets) + count($newTargets)
                !== self::PROSPECTIVE_PRODUCTS
            || count($baseSources) + count($newSources)
                !== self::PROSPECTIVE_SOURCE_PATHS
        ) {
            throw new RuntimeException(
                'SEO-07 prospective union totals are inconsistent.',
            );
        }

        return $approved;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeVerifiedFile(
        string $relativePath,
        string $expectedSha,
    ): array {
        $raw = @file_get_contents(
            base_path($relativePath),
        );

        if (
            ! is_string($raw)
            || ! hash_equals(
                $expectedSha,
                hash('sha256', $raw),
            )
        ) {
            throw new RuntimeException(
                'Missing or modified SEO-07 evidence: '.$relativePath,
            );
        }

        try {
            $decoded = json_decode(
                $raw,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Invalid SEO-07 JSON evidence: '.$relativePath,
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'SEO-07 evidence must decode to an object: '.$relativePath,
            );
        }

        return $decoded;
    }

    private function validatePath(
        string $path,
        string $label,
    ): void {
        if (
            $path === ''
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || strpbrk($path, "?#\r\n") !== false
        ) {
            throw new RuntimeException(
                'Invalid SEO-07 '.$label.' path: '.$path,
            );
        }
    }
}
