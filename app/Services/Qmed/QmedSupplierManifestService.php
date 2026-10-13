<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Supplier requirements only: no inference of commercial variants or approval. */
final class QmedSupplierManifestService
{
    public const HEADERS = [
        'manufacturer_source_id', 'product_name', 'source_url',
        'supplier_sku', 'orderable_configuration', 'size_code', 'side',
        'wholesale_net_price', 'retail_gross_price', 'currency', 'vat_rate',
        'availability', 'supplier_reference', 'supplier_notes',
    ];

    public function build(array $source, array $audit): array
    {
        $products = $source['products'] ?? null;
        $audited = $audit['products'] ?? null;
        if (!is_array($products) || !array_is_list($products) || count($products) !== 156
            || !is_array($audited) || !array_is_list($audited) || count($audited) !== 156
            || ($audit['summary']['products'] ?? null) !== 156
            || ($audit['summary']['commercially_ready'] ?? null) !== 0
            || ($audit['summary']['commercially_approved'] ?? null) !== 0) {
            throw new RuntimeException('Unexpected QMED-09B audit baseline.');
        }
        $auditByUrl = [];
        foreach ($audited as $item) {
            $url = $item['source_url'] ?? null;
            if (!is_array($item) || !is_string($url) || $url === '' || isset($auditByUrl[$url])) {
                throw new RuntimeException('Invalid or duplicate audit URL.');
            }
            $auditByUrl[$url] = $item;
        }
        $seen = [];
        $rows = [];
        $requirements = [];
        foreach ($products as $product) {
            $url = $product['source_url'] ?? null;
            if (!is_array($product) || !is_string($url) || $url === ''
                || isset($seen[$url]) || !isset($auditByUrl[$url])) {
                throw new RuntimeException('Invalid, duplicate or unmatched source URL.');
            }
            $seen[$url] = true;
            $review = $auditByUrl[$url];
            if (($review['external_id'] ?? null) !== ($product['external_id'] ?? null)
                || ($review['name'] ?? null) !== ($product['name'] ?? null)
                || ($review['commercial_status'] ?? null) !== 'NOT_COMMERCIALLY_READY'
                || ($review['commercially_approved'] ?? null) !== false
                || ($review['approved_for_variant_import'] ?? null) !== false
                || !is_array($review['reasons'] ?? null)
                || !is_array($review['semantic_review_flags'] ?? null)
                || !is_array($review['structured_size_review_flags'] ?? null)) {
                throw new RuntimeException('Commercial audit/source mismatch.');
            }
            $requirements[] = [
                'manufacturer_source_id' => $product['external_id'] ?? null,
                'product_name' => $product['name'] ?? null,
                'source_url' => $url,
                'readiness' => 'NOT_COMMERCIALLY_READY',
                'missing_evidence_reasons' => $review['reasons'],
                'semantic_review_flags' => $review['semantic_review_flags'],
                'structured_size_review_flags' => $review['structured_size_review_flags'],
                'supplier_response_fields' => array_slice(self::HEADERS, 3),
                'commercially_approved' => false,
                'approved_for_variant_import' => false,
            ];
            $rows[] = [
                (string) ($product['external_id'] ?? ''),
                (string) ($product['name'] ?? ''),
                $url,
                ...array_fill(0, count(self::HEADERS) - 3, ''),
            ];
        }
        if (count($seen) !== 156 || count($auditByUrl) !== 156) {
            throw new RuntimeException('Source/audit reconciliation failed.');
        }
        return [
            'manifest' => [
                'source' => 'qmed',
                'analysis_type' => 'qmed09c_supplier_data_requirements',
                'summary' => [
                    'products' => 156, 'template_rows' => 156,
                    'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0,
                ],
                'products' => $requirements,
            ],
            'rows' => $rows,
        ];
    }

    public function csv(array $rows): string
    {
        $file = fopen('php://temp', 'w+');
        if ($file === false) throw new RuntimeException('Could not create CSV buffer.');
        try {
            fputcsv($file, self::HEADERS, ',', '"', '');
            foreach ($rows as $row) {
                if (!is_array($row) || count($row) !== count(self::HEADERS)) {
                    throw new RuntimeException('Invalid CSV record.');
                }
                $safe = array_map(static function (mixed $field): string {
                    $value = (string) $field;
                    // Prevent spreadsheet formula execution from manufacturer text fields.
                    return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) ? "'".$value : $value;
                }, array_values($row));
                fputcsv($file, $safe, ',', '"', '');
            }
            rewind($file);
            $result = stream_get_contents($file);
            if ($result === false) throw new RuntimeException('Could not read CSV buffer.');
            return $result;
        } finally {
            fclose($file);
        }
    }
}
