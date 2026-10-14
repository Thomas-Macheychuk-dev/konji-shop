<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use RuntimeException;

final class HerdegenEvidenceAudit
{
    public function audit(array $snapshot): array
    {
        if (($snapshot['source'] ?? null) !== 'herdegen'
            || ($snapshot['analysis_type'] ?? null) !== 'herdegen_offline_listing_snapshot'
            || !is_array($snapshot['products'] ?? null)
            || !array_is_list($snapshot['products'])
            || ($snapshot['summary']['products'] ?? null) !== count($snapshot['products'])
            || ($snapshot['summary']['commercially_approved'] ?? null) !== 0) {
            throw new RuntimeException('Invalid Herdegen listing snapshot.');
        }

        $seen = [];
        $records = [];
        $summary = [
            'products' => 0,
            'identifiers_unverified' => 0,
            'retailer_prices_observed' => 0,
            'description_hashes_present' => 0,
            'configuration_review_required' => 0,
            'other_review_required' => 0,
            'manufacturer_specifications_verified' => 0,
            'orderable_variants_verified' => 0,
            'commercially_approved' => 0,
            'database_writes' => 0,
            'variants_created' => 0,
        ];

        foreach ($snapshot['products'] as $item) {
            if (!is_array($item) || !is_string($item['source_url'] ?? null)
                || !preg_match('~^https://www\.orteo\.pl/[A-Za-z0-9_-]+$~D', $item['source_url'])
                || !is_string($item['name'] ?? null) || trim($item['name']) === ''
                || ($item['brand'] ?? null) !== 'Herdegen'
                || ($item['external_id'] ?? null) !== substr(hash('sha256', $item['source_url']), 0, 32)
                || isset($seen[$item['external_id']])
                || ($item['commercially_approved'] ?? null) !== false
                || ($item['variant_candidates'] ?? null) !== []
                || !is_array($item['review_flags'] ?? null)) {
                throw new RuntimeException('Invalid, duplicate, or commercially modified Herdegen product.');
            }
            $seen[$item['external_id']] = true;
            $flags = $item['review_flags'];
            if (!array_is_list($flags) || count(array_filter($flags, 'is_string')) !== count($flags)) {
                throw new RuntimeException('Malformed review flags.');
            }
            $identifiers = $item['retailer_identifiers_unverified'] ?? [];
            $offer = $item['retailer_offer_snapshot'] ?? [];
            $descriptionHash = $item['description_evidence_sha256'] ?? null;
            if (!is_array($identifiers) || !is_array($offer)
                || ($descriptionHash !== null && (!is_string($descriptionHash) || !preg_match('/^[a-f0-9]{64}$/D', $descriptionHash)))) {
                throw new RuntimeException('Malformed source evidence.');
            }
            $hasIdentifier = false;
            foreach (['sku', 'mpn', 'gtin'] as $key) {
                if (is_string($identifiers[$key] ?? null) && trim($identifiers[$key]) !== '') {
                    $hasIdentifier = true;
                    break;
                }
            }
            $hasPrice = is_string($offer['price_gross_amount'] ?? null) && preg_match('/^\d+\.\d{2}$/D', $offer['price_gross_amount']) === 1;
            $needsConfig = in_array('DESCRIPTION_SUGGESTS_CONFIGURATIONS', $flags, true);
            $needsReview = $flags !== [];
            $summary['products']++;
            $summary['identifiers_unverified'] += (int) $hasIdentifier;
            $summary['retailer_prices_observed'] += (int) $hasPrice;
            $summary['description_hashes_present'] += (int) ($descriptionHash !== null);
            $summary['configuration_review_required'] += (int) $needsConfig;
            $summary['other_review_required'] += (int) ($needsReview && !$needsConfig);
            $records[] = [
                'external_id' => $item['external_id'],
                'source_url' => $item['source_url'],
                'name' => $item['name'],
                'description_evidence_sha256' => $descriptionHash,
                'review_flags' => $flags,
                'configuration_evidence_status' => $needsConfig ? 'REQUIRES_REVIEW' : 'NOT_VERIFIED',
                'manufacturer_specifications_verified' => false,
                'orderable_variants_verified' => false,
                'commercially_approved' => false,
            ];
        }

        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02a_offline_evidence_inventory',
            'summary' => $summary,
            'records' => $records,
        ];
    }
}
