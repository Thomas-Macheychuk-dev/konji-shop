<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedSupplierResponseValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QmedSupplierResponseValidatorTest extends TestCase
{
    private function manifest(): array
    {
        $products = [];
        for ($i = 0; $i < 156; ++$i) {
            $products[] = ['manufacturer_source_id' => 'ID'.$i,
                'product_name' => 'Product '.$i, 'source_url' => 'https://supplier.example/p/'.$i];
        }
        return ['analysis_type' => 'qmed09c_supplier_data_requirements',
            'summary' => ['products' => 156, 'commercially_approved' => 0], 'products' => $products];
    }

    private function csv(array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, QmedSupplierResponseValidator::HEADERS, ',', '"', '');
        foreach ($rows as $row) fputcsv($f, $row, ',', '"', '');
        rewind($f);
        $out = stream_get_contents($f);
        fclose($f);
        return $out;
    }

    private function row(string $sku = 'SUP-1'): array
    {
        return ['ID0', 'Product 0', 'https://supplier.example/p/0', $sku,
            'Standard', 'M', 'LEFT', '100.00', '123.00', 'PLN', '23', 'IN_STOCK', 'REF-1', ''];
    }

    public function test_complete_supplier_evidence_is_not_approved_for_sale(): void
    {
        $result = (new QmedSupplierResponseValidator())->validate($this->manifest(), $this->csv([$this->row()]));
        self::assertSame('EVIDENCE_COMPLETE_UNAPPROVED', $result['records'][0]['status']);
        self::assertSame(1, $result['summary']['evidence_complete']);
        self::assertSame(0, $result['summary']['commercially_approved']);
        self::assertSame(0, $result['summary']['variants_created']);
    }

    public function test_unfilled_supplier_template_requires_review(): void
    {
        $row = ['ID0', 'Product 0', 'https://supplier.example/p/0'];
        $row = array_pad($row, 14, '');
        $result = (new QmedSupplierResponseValidator())->validate($this->manifest(), $this->csv([$row]));
        self::assertSame('REVIEW_REQUIRED', $result['records'][0]['status']);
        self::assertContains('SUPPLIER_SKU_MISSING', $result['records'][0]['review_reasons']);
    }

    public function test_duplicate_sku_is_rejected_across_products(): void
    {
        $second = $this->row();
        $second[0] = 'ID1'; $second[1] = 'Product 1'; $second[2] = 'https://supplier.example/p/1';
        $result = (new QmedSupplierResponseValidator())->validate($this->manifest(), $this->csv([$this->row(), $second]));
        self::assertSame('REJECTED', $result['records'][1]['status']);
        self::assertContains('DUPLICATE_SUPPLIER_SKU', $result['records'][1]['errors']);
    }

    public function test_multiple_distinct_skus_for_same_product_are_supported(): void
    {
        $second = $this->row('SUP-2');
        $second[5] = 'L';
        $result = (new QmedSupplierResponseValidator())->validate($this->manifest(), $this->csv([$this->row(), $second]));
        self::assertSame(2, $result['summary']['evidence_complete']);
    }

    public function test_unknown_product_and_invalid_values_are_rejected(): void
    {
        $row = $this->row();
        $row[2] = 'https://supplier.example/unknown'; $row[6] = 'MAYBE'; $row[7] = '-1';
        $result = (new QmedSupplierResponseValidator())->validate($this->manifest(), $this->csv([$row]));
        self::assertSame('REJECTED', $result['records'][0]['status']);
        self::assertContains('SOURCE_URL_UNKNOWN', $result['records'][0]['errors']);
        self::assertContains('SIDE_VALUE_INVALID', $result['records'][0]['errors']);
        self::assertContains('WHOLESALE_NET_PRICE_INVALID', $result['records'][0]['errors']);
    }

    public function test_rejects_changed_csv_headers(): void
    {
        $this->expectException(RuntimeException::class);
        (new QmedSupplierResponseValidator())->validate($this->manifest(), "source_url,supplier_sku\n");
    }
}
