<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenConfigurationReview;
use App\Services\Herdegen\HerdegenEvidenceAudit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HerdegenConfigurationReviewTest extends TestCase
{
    private function fixture(): array
    {
        $products = [];
        foreach ([true, false] as $i => $flagged) {
            $url = 'https://www.orteo.pl/example-'.$i;
            $products[] = [
                'source_url' => $url,
                'external_id' => substr(hash('sha256', $url), 0, 32),
                'name' => 'Example '.$i,
                'brand' => 'Herdegen',
                'description_evidence_sha256' => hash('sha256', 'description'.$i),
                'review_flags' => $flagged ? ['DESCRIPTION_SUGGESTS_CONFIGURATIONS'] : [],
                'variant_candidates' => [],
                'commercially_approved' => false,
            ];
        }
        return ['source' => 'herdegen', 'analysis_type' => 'herdegen_offline_listing_snapshot',
            'summary' => ['products' => 2, 'commercially_approved' => 0], 'products' => $products];
    }

    public function test_it_classifies_without_inventing_configuration_values(): void
    {
        $snapshot = $this->fixture();
        $inventory = (new HerdegenEvidenceAudit())->audit($snapshot);
        $review = (new HerdegenConfigurationReview())->review($snapshot, $inventory);
        self::assertSame(2, $review['summary']['products']);
        self::assertSame(1, $review['summary']['insufficient_configuration_evidence']);
        self::assertSame(1, $review['summary']['no_configuration_claim']);
        self::assertSame([], $review['records'][0]['size_values']);
        self::assertSame(0, $review['summary']['variants_created']);
    }

    public function test_it_rejects_tampered_inventory_records(): void
    {
        $snapshot = $this->fixture();
        $inventory = (new HerdegenEvidenceAudit())->audit($snapshot);
        $inventory['records'][0]['configuration_evidence_status'] = 'VERIFIED';
        $this->expectException(RuntimeException::class);
        (new HerdegenConfigurationReview())->review($snapshot, $inventory);
    }

    public function test_it_rejects_commercially_approved_source(): void
    {
        $snapshot = $this->fixture();
        $snapshot['products'][0]['commercially_approved'] = true;
        $inventory = (new HerdegenEvidenceAudit())->audit($this->fixture());
        $this->expectException(RuntimeException::class);
        (new HerdegenConfigurationReview())->review($snapshot, $inventory);
    }
}
