<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedSupplierManifestService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QmedSupplierManifestServiceTest extends TestCase
{
    public function test_builds_blank_commercial_columns_for_156_products(): void
    {
        $products = []; $audited = [];
        for ($i = 0; $i < 156; $i++) {
            $url = "https://example.test/qmed/{$i}";
            $products[] = ['source_url' => $url, 'external_id' => "ID{$i}", 'name' => "Product {$i}"];
            $audited[] = [
                'source_url' => $url, 'external_id' => "ID{$i}", 'name' => "Product {$i}",
                'commercial_status' => 'NOT_COMMERCIALLY_READY', 'commercially_approved' => false,
                'approved_for_variant_import' => false, 'reasons' => ['MANUFACTURER_SKU_MISSING'],
                'semantic_review_flags' => [], 'structured_size_review_flags' => [],
            ];
        }
        $service = new QmedSupplierManifestService();
        $result = $service->build(['products' => $products], [
            'products' => $audited,
            'summary' => ['products' => 156, 'commercially_ready' => 0, 'commercially_approved' => 0],
        ]);
        self::assertCount(156, $result['manifest']['products']);
        self::assertCount(156, $result['rows']);
        self::assertSame('', $result['rows'][0][3]);
        self::assertSame(false, $result['manifest']['products'][0]['commercially_approved']);
        self::assertCount(157, array_filter(explode("\n", $service->csv($result['rows']))));
    }

    public function test_rejects_a_mismatched_baseline(): void
    {
        $this->expectException(RuntimeException::class);
        (new QmedSupplierManifestService())->build(['products' => []], ['products' => [], 'summary' => []]);
    }

    public function test_csv_escapes_spreadsheet_formulas(): void
    {
        $row = array_fill(0, count(QmedSupplierManifestService::HEADERS), '');
        $row[1] = '=1+1';
        self::assertStringContainsString("'=1+1", (new QmedSupplierManifestService())->csv([$row]));
    }
}
