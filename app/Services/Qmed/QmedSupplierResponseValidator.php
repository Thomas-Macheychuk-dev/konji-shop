<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Evidence-only validation: no catalogue mutation or commercial authorization. */
final class QmedSupplierResponseValidator
{
    public const HEADERS = [
        'manufacturer_source_id', 'product_name', 'source_url',
        'supplier_sku', 'orderable_configuration', 'size_code', 'side',
        'wholesale_net_price', 'retail_gross_price', 'currency',
        'vat_rate', 'availability', 'supplier_reference', 'supplier_notes',
    ];

    /** @param array<string,mixed> $manifest */
    public function validate(array $manifest, string $csv): array
    {
        $products = $manifest['products'] ?? null;
        if (($manifest['analysis_type'] ?? null) !== 'qmed09c_supplier_data_requirements'
            || !is_array($products) || !array_is_list($products) || count($products) !== 156
            || ($manifest['summary']['products'] ?? null) !== 156
            || ($manifest['summary']['commercially_approved'] ?? null) !== 0) {
            throw new RuntimeException('Unexpected QMED-09C manifest baseline.');
        }
        $known = [];
        foreach ($products as $product) {
            if (!is_array($product) || !is_string($product['source_url'] ?? null)
                || !is_string($product['manufacturer_source_id'] ?? null)
                || !is_string($product['product_name'] ?? null)
                || isset($known[$product['source_url']])) {
                throw new RuntimeException('Invalid or duplicate manifest product.');
            }
            $known[$product['source_url']] = $product;
        }
        if (!mb_check_encoding($csv, 'UTF-8')) throw new RuntimeException('CSV must be UTF-8.');
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('Unable to open CSV buffer.');
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header)) throw new RuntimeException('Missing CSV header.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            if ($header !== self::HEADERS) throw new RuntimeException('Unexpected supplier CSV header or order.');
            $results = [];
            $seenSku = [];
            $summary = ['rows' => 0, 'evidence_complete' => 0, 'review_required' => 0,
                'rejected' => 0, 'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0];
            $line = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                ++$line;
                if ($cells === [null]) continue;
                ++$summary['rows'];
                $errors = [];
                $review = [];
                if (count($cells) !== count(self::HEADERS)) {
                    $errors[] = 'COLUMN_COUNT_INVALID';
                    $results[] = ['csv_record' => $line, 'source_url' => null, 'supplier_sku' => null,
                        'status' => 'REJECTED', 'errors' => $errors, 'review_reasons' => []];
                    ++$summary['rejected'];
                    continue;
                }
                $row = array_combine(self::HEADERS, array_map('trim', $cells));
                $url = $row['source_url'];
                $ref = $known[$url] ?? null;
                if ($ref === null) $errors[] = 'SOURCE_URL_UNKNOWN';
                if ($ref !== null && $row['manufacturer_source_id'] !== $ref['manufacturer_source_id']) {
                    $errors[] = 'SOURCE_ID_MISMATCH';
                }
                if ($ref !== null && $row['product_name'] !== $ref['product_name']) {
                    $errors[] = 'PRODUCT_NAME_MISMATCH';
                }
                $sku = $row['supplier_sku'];
                if ($sku === '') {
                    $review[] = 'SUPPLIER_SKU_MISSING';
                } elseif (isset($seenSku[mb_strtolower($sku)])) {
                    $errors[] = 'DUPLICATE_SUPPLIER_SKU';
                } else {
                    $seenSku[mb_strtolower($sku)] = true;
                }
                if ($row['orderable_configuration'] === '') $review[] = 'CONFIGURATION_NOT_SUPPLIED';
                if ($row['size_code'] === '') $review[] = 'SIZE_NOT_CONFIRMED';
                $side = mb_strtoupper($row['side']);
                if ($side === '') $review[] = 'SIDE_NOT_CONFIRMED';
                elseif (!in_array($side, ['LEFT', 'RIGHT', 'BILATERAL', 'NOT_APPLICABLE'], true)) {
                    $errors[] = 'SIDE_VALUE_INVALID';
                }
                foreach (['wholesale_net_price', 'retail_gross_price'] as $field) {
                    $price = $row[$field];
                    if ($price === '') {
                        $review[] = strtoupper($field).'_MISSING';
                    } elseif (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $price)
                        || (float) $price <= 0) {
                        $errors[] = strtoupper($field).'_INVALID';
                    }
                }
                if ($row['currency'] === '') $review[] = 'CURRENCY_MISSING';
                elseif (!preg_match('/^[A-Z]{3}$/D', $row['currency'])) $errors[] = 'CURRENCY_INVALID';
                if ($row['vat_rate'] === '') $review[] = 'VAT_RATE_MISSING';
                elseif (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $row['vat_rate'])
                    || (float) $row['vat_rate'] > 100) $errors[] = 'VAT_RATE_INVALID';
                if ($row['availability'] === '') $review[] = 'AVAILABILITY_MISSING';
                if ($row['supplier_reference'] === '') $review[] = 'SUPPLIER_REFERENCE_MISSING';
                // Supplier claims are evidence, not manufacturer or tax-law verification.
                if ($errors === [] && $review === []) {
                    $status = 'EVIDENCE_COMPLETE_UNAPPROVED';
                    ++$summary['evidence_complete'];
                } elseif ($errors !== []) {
                    $status = 'REJECTED';
                    ++$summary['rejected'];
                } else {
                    $status = 'REVIEW_REQUIRED';
                    ++$summary['review_required'];
                }
                $results[] = ['csv_record' => $line, 'source_url' => $url,
                    'supplier_sku' => $sku, 'status' => $status,
                    'errors' => $errors, 'review_reasons' => $review];
            }
            return [
                'source' => 'qmed', 'analysis_type' => 'qmed09d_supplier_response_validation',
                'summary' => $summary, 'records' => $results,
            ];
        } finally {
            fclose($stream);
        }
    }
}
