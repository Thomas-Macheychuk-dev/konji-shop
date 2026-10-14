<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Evidence comparison only; never establishes supplier-orderability or authorises import. */
final class QmedConfigurationReconciliationService
{
    public function reconcile(array $evidence, array $validation, string $csv): array
    {
        $reviews = $evidence['review']['products'] ?? null;
        $semantics = $evidence['semantic']['products'] ?? null;
        $validated = $validation['records'] ?? null;
        if (!is_array($reviews) || count($reviews) !== 156 || !is_array($semantics)
            || count($semantics) !== 39 || !is_array($validated)
            || !array_is_list($validated) || ($validation['summary']['rows'] ?? null) !== count($validated)) {
            throw new RuntimeException('Unexpected Qmed evidence or supplier validation baseline.');
        }
        $index = static function (array $items): array {
            $map = [];
            foreach ($items as $item) {
                $url = $item['source_url'] ?? null;
                if (!is_array($item) || !is_string($url) || $url === '' || isset($map[$url])) {
                    throw new RuntimeException('Invalid or duplicated manufacturer evidence URL.');
                }
                $map[$url] = $item;
            }
            return $map;
        };
        $sizes = $index($reviews);
        $sideEvidence = $index($semantics);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('Unable to open CSV buffer.');
        try {
            if (fwrite($stream, $csv) !== strlen($csv)) throw new RuntimeException('CSV buffer write incomplete.');
            rewind($stream);
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($headers)) throw new RuntimeException('Missing CSV header.');
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            if ($headers !== QmedSupplierResponseValidator::HEADERS) {
                throw new RuntimeException('Supplier CSV header mismatch.');
            }
            $rows = [];
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) continue;
                $rows[] = $cells;
            }
        } finally {
            fclose($stream);
        }
        if (count($rows) !== count($validated)) {
            throw new RuntimeException('Supplier CSV row count differs from QMED-09D report.');
        }
        $results = [];
        $counts = ['rows' => count($rows), 'supported_by_manufacturer_evidence' => 0,
            'requires_review' => 0, 'conflicting_evidence' => 0, 'not_evaluated' => 0,
            'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0];
        foreach ($rows as $i => $cells) {
            $record = $validated[$i];
            if (!is_array($record) || !is_string($record['status'] ?? null)) {
                throw new RuntimeException('Malformed QMED-09D record.');
            }
            $result = ['csv_record' => $record['csv_record'] ?? null,
                'source_url' => $record['source_url'] ?? null,
                'supplier_sku' => $record['supplier_sku'] ?? null,
                'status' => 'NOT_EVALUATED', 'reasons' => [], 'commercially_approved' => false];
            if (count($cells) !== count($headers)) {
                if (($record['status'] ?? null) !== 'REJECTED') throw new RuntimeException('Validation/CSV mismatch.');
                $result['reasons'][] = 'INVALID_SUPPLIER_CSV_ROW';
            } else {
                $row = array_combine($headers, array_map('trim', $cells));
                if ($row['source_url'] !== ($record['source_url'] ?? null)
                    || $row['supplier_sku'] !== ($record['supplier_sku'] ?? null)) {
                    throw new RuntimeException('Validation/CSV record identity mismatch.');
                }
                $url = $row['source_url'];
                if (($record['status'] ?? null) !== 'EVIDENCE_COMPLETE_UNAPPROVED') {
                    $result['reasons'][] = 'SUPPLIER_VALIDATION_NOT_COMPLETE';
                } elseif (!isset($sizes[$url])) {
                    $result['reasons'][] = 'MANUFACTURER_EVIDENCE_MISSING';
                } else {
                    $size = $sizes[$url];
                    $semantic = $sideEvidence[$url] ?? null;
                    $sizeCode = mb_strtoupper(trim($row['size_code']));
                    $side = mb_strtoupper(trim($row['side']));
                    $labels = array_map(static fn($s) => mb_strtoupper(trim((string) $s)), $size['candidate_sizes'] ?? []);
                    $flags = $size['review_required_flags'] ?? [];
                    $cats = $semantic['evidence_categories'] ?? [];
                    $semanticFlags = $semantic['review_flags'] ?? [];
                    if (!is_array($labels) || !is_array($flags) || !is_array($cats) || !is_array($semanticFlags)) {
                        throw new RuntimeException('Malformed manufacturer evidence.');
                    }
                    $sizeSupported = false;
                    if ($flags !== []) {
                        $result['reasons'][] = 'STRUCTURED_SIZE_EVIDENCE_REQUIRES_REVIEW';
                    } elseif (in_array($sizeCode, $labels, true)) {
                        $sizeSupported = true;
                    } elseif ($labels === [] && $semantic !== null
                        && in_array('EXPLICIT_UNIVERSAL_SIZE', $cats, true)
                        && $sizeCode === 'UNIVERSAL' && $semanticFlags === []) {
                        $sizeSupported = true;
                    } else {
                        $result['reasons'][] = 'SIZE_NOT_SUPPORTED_BY_AVAILABLE_EVIDENCE';
                    }
                    $sideSupported = false;
                    $sideStatus = $semantic['side_evidence_status'] ?? null;
                    if ($semanticFlags !== []) {
                        $result['reasons'][] = 'SEMANTIC_EVIDENCE_REQUIRES_REVIEW';
                    } elseif ($sideStatus === 'EXPLICIT_SIDE_VERSIONS') {
                        if (in_array($side, ['LEFT', 'RIGHT'], true)) $sideSupported = true;
                        elseif ($side === 'BILATERAL') $result['reasons'][] = 'SIDE_CONFLICTS_WITH_EXPLICIT_VERSIONS';
                        else $result['reasons'][] = 'SIDE_CONFIGURATION_UNRESOLVED';
                    } elseif ($sideStatus === 'BILATERAL_COMPATIBILITY') {
                        if ($side === 'BILATERAL') $sideSupported = true;
                        elseif (in_array($side, ['LEFT', 'RIGHT'], true)) $result['reasons'][] = 'SIDE_CONFLICTS_WITH_BILATERAL_COMPATIBILITY';
                        else $result['reasons'][] = 'SIDE_CONFIGURATION_UNRESOLVED';
                    } else {
                        $result['reasons'][] = 'SIDE_CONFIGURATION_UNRESOLVED';
                    }
                    if (in_array('SIDE_CONFLICTS_WITH_EXPLICIT_VERSIONS', $result['reasons'], true)
                        || in_array('SIDE_CONFLICTS_WITH_BILATERAL_COMPATIBILITY', $result['reasons'], true)) {
                        $result['status'] = 'CONFLICTING_EVIDENCE';
                    } elseif ($sizeSupported && $sideSupported && $result['reasons'] === []) {
                        $result['status'] = 'SUPPORTED_BY_MANUFACTURER_EVIDENCE';
                    } else {
                        $result['status'] = 'REQUIRES_REVIEW';
                    }
                }
            }
            $key = match ($result['status']) {
                'SUPPORTED_BY_MANUFACTURER_EVIDENCE' => 'supported_by_manufacturer_evidence',
                'REQUIRES_REVIEW' => 'requires_review',
                'CONFLICTING_EVIDENCE' => 'conflicting_evidence',
                default => 'not_evaluated',
            };
            $counts[$key]++;
            $results[] = $result;
        }
        return ['source' => 'qmed', 'analysis_type' => 'qmed09f_configuration_evidence_reconciliation',
            'summary' => $counts, 'records' => $results];
    }
}
