<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use App\Services\Qmed\QmedSupplierManifestService;
use App\Services\Qmed\QmedSupplierResponseValidator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedSupplierResponseIntegrationTest extends TestCase
{
    private function fixture(): array
    {
        $source = ['products' => []];
        $audit = ['summary' => ['products' => 156, 'commercially_ready' => 0, 'commercially_approved' => 0], 'products' => []];
        for ($i = 0; $i < 156; ++$i) {
            $url = 'https://supplier.example/qmed/'.$i;
            $source['products'][] = ['source_url' => $url, 'external_id' => 'ID'.$i, 'name' => 'Product '.$i];
            $audit['products'][] = [
                'source_url' => $url, 'external_id' => 'ID'.$i, 'name' => 'Product '.$i,
                'commercial_status' => 'NOT_COMMERCIALLY_READY',
                'commercially_approved' => false, 'approved_for_variant_import' => false,
                'reasons' => ['MANUFACTURER_SKU_MISSING'],
                'semantic_review_flags' => [], 'structured_size_review_flags' => [],
            ];
        }
        $service = new QmedSupplierManifestService();
        $generated = $service->build($source, $audit);
        return [$source, $audit, $generated['manifest'], $service->csv($generated['rows'])];
    }

    private function responseRows(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        self::assertNotFalse($handle);
        fputcsv($handle, QmedSupplierResponseValidator::HEADERS, ',', '"', '');
        foreach ($rows as $row) fputcsv($handle, $row, ',', '"', '');
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        self::assertIsString($csv);
        return $csv;
    }

    private function completedRow(string $sku = 'QMED-SKU-M'): array
    {
        return [
            'ID0', 'Product 0', 'https://supplier.example/qmed/0', $sku,
            'Standard', 'M', 'LEFT', '100.00', '123.00', 'PLN', '23',
            'IN_STOCK', 'SUPPLIER-REF', '',
        ];
    }

    public function test_actual_qmed09c_builder_output_matches_validator_contract(): void
    {
        [, , $manifest, $csv] = $this->fixture();
        $result = (new QmedSupplierResponseValidator())->validate($manifest, $csv);
        self::assertSame('qmed09c_supplier_data_requirements', $manifest['analysis_type']);
        self::assertSame(156, $result['summary']['rows']);
        self::assertSame(156, $result['summary']['review_required']);
        self::assertSame(0, $result['summary']['rejected']);
        self::assertSame(0, $result['summary']['commercially_approved']);
    }

    public function test_multiple_distinct_supplier_skus_for_one_product_remain_unapproved(): void
    {
        [, , $manifest] = $this->fixture();
        $other = $this->completedRow('QMED-SKU-L');
        $other[5] = 'L';
        $result = (new QmedSupplierResponseValidator())->validate($manifest, $this->responseRows([
            $this->completedRow(), $other,
        ]));
        self::assertSame(2, $result['summary']['evidence_complete']);
        self::assertSame(0, $result['summary']['commercially_approved']);
        self::assertSame(0, $result['summary']['variants_created']);
    }

    public function test_duplicate_sku_is_case_insensitive(): void
    {
        [, , $manifest] = $this->fixture();
        $result = (new QmedSupplierResponseValidator())->validate($manifest, $this->responseRows([
            $this->completedRow('QMED-SKU-M'), $this->completedRow('qmed-sku-m'),
        ]));
        self::assertSame('REJECTED', $result['records'][1]['status']);
        self::assertContains('DUPLICATE_SUPPLIER_SKU', $result['records'][1]['errors']);
    }

    public function test_unknown_identity_and_invalid_side_price_and_vat_are_rejected(): void
    {
        [, , $manifest] = $this->fixture();
        $row = $this->completedRow();
        $row[0] = 'UNKNOWN-ID';
        $row[6] = 'BOTH?';
        $row[7] = '-0.01';
        $row[10] = '101';
        $result = (new QmedSupplierResponseValidator())->validate($manifest, $this->responseRows([$row]));
        self::assertSame('REJECTED', $result['records'][0]['status']);
        foreach (['SOURCE_ID_MISMATCH', 'SIDE_VALUE_INVALID', 'WHOLESALE_NET_PRICE_INVALID', 'VAT_RATE_INVALID'] as $reason) {
            self::assertContains($reason, $result['records'][0]['errors']);
        }
    }

    public function test_wrong_column_count_is_rejected_without_crashing(): void
    {
        [, , $manifest] = $this->fixture();
        $result = (new QmedSupplierResponseValidator())->validate($manifest, $this->responseRows([['ID0', 'Product 0']]));
        self::assertSame(1, $result['summary']['rejected']);
        self::assertContains('COLUMN_COUNT_INVALID', $result['records'][0]['errors']);
    }

    public function test_missing_supplier_fields_require_review_not_approval(): void
    {
        [, , $manifest] = $this->fixture();
        $row = $this->completedRow();
        $row[3] = '';
        $row[11] = '';
        $result = (new QmedSupplierResponseValidator())->validate($manifest, $this->responseRows([$row]));
        self::assertSame('REVIEW_REQUIRED', $result['records'][0]['status']);
        self::assertContains('SUPPLIER_SKU_MISSING', $result['records'][0]['review_reasons']);
        self::assertContains('AVAILABILITY_MISSING', $result['records'][0]['review_reasons']);
    }

    public function test_command_rejects_stale_manifest_hash_and_does_not_write_output(): void
    {
        Storage::fake('local');
        [$source, $audit, $manifest, $csv] = $this->fixture();
        $sourceRaw = json_encode($source, JSON_THROW_ON_ERROR);
        $audit['source_report_sha256'] = hash('sha256', $sourceRaw);
        $auditRaw = json_encode($audit, JSON_THROW_ON_ERROR);
        $manifest['source_report_sha256'] = hash('sha256', $sourceRaw);
        $manifest['audit_report_sha256'] = str_repeat('0', 64); // deliberately stale
        $disk = Storage::disk('local');
        $disk->put('scrapers/qmed/full-product-data.json', $sourceRaw);
        $disk->put('scrapers/qmed/analysis/qmed09b-commercial-readiness.json', $auditRaw);
        $disk->put('scrapers/qmed/analysis/qmed09c-supplier-requirements.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $disk->put('scrapers/qmed/analysis/qmed09c-supplier-template.csv', $csv);
        $this->artisan('qmed:validate-supplier-response')->assertExitCode(1);
        $disk->assertMissing('scrapers/qmed/analysis/qmed09d-supplier-response-validation.json');
    }
}
