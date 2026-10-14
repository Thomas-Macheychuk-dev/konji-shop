<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use RuntimeException;

final class HerdegenSupplierResponseValidator
{
    public function validate(array $manifest, string $csv): array
    {
        if (($manifest['source'] ?? null) !== 'herdegen'
            || ($manifest['analysis_type'] ?? null) !== 'herdegen02d_documentation_requirements'
            || !is_array($manifest['records'] ?? null) || !array_is_list($manifest['records'])
            || ($manifest['summary']['documentation_required'] ?? null) !== count($manifest['records'])
            || ($manifest['summary']['template_rows'] ?? null) !== count($manifest['records'])
            || ($manifest['summary']['commercially_approved'] ?? null) !== 0
            || ($manifest['summary']['variants_created'] ?? null) !== 0) {
            throw new RuntimeException('Invalid HERDEGEN-02D manifest.');
        }
        $expected = [];
        foreach ($manifest['records'] as $record) {
            $id = $record['external_id'] ?? null;
            if (!is_array($record) || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)
                || isset($expected[$id]) || !is_string($record['source_url'] ?? null)
                || ($record['status'] ?? null) !== 'MANUFACTURER_DOCUMENTATION_REQUIRED'
                || ($record['commercially_approved'] ?? null) !== false) {
                throw new RuntimeException('Invalid or duplicated manifest record.');
            }
            $expected[$id] = $record['source_url'];
        }
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('CSV stream error.');
        }
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if ($headers !== HerdegenDocumentationManifest::HEADERS) {
                throw new RuntimeException('Invalid supplier CSV header.');
            }
            $seen = [];
            $results = [];
            $validClaims = 0;
            $issueRows = 0;
            $line = 1;
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                if ($line > 10002) {
                    throw new RuntimeException('CSV row limit exceeded.');
                }
                if (count($row) !== count($headers)) {
                    $results[] = ['row' => $line, 'external_id' => null, 'status' => 'REVIEW_REQUIRED', 'issues' => ['COLUMN_COUNT_MISMATCH']];
                    $issueRows++;
                    continue;
                }
                $entry = array_combine($headers, $row);
                $id = $entry['external_id'];
                $errors = [];
                if (!isset($expected[$id])) {
                    $errors[] = 'UNKNOWN_PRODUCT';
                } elseif ($entry['source_url'] !== $expected[$id]) {
                    $errors[] = 'SOURCE_URL_MISMATCH';
                }
                if (isset($seen[$id])) {
                    $errors[] = 'DUPLICATE_PRODUCT';
                }
                $seen[$id] = true;
                foreach ($entry as $column => $value) {
                    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 1000) {
                        $errors[] = 'INVALID_FIELD_'.$column;
                    }
                }
                $reference = trim($entry['manufacturer_document_reference']);
                $digest = trim($entry['document_sha256']);
                $claims = array_filter([
                    $entry['manufacturer_product_code'], $entry['size'], $entry['colour'],
                    $entry['side'], $entry['model'],
                ], static fn (string $s): bool => trim($s) !== '');
                $filled = $reference !== '' || $digest !== '' || $claims !== [] || trim($entry['supplier_notes']) !== '';
                if ($filled) {
                    if ($reference === '') {
                        $errors[] = 'MISSING_DOCUMENT_REFERENCE';
                    }
                    if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                        $errors[] = 'INVALID_OR_MISSING_DOCUMENT_SHA256';
                    }
                    if ($claims === []) {
                        $errors[] = 'MISSING_CONFIGURATION_OR_MODEL_CLAIM';
                    }
                }
                $status = $errors !== [] ? 'REVIEW_REQUIRED' : ($filled ? 'DOCUMENTED_CLAIMS_REQUIRE_REVIEW' : 'AWAITING_SUPPLIER_DATA');
                $validClaims += (int) ($status === 'DOCUMENTED_CLAIMS_REQUIRE_REVIEW');
                $issueRows += (int) ($errors !== []);
                // Supplier content is intentionally excluded from the output report.
                $results[] = ['row' => $line, 'external_id' => preg_match('/^[a-f0-9]{32}$/D', $id) ? $id : null,
                    'status' => $status, 'issues' => array_values(array_unique($errors))];
            }
        } finally {
            fclose($stream);
        }
        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02e_supplier_response_validation',
            'summary' => [
                'manifest_products' => count($expected), 'csv_rows' => count($results),
                'documented_claims_require_review' => $validClaims, 'rows_with_issues' => $issueRows,
                'manifest_products_missing_from_csv' => count(array_diff(array_keys($expected), array_keys($seen))),
                'manufacturer_authenticity_verified' => 0, 'orderable_variants_verified' => 0,
                'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0,
            ],
            'records' => $results,
            'disclaimer' => 'Structural checks do not authenticate manufacturer documents or commercial orderability.',
        ];
    }
}
