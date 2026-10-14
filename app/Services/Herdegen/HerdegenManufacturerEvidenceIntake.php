<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use RuntimeException;

/** Reviews submitted documentary claims; never authenticates a manufacturer or approves sale. */
final class HerdegenManufacturerEvidenceIntake
{
    public function assess(array $review, array $submission): array
    {
        if (($review['source'] ?? null) !== 'herdegen'
            || ($review['analysis_type'] ?? null) !== 'herdegen02b_configuration_evidence_review'
            || !is_array($review['records'] ?? null) || !array_is_list($review['records'])
            || ($review['summary']['products'] ?? null) !== count($review['records'])
            || ($review['summary']['commercially_approved'] ?? null) !== 0
            || ($review['summary']['variants_created'] ?? null) !== 0) {
            throw new RuntimeException('Invalid HERDEGEN-02B report.');
        }
        if (($submission['source'] ?? null) !== 'herdegen'
            || ($submission['analysis_type'] ?? null) !== 'herdegen02c_manufacturer_evidence_submission'
            || !is_array($submission['entries'] ?? null) || !array_is_list($submission['entries'])) {
            throw new RuntimeException('Invalid manufacturer evidence submission.');
        }
        $index = [];
        foreach ($review['records'] as $record) {
            if (!is_array($record) || !is_string($record['external_id'] ?? null)
                || !preg_match('/^[a-f0-9]{32}$/D', $record['external_id'])
                || isset($index[$record['external_id']])
                || !in_array($record['status'] ?? null, ['INSUFFICIENT_CONFIGURATION_EVIDENCE', 'NO_CONFIGURATION_CLAIM'], true)
                || ($record['commercially_approved'] ?? null) !== false
                || ($record['orderable_variants_verified'] ?? null) !== false) {
                throw new RuntimeException('Malformed HERDEGEN-02B records.');
            }
            $index[$record['external_id']] = $record;
        }
        $seen = [];
        $results = [];
        $counts = [
            'products' => count($index), 'submitted_entries' => 0,
            'documented_claims_for_review' => 0, 'missing_document_reference' => 0,
            'unmatched_entries' => 0, 'products_without_submission' => 0,
            'manufacturer_authenticity_verified' => 0, 'orderable_variants_verified' => 0,
            'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0,
        ];
        foreach ($submission['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['external_id'] ?? null)
                || !preg_match('/^[a-f0-9]{32}$/D', $entry['external_id'])
                || isset($seen[$entry['external_id']])) {
                throw new RuntimeException('Invalid or duplicate evidence submission ID.');
            }
            $seen[$entry['external_id']] = true;
            $claims = $entry['claims'] ?? null;
            if (!is_array($claims) || !array_is_list($claims) || count($claims) > 100) {
                throw new RuntimeException('Invalid evidence claims.');
            }
            foreach ($claims as $claim) {
                if (!is_array($claim)
                    || !in_array($claim['type'] ?? null, ['size', 'colour', 'side', 'model'], true)
                    || !is_string($claim['value'] ?? null)
                    || trim($claim['value']) === '' || mb_strlen($claim['value']) > 120) {
                    throw new RuntimeException('Invalid evidence claim.');
                }
            }
            $document = $entry['document'] ?? null;
            if ($document !== null && (!is_array($document)
                || !is_string($document['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $document['sha256'])
                || !is_string($document['reference'] ?? null)
                || trim($document['reference']) === ''
                || mb_strlen($document['reference']) > 400)) {
                throw new RuntimeException('Invalid documentary reference.');
            }
            // A submitted digest/reference is not proof the file was examined or is authentic.
            $matched = isset($index[$entry['external_id']]);
            $status = !$matched ? 'UNMATCHED_PRODUCT'
                : ($claims === [] || $document === null ? 'MISSING_DOCUMENTED_CLAIMS' : 'DOCUMENTED_CLAIMS_REQUIRE_REVIEW');
            $counts['submitted_entries']++;
            $counts['unmatched_entries'] += (int) !$matched;
            $counts['documented_claims_for_review'] += (int) ($status === 'DOCUMENTED_CLAIMS_REQUIRE_REVIEW');
            $counts['missing_document_reference'] += (int) ($matched && $document === null);
            $results[] = [
                'external_id' => $entry['external_id'],
                'status' => $status,
                'claims' => $claims,
                'document_reference' => is_array($document) ? $document['reference'] : null,
                'document_sha256' => is_array($document) ? $document['sha256'] : null,
                'manufacturer_authenticity_verified' => false,
                'orderable_variants_verified' => false,
            ];
        }
        $counts['products_without_submission'] = count(array_diff(array_keys($index), array_keys($seen)));
        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02c_manufacturer_evidence_intake',
            'summary' => $counts,
            'records' => $results,
            'disclaimer' => 'Submitted documents and claims remain unverified until independently reviewed; no commercial approval.',
        ];
    }
}
