<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenDocumentationManifest;
use App\Services\Herdegen\HerdegenSupplierResponseValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HerdegenSupplierResponseValidatorTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'source' => 'herdegen', 'analysis_type' => 'herdegen02d_documentation_requirements',
            'summary' => ['documentation_required' => 1, 'template_rows' => 1, 'commercially_approved' => 0, 'variants_created' => 0],
            'records' => [['external_id' => str_repeat('a', 32), 'source_url' => 'https://www.orteo.pl/test-product',
                'status' => 'MANUFACTURER_DOCUMENTATION_REQUIRED', 'commercially_approved' => false]],
        ];
    }

    private function csv(array $overrides = []): string
    {
        $row = array_replace([str_repeat('a', 32), '', 'https://www.orteo.pl/test-product', 'Official specification',
            '', '', '', '', '', '', '', ''], $overrides);
        return (new HerdegenDocumentationManifest())->csv([$row]);
    }

    public function test_blank_template_is_awaiting_supplier_data(): void
    {
        $report = (new HerdegenSupplierResponseValidator())->validate($this->manifest(), $this->csv());
        self::assertSame('AWAITING_SUPPLIER_DATA', $report['records'][0]['status']);
        self::assertSame(0, $report['summary']['variants_created']);
    }

    public function test_documented_claim_is_not_automatically_approved(): void
    {
        $report = (new HerdegenSupplierResponseValidator())->validate($this->manifest(),
            $this->csv([4 => 'Manufacturer catalogue', 5 => str_repeat('b', 64), 6 => 'M001']));
        self::assertSame('DOCUMENTED_CLAIMS_REQUIRE_REVIEW', $report['records'][0]['status']);
        self::assertSame(0, $report['summary']['commercially_approved']);
    }

    public function test_missing_digest_is_reported(): void
    {
        $report = (new HerdegenSupplierResponseValidator())->validate($this->manifest(), $this->csv([4 => 'Catalogue', 7 => 'M']));
        self::assertContains('INVALID_OR_MISSING_DOCUMENT_SHA256', $report['records'][0]['issues']);
    }

    public function test_unknown_product_is_reported(): void
    {
        $report = (new HerdegenSupplierResponseValidator())->validate($this->manifest(), $this->csv([0 => str_repeat('b', 32)]));
        self::assertContains('UNKNOWN_PRODUCT', $report['records'][0]['issues']);
    }

    public function test_invalid_header_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new HerdegenSupplierResponseValidator())->validate($this->manifest(), "wrong,header\n");
    }
}
