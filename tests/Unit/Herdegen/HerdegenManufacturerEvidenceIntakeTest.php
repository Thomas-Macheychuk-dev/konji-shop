<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenManufacturerEvidenceIntake;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HerdegenManufacturerEvidenceIntakeTest extends TestCase
{
    private function review(): array
    {
        return [
            'source' => 'herdegen',
            'analysis_type' => 'herdegen02b_configuration_evidence_review',
            'summary' => ['products' => 1, 'commercially_approved' => 0, 'variants_created' => 0],
            'records' => [[
                'external_id' => str_repeat('a', 32), 'status' => 'INSUFFICIENT_CONFIGURATION_EVIDENCE',
                'commercially_approved' => false, 'orderable_variants_verified' => false,
            ]],
        ];
    }

    private function submission(array $entries = []): array
    {
        return ['source' => 'herdegen', 'analysis_type' => 'herdegen02c_manufacturer_evidence_submission', 'entries' => $entries];
    }

    public function test_empty_submission_stays_read_only(): void
    {
        $result = (new HerdegenManufacturerEvidenceIntake())->assess($this->review(), $this->submission());
        self::assertSame(1, $result['summary']['products_without_submission']);
        self::assertSame(0, $result['summary']['commercially_approved']);
        self::assertSame(0, $result['summary']['database_writes']);
    }

    public function test_claims_with_a_document_still_require_manual_review(): void
    {
        $entry = ['external_id' => str_repeat('a', 32), 'claims' => [['type' => 'size', 'value' => 'M']],
            'document' => ['sha256' => str_repeat('b', 64), 'reference' => 'catalogue page 4']];
        $result = (new HerdegenManufacturerEvidenceIntake())->assess($this->review(), $this->submission([$entry]));
        self::assertSame('DOCUMENTED_CLAIMS_REQUIRE_REVIEW', $result['records'][0]['status']);
        self::assertSame(0, $result['summary']['manufacturer_authenticity_verified']);
        self::assertSame(0, $result['summary']['orderable_variants_verified']);
    }

    public function test_duplicate_submission_is_rejected(): void
    {
        $entry = ['external_id' => str_repeat('a', 32), 'claims' => []];
        $this->expectException(RuntimeException::class);
        (new HerdegenManufacturerEvidenceIntake())->assess($this->review(), $this->submission([$entry, $entry]));
    }

    public function test_unmatched_document_does_not_create_product(): void
    {
        $result = (new HerdegenManufacturerEvidenceIntake())->assess($this->review(), $this->submission([
            ['external_id' => str_repeat('c', 32), 'claims' => []],
        ]));
        self::assertSame(1, $result['summary']['unmatched_entries']);
        self::assertSame(0, $result['summary']['variants_created']);
    }
}
