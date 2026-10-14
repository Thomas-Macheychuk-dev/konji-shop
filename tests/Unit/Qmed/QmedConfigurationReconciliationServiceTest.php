<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedConfigurationReconciliationService;
use App\Services\Qmed\QmedSupplierResponseValidator;
use PHPUnit\Framework\TestCase;

final class QmedConfigurationReconciliationServiceTest extends TestCase
{
    private function inputs(string $size, string $side, string $sideStatus = 'EXPLICIT_SIDE_VERSIONS'): array
    {
        $reviews = [];
        for ($i = 0; $i < 156; $i++) {
            $reviews[] = ['source_url' => 'https://example.test/'.$i,
                'candidate_sizes' => ['S', 'M', 'L'], 'review_required_flags' => []];
        }
        $semantics = [];
        for ($i = 0; $i < 39; $i++) {
            $semantics[] = ['source_url' => 'https://example.test/'.$i,
                'side_evidence_status' => $sideStatus,
                'evidence_categories' => ['EXPLICIT_SIDE_VERSIONS'], 'review_flags' => []];
        }
        $row = ['ID0', 'Product 0', 'https://example.test/0', 'SUP-1',
            'Standard', $size, $side, '100.00', '123.00', 'PLN', '23', 'IN_STOCK', 'REF', ''];
        $f = fopen('php://temp', 'w+');
        fputcsv($f, QmedSupplierResponseValidator::HEADERS, ',', '"', '');
        fputcsv($f, $row, ',', '"', '');
        rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);
        return [
            ['review' => ['products' => $reviews], 'semantic' => ['products' => $semantics]],
            ['summary' => ['rows' => 1], 'records' => [
                ['csv_record' => 2, 'source_url' => 'https://example.test/0',
                    'supplier_sku' => 'SUP-1', 'status' => 'EVIDENCE_COMPLETE_UNAPPROVED'],
            ]],
            $csv,
        ];
    }

    public function test_exact_size_and_side_match_remains_unapproved(): void
    {
        $report = (new QmedConfigurationReconciliationService())->reconcile(...$this->inputs('M', 'LEFT'));
        self::assertSame('SUPPORTED_BY_MANUFACTURER_EVIDENCE', $report['records'][0]['status']);
        self::assertSame(0, $report['summary']['commercially_approved']);
        self::assertSame(0, $report['summary']['variants_created']);
    }

    public function test_unknown_size_requires_review_not_invented_rejection(): void
    {
        $report = (new QmedConfigurationReconciliationService())->reconcile(...$this->inputs('XL', 'LEFT'));
        self::assertSame('REQUIRES_REVIEW', $report['records'][0]['status']);
        self::assertContains('SIZE_NOT_SUPPORTED_BY_AVAILABLE_EVIDENCE', $report['records'][0]['reasons']);
    }

    public function test_bilateral_claim_conflicts_with_explicit_side_versions(): void
    {
        $report = (new QmedConfigurationReconciliationService())->reconcile(...$this->inputs('M', 'BILATERAL'));
        self::assertSame('CONFLICTING_EVIDENCE', $report['records'][0]['status']);
    }

    public function test_missing_side_evidence_does_not_imply_support(): void
    {
        [$evidence, $validation, $csv] = $this->inputs('M', 'LEFT');
        $evidence['semantic']['products'][0]['source_url'] = 'https://example.test/100';
        $report = (new QmedConfigurationReconciliationService())->reconcile($evidence, $validation, $csv);
        self::assertSame('REQUIRES_REVIEW', $report['records'][0]['status']);
    }
}
