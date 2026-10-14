<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenEvidenceAudit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HerdegenEvidenceAuditTest extends TestCase
{
    private function fixture(): array
    {
        $url = 'https://www.orteo.pl/test-product';
        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen_offline_listing_snapshot',
            'summary' => ['products' => 1, 'commercially_approved' => 0],
            'products' => [[
                'source_url' => $url,
                'external_id' => substr(hash('sha256', $url), 0, 32),
                'name' => 'Test product',
                'brand' => 'Herdegen',
                'retailer_identifiers_unverified' => ['sku' => '123'],
                'retailer_offer_snapshot' => ['price_gross_amount' => '12.99'],
                'description_evidence_sha256' => hash('sha256', 'description'),
                'review_flags' => ['ORDERABLE_VARIANTS_UNVERIFIED', 'DESCRIPTION_SUGGESTS_CONFIGURATIONS'],
                'variant_candidates' => [],
                'commercially_approved' => false,
            ]],
        ];
    }

    public function test_it_counts_evidence_without_promoting_supplier_data(): void
    {
        $report = (new HerdegenEvidenceAudit())->audit($this->fixture());
        self::assertSame(1, $report['summary']['products']);
        self::assertSame(1, $report['summary']['configuration_review_required']);
        self::assertSame(0, $report['summary']['commercially_approved']);
        self::assertSame(0, $report['summary']['variants_created']);
        self::assertSame('REQUIRES_REVIEW', $report['records'][0]['configuration_evidence_status']);
    }

    public function test_it_rejects_commercial_approval(): void
    {
        $fixture = $this->fixture();
        $fixture['products'][0]['commercially_approved'] = true;
        $this->expectException(RuntimeException::class);
        (new HerdegenEvidenceAudit())->audit($fixture);
    }

    public function test_it_rejects_duplicate_external_ids(): void
    {
        $fixture = $this->fixture();
        $fixture['products'][] = $fixture['products'][0];
        $fixture['summary']['products'] = 2;
        $this->expectException(RuntimeException::class);
        (new HerdegenEvidenceAudit())->audit($fixture);
    }
}
