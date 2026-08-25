<?php

declare(strict_types=1);

use App\Services\Armedical\ArmedicalPricingPreflight;
use App\Services\Armedical\ArmedicalSupplierPriceList;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('loads the frozen ARmedical 2026 supplier net-price and VAT reference with audited invariants', function (): void {
    $priceList = app(ArmedicalSupplierPriceList::class)->load();

    expect($priceList['metadata'])->toMatchArray([
        'source_file' => 'Armedical_Cennik_na_2026_aktualny_od_04.03.2026 (1).xls',
        'source_sha256' => ArmedicalSupplierPriceList::SOURCE_XLS_SHA256,
        'effective_from' => '2026-03-04',
        'price_column' => 'Cena netto',
        'vat_column' => 'VAT %',
        'ignored_price_column' => 'Pakiet 5+1 cena*',
    ])
        ->and($priceList['summary'])->toMatchArray([
            'rows' => 245,
            'unique_codes' => 241,
            'duplicate_codes_with_consistent_price' => 1,
            'vat_row_breakdown' => [8 => 220, 23 => 25],
        ])
        ->and($priceList['index']['AR-023'])->toMatchArray([
            'net_minor' => 13500,
            'vat_rate' => 8,
            'source_rows' => [228],
        ])
        ->and($priceList['index']['EB-30R'])->toMatchArray([
            'net_minor' => 11500,
            'vat_rate' => 23,
            'source_rows' => [169],
        ])
        ->and($priceList['index']['PDG-6'])->toMatchArray([
            'net_minor' => 99,
            'vat_rate' => 8,
            'source_rows' => [301],
        ])
        ->and($priceList['index']['AR-010']['source_rows'])->toBe([190, 191, 192, 193, 194]);
});

it('maps supplier price and VAT by exact variant code alias and parent code without guessing unmatched variants', function (): void {
    $priceList = app(ArmedicalSupplierPriceList::class)->load();
    $result = app(ArmedicalPricingPreflight::class)->apply(armedicalPricingFixtureMap(), $priceList);

    expect($result['errors'])->toBe([])
        ->and($result['database_writes'])->toBeFalse()
        ->and($result['pricing_summary'])->toMatchArray([
            'planned_variants' => 5,
            'matched_variants' => 4,
            'unmatched_variants' => 1,
            'fully_priced_products' => 3,
            'unpriced_products' => 1,
            'vat_variant_breakdown' => [8 => 3, 23 => 1],
            'match_strategy_breakdown' => [
                'parent_code' => 1,
                'variant_alias' => 1,
                'variant_code' => 2,
            ],
        ])
        ->and($result['summary']['products_without_price'])->toBe(1)
        ->and($result['summary']['products_without_vat'])->toBe(1)
        ->and($result['ready_for_database_write'])->toBeFalse();

    $products = collect($result['products'])->keyBy(fn (array $item): string => (string) $item['product']['catalogue_number']);

    expect($products['AR-023']['variants'][0])->toMatchArray([
        'price_net_minor' => 13500,
        'price_gross_minor' => 14580,
        'vat_rate' => 8,
    ])
        ->and($products['AR-023']['variants'][0]['pricing_resolution'])->toMatchArray([
            'status' => 'matched',
            'price_code' => 'AR-023',
            'match_strategy' => 'parent_code',
            'supplier_source_rows' => [228],
        ])
        ->and($products['EB']['variants'][0])->toMatchArray([
            'price_net_minor' => 11500,
            'price_gross_minor' => 14145,
            'vat_rate' => 23,
        ])
        ->and($products['EB']['variants'][1])->toMatchArray([
            'price_net_minor' => 690,
            'price_gross_minor' => 745,
            'vat_rate' => 8,
        ])
        ->and($products['EB']['pricing'])->toMatchArray([
            'variant_pricing_complete' => true,
            'mixed_variant_pricing' => true,
            'requires_review' => false,
        ])
        ->and($products['PDG']['variants'][0])->toMatchArray([
            'price_net_minor' => 99,
            'price_gross_minor' => 107,
            'vat_rate' => 8,
        ])
        ->and($products['PDG']['variants'][0]['pricing_resolution'])->toMatchArray([
            'price_code' => 'PDG-6',
            'matched_from_code' => 'PDG-06',
            'match_strategy' => 'variant_alias',
        ])
        ->and($products['AR-600']['variants'][0])->toMatchArray([
            'price_net_minor' => null,
            'price_gross_minor' => null,
            'vat_rate' => null,
        ])
        ->and($products['AR-600']['variants'][0]['pricing_resolution']['status'])->toBe('unmatched')
        ->and($result['unmatched_products'][0])->toMatchArray([
            'catalogue_number' => 'AR-600',
            'unmatched_variant_count' => 1,
            'candidate_price_codes' => ['AR-600L', 'AR-600P'],
        ])
        ->and(collect($result['review_items'])->implode('\n'))->toContain('Cena netto')
        ->and(collect($result['review_items'])->implode('\n'))->toContain('AR-600L, AR-600P');
});

it('runs the ARmedical price and VAT command as a saved zero-write preflight', function (): void {
    Storage::fake('local');

    $source = 'scrapers/armedical/import-map-pricing-test.json';
    $save = 'scrapers/armedical/import-map-priced-test.json';
    Storage::disk('local')->put($source, json_encode(
        armedicalPricingFixtureMap(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));

    $exit = Artisan::call('armedical:pricing-preflight', [
        '--from' => $source,
        '--expected-catalogue-sha256' => '',
        '--expected-products' => 4,
        '--expected-variants' => 5,
        '--expected-matched' => 4,
        '--expected-unmatched' => 1,
        '--save' => $save,
        '--show-unmatched' => true,
        '--show-review' => true,
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('ARmedical price/VAT mapping preflight')
        ->and($output)->toContain('Frozen catalogue SHA gate: PASS')
        ->and($output)->toContain('Supplier XLS SHA gate: PASS')
        ->and($output)->toContain('Database writes: NO')
        ->and($output)->toContain('Price/VAT matched variants: 4')
        ->and($output)->toContain('Price/VAT unmatched variants: 1')
        ->and($output)->toContain('AR-600L, AR-600P')
        ->and($output)->toContain('PASS WITH REVIEW')
        ->and(Storage::disk('local')->exists($save))->toBeTrue();

    $saved = json_decode(Storage::disk('local')->get($save), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['pricing_structurally_valid'])->toBeTrue()
        ->and($saved['ready_for_database_write'])->toBeFalse()
        ->and($saved['supplier_price_list']['metadata']['source_sha256'])->toBe(ArmedicalSupplierPriceList::SOURCE_XLS_SHA256)
        ->and($saved['pricing_summary']['matched_variants'])->toBe(4)
        ->and($saved['pricing_summary']['unmatched_variants'])->toBe(1);
});

/** @return array<string, mixed> */
function armedicalPricingFixtureMap(): array
{
    return [
        'source' => 'armedical',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'mapped_product_count' => 4,
        'input_fingerprint' => [
            'sha256' => 'fixture-catalogue-sha',
        ],
        'summary' => [
            'planned_variants' => 5,
            'products_without_price' => 4,
            'products_without_vat' => 4,
        ],
        'errors' => [],
        'blocking_review_items' => [],
        'review_items' => [
            'ARmedical does not provide selling prices or VAT in the scraped catalogue; price and VAT must be supplied before any database write.',
        ],
        'products' => [
            armedicalPricingFixtureProduct('AR-023', 'Balkonik aluminiowy', [
                armedicalPricingFixtureVariant('armedical-ar-023-default'),
            ]),
            armedicalPricingFixtureProduct('EB', 'Taśma rehabilitacyjna ARband', [
                armedicalPricingFixtureVariant('armedical-eb-30r', 'EB-30R', 'EB-30R', '15cm x 45,5m'),
                armedicalPricingFixtureVariant('armedical-eb-m30', 'EB-M30', 'EB-M30', '15cm x 2mb'),
            ]),
            armedicalPricingFixtureProduct('PDG', 'Syntetyczny podkład podgipsowy', [
                armedicalPricingFixtureVariant('armedical-pdg-06', 'PDG-06', 'PDG-06', '6cm'),
            ]),
            armedicalPricingFixtureProduct('AR-600', 'Podpiętki do ortezy stopowo-goleniowej', [
                armedicalPricingFixtureVariant('armedical-ar-600-default'),
            ]),
        ],
    ];
}

/** @param list<array<string, mixed>> $variants @return array<string, mixed> */
function armedicalPricingFixtureProduct(string $catalogueNumber, string $name, array $variants): array
{
    return [
        'source' => 'armedical',
        'product' => [
            'external_id' => 'fixture-'.strtolower(str_replace([' ', '/'], '-', $catalogueNumber)),
            'catalogue_number' => $catalogueNumber,
            'name' => $name,
            'status' => 'draft',
        ],
        'pricing' => [
            'gross_minor' => null,
            'net_minor' => null,
            'vat_rate' => null,
            'currency' => 'PLN',
            'requires_review' => true,
            'source' => 'not_provided_by_armedical_catalogue',
        ],
        'variants' => $variants,
        'errors' => [],
        'review_items' => [],
        'blocking_review_items' => [],
    ];
}

/** @return array<string, mixed> */
function armedicalPricingFixtureVariant(
    string $externalVariantId,
    ?string $sourceExternalVariantId = null,
    ?string $sourceOptionLabel = null,
    ?string $sourceOptionValue = null,
): array {
    return [
        'source_external_variant_id' => $sourceExternalVariantId,
        'external_variant_id' => $externalVariantId,
        'sku' => null,
        'status' => 'draft',
        'is_default' => false,
        'price_gross_minor' => null,
        'price_net_minor' => null,
        'currency' => 'PLN',
        'vat_rate' => null,
        'source_option_label' => $sourceOptionLabel,
        'source_option_value' => $sourceOptionValue,
    ];
}
