<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedSizeClassifier;
use PHPUnit\Framework\TestCase;

final class QmedSizeClassifierTest extends TestCase
{
    private function analyse(string $name, array $rows): array
    {
        return (new QmedSizeClassifier())->analyse([
            'products' => [[
                'external_id' => 'test', 'name' => $name,
                'source_url' => 'https://qmedinfo.pl/example',
                'technical_tables' => [['rows' => $rows]],
            ]],
        ])['products'][0];
    }

    public function test_horizontal_size_and_measurements(): void
    {
        $p = $this->analyse('AFO-Fit', [
            ['Rozmiar', 'S', 'M', 'L', 'XL'],
            ['Rozmiar buta', '35 - 37', '37 - 39', '39 - 41', '41 - 44'],
        ]);
        self::assertSame(['S', 'M', 'L', 'XL'], $p['candidate_sizes']);
        self::assertSame('37 - 39', $p['size_evidence'][1]['measurements'][0]['value']);
        self::assertFalse($p['commercially_verified']);
    }

    public function test_vertical_sizing_and_footnote_header(): void
    {
        $p = $this->analyse('ONE Gorset Jewetta', [
            ['Rozmiar *', 'Obwód szyi (cm)', 'Wysokość'],
            ['XS', '25-32', '6,4'],
            ['S', '32-38', '7,6'],
        ]);
        self::assertSame(['XS', 'S'], $p['candidate_sizes']);
        self::assertCount(2, $p['size_evidence'][0]['measurements']);
    }

    public function test_nonleading_size_row(): void
    {
        $p = $this->analyse('Kamizelka ortopedyczna', [
            ['Typ', 'Dziecięcy', 'Dorosły'],
            ['Rozmiar', 'R00', 'R4'],
            ['Obwód klatki piersiowej (cm)', '<50', '90 - 120'],
        ]);
        self::assertSame(['R00', 'R4'], $p['candidate_sizes']);
        self::assertContains('NON_LEADING_SIZE_ROW', $p['review_flags']);
    }

    public function test_collar_stark_two_dimensional_matrix(): void
    {
        $p = $this->analyse('Collar Stark', [
            ['Rozmiary', '5,71 cm', '8,25 cm', '10,79 cm', '13,33 cm'],
            ['S (25-33)', 'S2', 'S3', 'S4', 'S5'],
            ['M (33-40)', 'M2', 'M3', 'M4', 'M5'],
            ['L (40-48)', 'L2', 'L3', 'L4', 'L5'],
            ['XL (48 i więcej)', 'XL2', 'XL3', 'XL4', 'XL5'],
        ]);
        self::assertCount(16, $p['size_evidence']);
        self::assertSame('S2', $p['candidate_sizes'][0]);
        self::assertSame('13,33 cm', $p['size_evidence'][3]['measurements'][1]['value']);
    }

    public function test_nt_bac_grid(): void
    {
        $p = $this->analyse('Kołnierz NT-BAC PLUS typu Florida', [
            ['*', '29,5 - 32,5', '32,6 - 35,5', '35,6 - 38,5', '38,6 - 41,5', '41,6 - 44,5', '44,6 - 47,5'],
            ['** 7', 'A1', 'A2', 'A3', 'A4', 'A5', 'A6'],
            ['** 8,5', 'B1', 'B2', 'B3', 'B4', 'B5', 'B6'],
            ['** 10', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6'],
            ['** 12', 'D1', 'D2', 'D3', 'D4', 'D5', 'D6'],
        ]);
        self::assertCount(24, $p['candidate_sizes']);
        self::assertSame('D6', $p['candidate_sizes'][23]);
    }

    public function test_stacked_numeric_sizes_retain_distinct_evidence(): void
    {
        $p = $this->analyse('Aparat Stacka', [
            ['Rozmiar', '2', '2', '4', '5', '5,5'],
            ['Obwód palca (cm)', '5,5 - 6', '6 - 6,5', '6,5 - 7', '7,5 - 8', '8,5 - 9'],
        ]);
        self::assertSame(['2', '4', '5', '5,5'], $p['candidate_sizes']);
        self::assertCount(5, $p['size_evidence']);
        self::assertSame('6 - 6,5', $p['size_evidence'][1]['measurements'][0]['value']);
        self::assertContains('DECIMAL_SIZE_LABEL_REVIEW', $p['review_flags']);
        self::assertContains('DUPLICATE_SIZE_LABEL_WITH_DISTINCT_EVIDENCE', $p['review_flags']);
    }

    public function test_mixed_kafo_measurement_and_normalisation(): void
    {
        $p = $this->analyse('Orteza KAFO', [
            ['Długość elementu unieruchamiającego stopę (D)', 'Długość stopy (cm)'],
            ['Rozmiar cm', 'cm'],
            ['S 16', '<22'], ['M 17,5', '22-25'], ['L 19', '>25'],
        ]);
        self::assertSame(['S', 'M', 'L'], $p['candidate_sizes']);
        self::assertContains('MIXED_SIZE_MEASUREMENT_TABLE_REVIEW', $p['review_flags']);
        $footnote = $this->analyse('Reflex', [
            ['Rozmiar', 'XXL', 'XXXL**'], ['Obwód uda* (cm)', '64-72', '72-78'],
        ]);
        self::assertSame(['XXL', 'XXXL'], $footnote['candidate_sizes']);
        self::assertSame('XXXL**', $footnote['size_evidence'][1]['raw_size']);
    }

    public function test_gendered_measurement_columns_and_no_candidates(): void
    {
        $p = $this->analyse('Ovation Hybrid Night Splint', [
            ['Rozmiar', 'Mężczyźni', 'Kobiety'],
            ['S-M', '39-42,5', '37,5-42,5'],
            ['L-XL', '43-49', '43-48'],
        ]);
        self::assertSame(['S-M', 'L-XL'], $p['candidate_sizes']);
        self::assertContains('GENDERED_MEASUREMENT_COLUMNS', $p['review_flags']);
        $empty = (new QmedSizeClassifier())->analyse(['products' => [[
            'name' => 'Universal?', 'external_id' => 'x', 'technical_tables' => [],
        ]]]);
        self::assertSame(0, $empty['counts']['products_with_candidates']);
        self::assertContains('NO_STRUCTURED_SIZE_CANDIDATES', $empty['products'][0]['review_flags']);
    }
}
