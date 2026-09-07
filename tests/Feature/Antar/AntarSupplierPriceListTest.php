<?php

declare(strict_types=1);

use App\Services\Antar\AntarSupplierPriceList;

it('loads the frozen Antar supplier price list effective 1 September 2026 with reconciled pricing', function (): void {
    $priceList = app(AntarSupplierPriceList::class)->load();

    expect($priceList['metadata'])
        ->toMatchArray([
            'source' => 'antar_supplier_price_list',
            'source_file' => 'A CENNIK ANTAR PELNY od 2026 09 01.xlsx',
            'source_sha256' => AntarSupplierPriceList::SOURCE_XLSX_SHA256,
            'effective_from' => '2026-09-01',
            'worksheet' => 'Cennik',
            'price_net_column' => 'E',
            'vat_column' => 'F',
            'price_gross_column' => 'G',
            'currency' => 'PLN',
        ])
        ->and($priceList['summary'])->toMatchArray([
            'rows' => 995,
            'unique_normalized_codes' => 940,
            'safe_price_codes' => 930,
            'ambiguous_price_codes' => 10,
            'consistent_duplicate_codes' => 19,
            'vat_row_breakdown' => [5 => 4, 8 => 947, 23 => 44],
            'gross_formula_mismatches' => 0,
        ])
        ->and($priceList['safe_index']['AT01001'])->toMatchArray([
            'net_minor' => 25741,
            'gross_minor' => 27800,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ])
        ->and($priceList['safe_index']['PER-FIT-PF-012-1'])->toMatchArray([
            'net_minor' => 2057,
            'gross_minor' => 2160,
            'vat_rate' => 5,
        ])
        ->and($priceList['ambiguous_index'])->toHaveKeys([
            'AKCESORIA',
            'AT04601-24',
            'AT04601-30',
            'AT04602',
            'AT04608-24',
            'AT04608-32',
            'AT52201',
            'AT53050',
            'MODEL-R',
            'MODEL-S',
        ]);
});

it('normalizes supplier codes exactly like the Antar catalogue importer', function (): void {
    $priceList = app(AntarSupplierPriceList::class);

    expect($priceList->normalizeCode(' PER-FIT PF-012/1* '))->toBe('PER-FIT-PF-012-1')
        ->and($priceList->normalizeCode('AT04601 (24)'))->toBe('AT04601-24')
        ->and($priceList->normalizeCode('  SAFE  WALK  '))->toBe('SAFE-WALK');
});
