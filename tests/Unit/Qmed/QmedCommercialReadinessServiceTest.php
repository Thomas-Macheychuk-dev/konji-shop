<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedCommercialReadinessService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QmedCommercialReadinessServiceTest extends TestCase
{
    private function fixtures(): array
    {
        $source = ['products' => []];
        $review = [];
        $semantic = [];
        for ($i = 0; $i < 156; $i++) {
            $url = 'https://example.test/qmed/'. $i;
            $source['products'][] = [
                'name' => 'Qmed '.$i, 'source_url' => $url,
                'external_id' => (string) $i, 'sku' => null,
                'price_gross_amount' => null, 'variants_unresolved' => true,
                'variant_candidates' => [],
            ];
            $review[] = ['source_url' => $url, 'review_required_flags' => []];
            if ($i < 39) {
                $semantic[] = ['source_url' => $url, 'review_flags' => $i < 4 ? ['SIZE_UNRESOLVED'] : []];
            }
        }
        $evidence = [
            'summary' => ['products' => 156, 'semantic_review_required' => 4],
            'review' => ['products' => $review],
            'semantic' => ['products' => $semantic],
        ];
        return [$source, $evidence];
    }

    public function test_it_reports_all_missing_commercial_evidence_without_approval(): void
    {
        [$source, $evidence] = $this->fixtures();
        $report = (new QmedCommercialReadinessService())->audit($source, $evidence);
        self::assertCount(156, $report['products']);
        self::assertSame(0, $report['summary']['commercially_ready']);
        self::assertSame(0, $report['summary']['source_skus_present_unverified']);
        self::assertSame(0, $report['summary']['source_positive_gross_prices_present_unverified']);
        self::assertSame(4, $report['summary']['semantic_review_required']);
        self::assertSame(156, $report['summary']['reason_counts']['VAT_NOT_VERIFIED']);
        self::assertSame(156, $report['summary']['reason_counts']['MANUFACTURER_SKU_MISSING']);
        self::assertSame(4, $report['summary']['reason_counts']['SEMANTIC_EVIDENCE_REVIEW_REQUIRED']);
        self::assertSame('NOT_COMMERCIALLY_READY', $report['products'][0]['commercial_status']);
    }

    public function test_it_rejects_source_not_matched_to_review(): void
    {
        [$source, $evidence] = $this->fixtures();
        $source['products'][0]['source_url'] = 'https://example.test/other';
        $this->expectException(RuntimeException::class);
        (new QmedCommercialReadinessService())->audit($source, $evidence);
    }

    public function test_it_does_not_treat_nonempty_sku_as_commercial_approval(): void
    {
        [$source, $evidence] = $this->fixtures();
        $source['products'][0]['sku'] = 'UNVERIFIED-EXAMPLE';
        $report = (new QmedCommercialReadinessService())->audit($source, $evidence);
        self::assertSame(1, $report['summary']['source_skus_present_unverified']);
        self::assertFalse($report['products'][0]['commercially_approved']);
        self::assertContains('VAT_NOT_VERIFIED', $report['products'][0]['reasons']);
    }
}
