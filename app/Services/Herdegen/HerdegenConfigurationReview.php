<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use RuntimeException;

final class HerdegenConfigurationReview
{
    public function review(array $snapshot, array $inventory): array
    {
        if (($inventory['analysis_type'] ?? null) !== 'herdegen02a_offline_evidence_inventory'
            || ($inventory['source'] ?? null) !== 'herdegen'
            || !is_array($inventory['records'] ?? null)
            || !array_is_list($inventory['records'])) {
            throw new RuntimeException('Invalid HERDEGEN-02A inventory.');
        }
        // Recompute rather than trust potentially manipulated aggregate counts or records.
        $expected = (new HerdegenEvidenceAudit())->audit($snapshot);
        if ($expected['summary'] !== ($inventory['summary'] ?? null)
            || $expected['records'] !== $inventory['records']) {
            throw new RuntimeException('HERDEGEN-02A inventory does not match the source snapshot.');
        }
        $summary = [
            'products' => 0,
            'configuration_review_required' => 0,
            'insufficient_configuration_evidence' => 0,
            'no_configuration_claim' => 0,
            'size_values_verified' => 0,
            'colour_values_verified' => 0,
            'side_values_verified' => 0,
            'model_values_verified' => 0,
            'orderable_variants_verified' => 0,
            'commercially_approved' => 0,
            'database_writes' => 0,
            'variants_created' => 0,
        ];
        $records = [];
        foreach ($expected['records'] as $record) {
            $flagged = $record['configuration_evidence_status'] === 'REQUIRES_REVIEW';
            $summary['products']++;
            $summary['configuration_review_required'] += (int) $flagged;
            $summary[$flagged ? 'insufficient_configuration_evidence' : 'no_configuration_claim']++;
            $records[] = [
                'external_id' => $record['external_id'],
                'source_url' => $record['source_url'],
                'description_evidence_sha256' => $record['description_evidence_sha256'],
                'source_review_flags' => $record['review_flags'],
                'status' => $flagged ? 'INSUFFICIENT_CONFIGURATION_EVIDENCE' : 'NO_CONFIGURATION_CLAIM',
                'reason' => $flagged
                    ? 'DESCRIPTION_FLAG_ONLY_NO_VERIFIABLE_OPTION_VALUES'
                    : 'NO_CONFIGURATION_FLAG_IN_LISTING_SNAPSHOT',
                'size_values' => [],
                'colour_values' => [],
                'side_values' => [],
                'model_values' => [],
                'orderable_variants_verified' => false,
                'commercially_approved' => false,
            ];
        }
        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02b_configuration_evidence_review',
            'evidence_limitations' => [
                'The listing snapshot contains description hashes, not full manufacturer-authenticated configuration descriptions.',
                'A source review flag does not identify sizes, colours, sides, models, or orderable SKUs.',
            ],
            'summary' => $summary,
            'records' => $records,
        ];
    }
}
