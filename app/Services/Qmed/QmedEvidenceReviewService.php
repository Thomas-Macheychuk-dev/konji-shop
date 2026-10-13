<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Read-only evidence triage. Commercial approval is never inferred. */
final class QmedEvidenceReviewService
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function review(array $data, string $sizeRaw): array
    {
        if (!isset($data['products']) || !is_array($data['products']) || !array_is_list($data['products'])) {
            throw new RuntimeException('Invalid size analysis schema.');
        }
    $informational = [
        'SIZE_LABEL_NORMALISED',
        'NON_LEADING_SIZE_ROW',
        'TWO_DIMENSIONAL_SIZE_GRID',
        'GENDERED_MEASUREMENT_COLUMNS',
        'CHILD_SIZE_QUALIFIER',
    ];
    $summary = [
        'products' => 0, 'with_candidates' => 0, 'without_candidates' => 0,
        'evidence_review_required' => 0, 'informational_only' => 0,
        'no_reported_flags' => 0, 'candidate_size_entries' => 0,
        'unknown_flags' => [], 'flag_frequencies' => [],
        'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0,
    ];
    $rows = [];
    foreach ( $data['products'] as $index => $product) {
        if (!is_array($product) || !isset($product['name'], $product['candidate_sizes'], $product['size_evidence'], $product['review_flags']) ||
            !is_string($product['name']) || !is_array($product['candidate_sizes']) || !is_array($product['size_evidence']) || !is_array($product['review_flags'])) {
            throw new RuntimeException("Malformed product row at index {$index}");
        }
        $flags = array_values(array_unique(array_map('strval', $product['review_flags'])));
        $blocking = [];
        $info = [];
        foreach ($flags as $flag) {
            $summary['flag_frequencies'][$flag] = ($summary['flag_frequencies'][$flag] ?? 0) + 1;
            if (in_array($flag, $informational, true)) {
                $info[] = $flag;
            } else {
                $blocking[] = $flag;
                if (!in_array($flag, ['NO_STRUCTURED_SIZE_CANDIDATES', 'DUPLICATE_SIZE_LABEL_WITH_DISTINCT_EVIDENCE', 'DECIMAL_SIZE_LABEL_REVIEW', 'MIXED_SIZE_MEASUREMENT_TABLE_REVIEW', 'UNRECOGNISED_TABLE_LAYOUT', 'UNRECOGNISED_VERTICAL_ROW'], true)) {
                    $summary['unknown_flags'][$flag] = ($summary['unknown_flags'][$flag] ?? 0) + 1;
                }
            }
        }
        $hasCandidates = count($product['size_evidence']) > 0;
        if (!$hasCandidates && !in_array('NO_STRUCTURED_SIZE_CANDIDATES', $blocking, true)) {
            $blocking[] = 'NO_STRUCTURED_SIZE_CANDIDATES';
        }
        $status = $blocking !== [] ? 'REVIEW_REQUIRED' : ($info !== [] ? 'INFO_ONLY' : 'NO_REPORTED_FLAGS');
        ++$summary['products'];
        $summary[$hasCandidates ? 'with_candidates' : 'without_candidates']++;
        $summary['candidate_size_entries'] += count($product['size_evidence']);
        $summary[$status === 'REVIEW_REQUIRED' ? 'evidence_review_required' : ($status === 'INFO_ONLY' ? 'informational_only' : 'no_reported_flags')]++;
        $rows[] = [
            'name' => $product['name'],
            'external_id' => $product['external_id'] ?? null,
            'source_url' => $product['source_url'] ?? null,
            'candidate_sizes' => $product['candidate_sizes'],
            'size_evidence' => $product['size_evidence'],
            'source_flags' => $flags,
            'informational_flags' => $info,
            'review_required_flags' => $blocking,
            'evidence_status' => $status,
            'commercial_status' => 'NOT_VERIFIED',
            'manufacturer_skus_verified' => false,
            'approved_for_variant_import' => false,
        ];
    }
    ksort($summary['flag_frequencies']);
    ksort($summary['unknown_flags']);
    $result = [
        'source' => 'qmed',
        'analysis_type' => 'qmed08a_commercial_evidence_review',
        'source_report_sha256' => hash('sha256', $sizeRaw),
        'triage_policy' => 'Only explicitly known extraction flags are informational; all unknown flags require review; no commercial approvals inferred.',
        'summary' => $summary,
        'products' => $rows,
    ];
        return $result;
    }
}
