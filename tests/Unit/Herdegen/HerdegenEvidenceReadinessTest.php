<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenDocumentationManifest;
use App\Services\Herdegen\HerdegenEvidenceReadiness;
use App\Services\Herdegen\HerdegenSupplierResponseValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HerdegenEvidenceReadinessTest extends TestCase
{
    private function fixture(): array
    {
        $id = str_repeat('a', 32);
        $review = [
            'source' => 'herdegen', 'analysis_type' => 'herdegen02b_configuration_evidence_review',
            'summary' => ['products' => 1, 'configuration_review_required' => 1, 'commercially_approved' => 0, 'variants_created' => 0],
            'records' => [[
                'external_id' => $id, 'source_url' => 'https://www.orteo.pl/test-product',
                'status' => 'INSUFFICIENT_CONFIGURATION_EVIDENCE', 'commercially_approved' => false,
                'orderable_variants_verified' => false,
            ]],
        ];
        $intake = [
            'source' => 'herdegen', 'analysis_type' => 'herdegen02c_manufacturer_evidence_intake',
            'summary' => ['products' => 1, 'submitted_entries' => 0, 'unmatched_entries' => 0,
                'products_without_submission' => 1, 'commercially_approved' => 0, 'variants_created' => 0],
            'records' => [],
        ];
        $builder = new HerdegenDocumentationManifest();
        $built = $builder->build($review, $intake);
        $csv = $builder->csv($built['rows']);
        $manifest = $built['manifest'];
        $validation = (new HerdegenSupplierResponseValidator())->validate($manifest, $csv);
        return [$review, $intake, $manifest, $validation, $csv];
    }

    public function test_it_marks_blank_supplier_rows_as_awaiting_data(): void
    {
        $report = (new HerdegenEvidenceReadiness())->reconcile(...$this->fixture());
        self::assertSame(1, $report['summary']['awaiting_supplier_data']);
        self::assertSame(0, $report['summary']['commercially_approved']);
        self::assertSame(0, $report['summary']['database_writes']);
    }

    public function test_it_rejects_a_modified_manifest(): void
    {
        $fixture = $this->fixture();
        $fixture[2]['records'][0]['source_url'] = 'https://www.orteo.pl/changed';
        $this->expectException(RuntimeException::class);
        (new HerdegenEvidenceReadiness())->reconcile(...$fixture);
    }

    public function test_it_rejects_stale_csv_validation(): void
    {
        $fixture = $this->fixture();
        $fixture[4] .= "extra,column\n";
        $this->expectException(RuntimeException::class);
        (new HerdegenEvidenceReadiness())->reconcile(...$fixture);
    }
}
