<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use RuntimeException;

final class HerdegenEvidenceReadiness
{
    public function reconcile(array $review, array $intake, array $manifest, array $validation, string $csv): array
    {
        $rebuilt = (new HerdegenDocumentationManifest())->build($review, $intake)['manifest'];
        foreach ($rebuilt as $key => $value) {
            if (($manifest[$key] ?? null) !== $value) {
                throw new RuntimeException('HERDEGEN-02D manifest does not match upstream evidence.');
            }
        }
        $revalidated = (new HerdegenSupplierResponseValidator())->validate($manifest, $csv);
        foreach ($revalidated as $key => $value) {
            if (($validation[$key] ?? null) !== $value) {
                throw new RuntimeException('HERDEGEN-02E validation does not match submitted CSV.');
            }
        }
        $rows = [];
        foreach ($validation['records'] as $record) {
            $id = $record['external_id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                continue; // Invalid or malformed CSV rows remain counted in the validation issues.
            }
            $rows[$id][] = $record;
        }
        $records = [];
        $counts = [
            'products' => count($review['records']),
            'documentation_required' => count($manifest['records']),
            'no_configuration_claim' => 0,
            'awaiting_supplier_data' => 0,
            'documented_claims_require_review' => 0,
            'supplier_response_issues' => 0,
            'supplier_rows_with_issues' => $validation['summary']['rows_with_issues'],
            'manufacturer_authenticity_verified' => 0,
            'orderable_variants_verified' => 0,
            'commercially_approved' => 0,
            'database_writes' => 0,
            'variants_created' => 0,
        ];
        $required = [];
        foreach ($manifest['records'] as $item) {
            $required[$item['external_id']] = true;
        }
        foreach ($review['records'] as $item) {
            $id = $item['external_id'];
            if (!isset($required[$id])) {
                $status = 'NO_CONFIGURATION_CLAIM';
                $counts['no_configuration_claim']++;
            } else {
                $matched = $rows[$id] ?? [];
                if (count($matched) !== 1 || $matched[0]['status'] === 'REVIEW_REQUIRED') {
                    $status = 'SUPPLIER_RESPONSE_ISSUES';
                    $counts['supplier_response_issues']++;
                } elseif ($matched[0]['status'] === 'DOCUMENTED_CLAIMS_REQUIRE_REVIEW') {
                    $status = 'DOCUMENTED_CLAIMS_REQUIRE_REVIEW';
                    $counts['documented_claims_require_review']++;
                } else {
                    $status = 'AWAITING_SUPPLIER_DATA';
                    $counts['awaiting_supplier_data']++;
                }
            }
            $records[] = [
                'external_id' => $id,
                'source_url' => $item['source_url'],
                'status' => $status,
                'manufacturer_authenticity_verified' => false,
                'orderable_variants_verified' => false,
                'commercially_approved' => false,
            ];
        }
        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02f_supplier_evidence_readiness',
            'summary' => $counts,
            'records' => $records,
            'disclaimer' => 'Readiness only; documented claims are not authenticated and products remain non-orderable.',
        ];
    }
}
