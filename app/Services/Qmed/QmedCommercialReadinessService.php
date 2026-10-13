<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Evidence-only audit. Never creates SKUs, variants, prices or approval records. */
final class QmedCommercialReadinessService
{
    /** @param array<string,mixed> $source @param array<string,mixed> $evidence */
    public function audit(array $source, array $evidence): array
    {
        $products = $source['products'] ?? null;
        $review = $evidence['review']['products'] ?? null;
        $semantic = $evidence['semantic']['products'] ?? null;
        if (!is_array($products) || !array_is_list($products) || !is_array($review) || !is_array($semantic)) {
            throw new RuntimeException('Invalid Qmed inventory or QMED-08G report.');
        }
        if (count($products) !== 156 || count($review) !== 156 || count($semantic) !== 39
            || ($evidence['summary']['products'] ?? null) !== 156
            || ($evidence['summary']['semantic_review_required'] ?? null) !== 4) {
            throw new RuntimeException('Unexpected Qmed baseline; audit requires verified QMED-08G inputs.');
        }
        $index = static function (array $items): array {
            $indexed = [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['source_url'] ?? null) || $item['source_url'] === ''
                    || isset($indexed[$item['source_url']])) {
                    throw new RuntimeException('Invalid or duplicate evidence source URL.');
                }
                $indexed[$item['source_url']] = $item;
            }
            return $indexed;
        };
        $byReview = $index($review);
        $bySemantic = $index($semantic);
        $seen = [];
        $rows = [];
        $reasonCounts = [];
        $skuCount = 0;
        $priceCount = 0;
        $semanticCount = 0;
        $variantsUnresolvedTrue = 0;
        foreach ($products as $p) {
            if (!is_array($p) || !is_string($p['source_url'] ?? null) || $p['source_url'] === ''
                || isset($seen[$p['source_url']]) || !isset($byReview[$p['source_url']])) {
                throw new RuntimeException('Invalid, duplicate or unmatched source product.');
            }
            $url = $p['source_url'];
            $seen[$url] = true;
            $skuPresent = is_string($p['sku'] ?? null) && trim($p['sku']) !== '';
            $priceValue = $p['price_gross_amount'] ?? null;
            $pricePresent = (is_int($priceValue) || is_float($priceValue) || is_string($priceValue))
                && is_numeric($priceValue) && (float) $priceValue > 0;
            $skuCount += (int) $skuPresent;
            $priceCount += (int) $pricePresent;
            $variantsUnresolvedTrue += (int) (($p['variants_unresolved'] ?? false) === true);
            $semanticRow = $bySemantic[$url] ?? null;
            $semanticFlags = $semanticRow['review_flags'] ?? [];
            if (!is_array($semanticFlags)) throw new RuntimeException('Malformed semantic review flags.');
            $needsSemanticReview = $semanticFlags !== [];
            $semanticCount += (int) $needsSemanticReview;
            $reasons = [];
            if (!$skuPresent) $reasons[] = 'MANUFACTURER_SKU_MISSING';
            if (!$pricePresent) $reasons[] = 'PRICE_MISSING';
            // The source does not include independently validated VAT, stock or orderable-variant approvals.
            $reasons[] = 'VAT_NOT_VERIFIED';
            $reasons[] = 'AVAILABILITY_NOT_VERIFIED';
            $reasons[] = 'ORDERABLE_VARIANTS_NOT_VERIFIED';
            if ($needsSemanticReview) $reasons[] = 'SEMANTIC_EVIDENCE_REVIEW_REQUIRED';
            $sizeFlags = $byReview[$url]['review_required_flags'] ?? [];
            if (!is_array($sizeFlags)) throw new RuntimeException('Malformed size evidence flags.');
            if ($sizeFlags !== []) $reasons[] = 'STRUCTURED_SIZE_EVIDENCE_REVIEW_REQUIRED';
            foreach ($reasons as $reason) $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $rows[] = [
                'name' => $p['name'] ?? null,
                'source_url' => $url,
                'external_id' => $p['external_id'] ?? null,
                'source_sku_present_unverified' => $skuPresent,
                'source_positive_gross_price_present_unverified' => $pricePresent,
                'source_variants_unresolved' => $p['variants_unresolved'] ?? null,
                'semantic_review_flags' => $semanticFlags,
                'structured_size_review_flags' => $sizeFlags,
                'reasons' => $reasons,
                'commercial_status' => 'NOT_COMMERCIALLY_READY',
                'commercially_approved' => false,
                'approved_for_variant_import' => false,
            ];
        }
        if (count($seen) !== 156 || $semanticCount !== 4 || count($bySemantic) !== 39) {
            throw new RuntimeException('Qmed source/evidence reconciliation failed.');
        }
        foreach ($bySemantic as $url => $_) {
            if (!isset($seen[$url])) throw new RuntimeException('Orphan semantic evidence row.');
        }
        ksort($reasonCounts);
        return [
            'source' => 'qmed',
            'analysis_type' => 'qmed09b_commercial_readiness_evidence_only',
            'summary' => [
                'products' => 156,
                'source_skus_present_unverified' => $skuCount,
                'source_positive_gross_prices_present_unverified' => $priceCount,
                'source_variants_unresolved_true' => $variantsUnresolvedTrue,
                'semantic_review_required' => $semanticCount,
                'commercially_ready' => 0,
                'commercially_approved' => 0,
                'orderable_variants_verified' => 0,
                'database_writes' => 0,
                'variants_created' => 0,
                'reason_counts' => $reasonCounts,
            ],
            'products' => $rows,
        ];
    }
}
