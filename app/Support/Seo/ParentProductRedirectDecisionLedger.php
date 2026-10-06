<?php

declare(strict_types=1);

namespace App\Support\Seo;

use RuntimeException;

/**
 * Current October 6, 2026 owner decisions: 22 approved / 0 on HOLD.
 *
 * Documentary decisions do not install redirects or authorise deployment.
 */
final class ParentProductRedirectDecisionLedger
{
    public const DECISION_SHA256 = '16eaa929ed3957d853acfc3e88d853ea11b22d97b4e5e98991a7647df7d28f56';

    public const DOCUMENTARY_REVIEW_SHA256 = '15335ec4ee46893cdbdf67e87ca7caa5c68d2cfe6adef0127bbf457afa94158a';

    public const APPROVED_PRODUCTS = 22;

    public const APPROVED_SOURCE_PATHS = 28;

    public const HELD_PRODUCTS = 0;

    public const HELD_SOURCE_PATHS = 0;

    private const DECISION_PATH = 'resources/seo/ortezka/review/parent-product-decisions-20261006.json';

    private const DOCUMENTARY_REVIEW_PATH = 'resources/seo/ortezka/review/parent-product-documentary-review-20261003.csv';

    /**
     * Resolve the exact owner-accepted subset, cross-checked against the
     * separately frozen original parent-product match and HTTP evidence.
     *
     * @param  array<string, array<string, mixed>>  $frozenReviewedRecords
     * @return array<string, array<string, mixed>> Approved frozen records keyed by legacy ID.
     */
    public function approvedRecords(array $frozenReviewedRecords): array
    {
        $reviewCsv = $this->verifiedFile(
            self::DOCUMENTARY_REVIEW_PATH,
            self::DOCUMENTARY_REVIEW_SHA256,
        );

        if ($reviewCsv === '') {
            throw new RuntimeException('SEO-03B documentary review is empty.');
        }

        $raw = $this->verifiedFile(self::DECISION_PATH, self::DECISION_SHA256);
        $ledger = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($ledger)
            || ($ledger['schema_version'] ?? null) !== 1
            || ($ledger['decision_record_date'] ?? null) !== '2026-10-06'
            || ($ledger['decision_source'] ?? null) !== 'User message: Approved'
            || ($ledger['deployment_authorized'] ?? null) !== false
            || ($ledger['redirects_installed_by_this_record'] ?? null) !== 0
            || ($ledger['frozen_parent_review_sha256'] ?? null) !== ParentProductRedirectApprovalPolicy::REVIEW_SHA256
            || ($ledger['frozen_parent_target_validation_sha256'] ?? null) !== ParentProductRedirectApprovalPolicy::VALIDATION_SHA256
            || ($ledger['documentary_review_csv_sha256'] ?? null) !== self::DOCUMENTARY_REVIEW_SHA256) {
            throw new RuntimeException('SEO-03B decision ledger provenance or governance metadata is invalid.');
        }

        $counts = $ledger['counts'] ?? null;

        if (! is_array($counts)
            || ($counts['approved_products'] ?? null) !== self::APPROVED_PRODUCTS
            || ($counts['approved_source_paths'] ?? null) !== self::APPROVED_SOURCE_PATHS
            || ($counts['held_products'] ?? null) !== self::HELD_PRODUCTS
            || ($counts['held_source_paths'] ?? null) !== self::HELD_SOURCE_PATHS
            || ($counts['combined_with_original_approved_products'] ?? null) !== 45
            || ($counts['combined_with_original_source_paths'] ?? null) !== 64) {
            throw new RuntimeException('SEO-03B decision ledger cohort counts are invalid.');
        }

        $entries = $ledger['records'] ?? null;

        if (! is_array($entries) || count($entries) !== 22 || count($frozenReviewedRecords) !== 22) {
            throw new RuntimeException('SEO-03B decision ledger must cover all 22 frozen review records.');
        }

        $seen = [];
        $approved = [];
        $approvedPaths = 0;
        $heldProducts = 0;
        $heldPaths = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('Invalid SEO-03B decision record.');
            }

            $id = (string) ($entry['legacy_id'] ?? '');
            $frozen = $frozenReviewedRecords[$id] ?? null;

            if ($id === '' || isset($seen[$id]) || ! is_array($frozen)) {
                throw new RuntimeException('Unknown or duplicate SEO-03B decision legacy ID: '.$id);
            }

            $seen[$id] = true;
            $decision = $entry['user_decision'] ?? null;
            $expectedRecommendation = $decision === 'APPROVE_301' ? 'RECOMMEND_APPROVE_301' : 'HOLD';

            if (! in_array($decision, ['APPROVE_301', 'HOLD'], true)
                || ($entry['documentary_recommendation'] ?? null) !== $expectedRecommendation
                || (string) ($entry['legacy_index'] ?? '') !== (string) ($frozen['legacy_index'] ?? '')
                || (string) ($entry['target_product_id'] ?? '') !== (string) ($frozen['target_product_id'] ?? '')
                || ($entry['target_product_url'] ?? null) !== 'https://ortezka.pl'.($frozen['target_path'] ?? '')
                || ($entry['legacy_source_path_count'] ?? null) !== count($frozen['source_paths'] ?? [])) {
                throw new RuntimeException('SEO-03B decision does not match frozen identity evidence: '.$id);
            }

            $sourceCount = count($frozen['source_paths']);

            if ($decision === 'APPROVE_301') {
                $approved[$id] = $frozen;
                $approvedPaths += $sourceCount;
            } else {
                $heldProducts++;
                $heldPaths += $sourceCount;
            }
        }

        if (count($seen) !== count($frozenReviewedRecords)
            || count($approved) !== self::APPROVED_PRODUCTS
            || $approvedPaths !== self::APPROVED_SOURCE_PATHS
            || $heldProducts !== self::HELD_PRODUCTS
            || $heldPaths !== self::HELD_SOURCE_PATHS) {
            throw new RuntimeException('SEO-03B decision membership/count mismatch.');
        }

        return $approved;
    }

    private function verifiedFile(string $relative, string $expectedSha256): string
    {
        $raw = @file_get_contents(base_path($relative));

        if (! is_string($raw) || ! hash_equals($expectedSha256, hash('sha256', $raw))) {
            throw new RuntimeException('Missing or modified SEO-03B decision evidence: '.$relative);
        }

        return $raw;
    }
}
