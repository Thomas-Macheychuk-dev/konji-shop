<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use RuntimeException;

final class HerdegenDocumentationManifest
{
    public const HEADERS = [
        'external_id', 'product_name', 'source_url', 'required_evidence',
        'manufacturer_document_reference', 'document_sha256',
        'manufacturer_product_code', 'size', 'colour', 'side', 'model', 'supplier_notes',
    ];

    public function build(array $review, array $intake): array
    {
        if (($review['source'] ?? null) !== 'herdegen'
            || ($review['analysis_type'] ?? null) !== 'herdegen02b_configuration_evidence_review'
            || !is_array($review['records'] ?? null) || !array_is_list($review['records'])
            || ($review['summary']['products'] ?? null) !== count($review['records'])
            || ($review['summary']['commercially_approved'] ?? null) !== 0
            || ($review['summary']['variants_created'] ?? null) !== 0
            || ($intake['source'] ?? null) !== 'herdegen'
            || ($intake['analysis_type'] ?? null) !== 'herdegen02c_manufacturer_evidence_intake'
            || !is_array($intake['records'] ?? null) || !array_is_list($intake['records'])
            || ($intake['summary']['products'] ?? null) !== count($review['records'])
            || ($intake['summary']['commercially_approved'] ?? null) !== 0
            || ($intake['summary']['variants_created'] ?? null) !== 0) {
            throw new RuntimeException('Invalid HERDEGEN-02B/02C evidence reports.');
        }

        $seen = [];
        $eligible = [];
        foreach ($review['records'] as $record) {
            $id = $record['external_id'] ?? null;
            if (!is_array($record) || !is_string($id)
                || !preg_match('/^[a-f0-9]{32}$/D', $id) || isset($seen[$id])
                || !is_string($record['source_url'] ?? null)
                || !preg_match('~^https://www\.orteo\.pl/[A-Za-z0-9_-]+$~D', $record['source_url'])
                || !in_array($record['status'] ?? null, ['INSUFFICIENT_CONFIGURATION_EVIDENCE', 'NO_CONFIGURATION_CLAIM'], true)
                || ($record['commercially_approved'] ?? null) !== false
                || ($record['orderable_variants_verified'] ?? null) !== false) {
                throw new RuntimeException('Malformed HERDEGEN-02B product record.');
            }
            $seen[$id] = true;
            if ($record['status'] === 'INSUFFICIENT_CONFIGURATION_EVIDENCE') {
                $eligible[$id] = $record;
            }
        }

        $submitted = [];
        foreach ($intake['records'] as $record) {
            $id = $record['external_id'] ?? null;
            if (!is_array($record) || !is_string($id) || !isset($seen[$id])
                || isset($submitted[$id])
                || ($record['manufacturer_authenticity_verified'] ?? null) !== false
                || ($record['orderable_variants_verified'] ?? null) !== false) {
                throw new RuntimeException('Invalid or unmatched HERDEGEN-02C intake record.');
            }
            $submitted[$id] = true;
        }
        if (($intake['summary']['submitted_entries'] ?? null) !== count($submitted)
            || ($intake['summary']['unmatched_entries'] ?? null) !== 0
            || ($intake['summary']['products_without_submission'] ?? null) !== count($seen) - count($submitted)) {
            throw new RuntimeException('HERDEGEN-02C intake summary mismatch.');
        }

        $rows = [];
        $records = [];
        foreach ($eligible as $id => $record) {
            $rows[] = [
                $id, '', $record['source_url'],
                'Official configuration catalogue or datasheet; size/colour/side/model and ordering reference',
                '', '', '', '', '', '', '', '',
            ];
            $records[] = [
                'external_id' => $id,
                'source_url' => $record['source_url'],
                'status' => 'MANUFACTURER_DOCUMENTATION_REQUIRED',
                'intake_submission_exists' => isset($submitted[$id]),
                'commercially_approved' => false,
            ];
        }

        if (count($eligible) !== ($review['summary']['configuration_review_required'] ?? null)) {
            throw new RuntimeException('HERDEGEN-02B review count mismatch.');
        }

        return [
            'manifest' => [
                'source' => 'herdegen',
                'analysis_type' => 'herdegen02d_documentation_requirements',
                'summary' => [
                    'products' => count($seen),
                    'documentation_required' => count($eligible),
                    'template_rows' => count($rows),
                    'manufacturer_authenticity_verified' => 0,
                    'orderable_variants_verified' => 0,
                    'commercially_approved' => 0,
                    'database_writes' => 0,
                    'variants_created' => 0,
                ],
                'records' => $records,
            ],
            'rows' => $rows,
        ];
    }

    public function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to create CSV buffer.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF"); // Excel-compatible UTF-8.
            fputcsv($stream, self::HEADERS, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv)) {
                throw new RuntimeException('Unable to read CSV buffer.');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }
}
