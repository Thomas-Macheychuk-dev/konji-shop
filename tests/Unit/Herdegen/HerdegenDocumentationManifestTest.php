<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenDocumentationManifest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HerdegenDocumentationManifestTest extends TestCase
{
    private function reports(): array
    {
        $id = str_repeat('a', 32);
        $review = [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02b_configuration_evidence_review',
            'summary' => ['products' => 1, 'configuration_review_required' => 1, 'commercially_approved' => 0, 'variants_created' => 0],
            'records' => [[
                'external_id' => $id,
                'source_url' => 'https://www.orteo.pl/test-product',
                'status' => 'INSUFFICIENT_CONFIGURATION_EVIDENCE',
                'commercially_approved' => false,
                'orderable_variants_verified' => false,
            ]],
        ];
        $intake = [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02c_manufacturer_evidence_intake',
            'summary' => [
                'products' => 1, 'submitted_entries' => 0, 'unmatched_entries' => 0,
                'products_without_submission' => 1, 'commercially_approved' => 0, 'variants_created' => 0,
            ],
            'records' => [],
        ];
        return [$review, $intake];
    }

    public function test_it_exports_only_review_candidates_with_blank_supplier_fields(): void
    {
        [$review, $intake] = $this->reports();
        $result = (new HerdegenDocumentationManifest())->build($review, $intake);
        self::assertSame(1, $result['manifest']['summary']['documentation_required']);
        self::assertSame('', $result['rows'][0][4]);
        self::assertSame(0, $result['manifest']['summary']['variants_created']);
        self::assertStringContainsString('manufacturer_document_reference', (new HerdegenDocumentationManifest())->csv($result['rows']));
    }

    public function test_it_rejects_commercial_approval(): void
    {
        [$review, $intake] = $this->reports();
        $review['summary']['commercially_approved'] = 1;
        $this->expectException(RuntimeException::class);
        (new HerdegenDocumentationManifest())->build($review, $intake);
    }

    public function test_it_rejects_unmatched_intake_record(): void
    {
        [$review, $intake] = $this->reports();
        $intake['records'] = [['external_id' => str_repeat('b', 32)]];
        $intake['summary']['submitted_entries'] = 1;
        $intake['summary']['products_without_submission'] = 0;
        $this->expectException(RuntimeException::class);
        (new HerdegenDocumentationManifest())->build($review, $intake);
    }
}
