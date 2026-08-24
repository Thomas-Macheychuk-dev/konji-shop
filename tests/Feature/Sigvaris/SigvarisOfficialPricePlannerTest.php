<?php

use App\Services\Sigvaris\SigvarisOfficialPricePlanner;

it('calculates the 20 percent markup after official VAT from the base net price', function (): void {
    $plan = app(SigvarisOfficialPricePlanner::class)->build([
        'source' => 'sigvaris',
        'products' => [
            sigvarisOfficialPriceMappedProduct('97625', 'Compreflex Standard – Wrap Udo', [
                sigvarisOfficialPriceVariant('sigvaris-97625-1', 'SIGVARIS-97625-1', currentVat: 8),
            ]),
        ],
    ]);

    expect($plan['matched_product_count'])->toBe(1)
        ->and($plan['matched_variant_count'])->toBe(1)
        ->and($plan['error_count'])->toBe(0)
        ->and($plan['variants'][0]['base_net_minor'])->toBe(27700)
        ->and($plan['variants'][0]['vat_rate'])->toBe(8)
        ->and($plan['variants'][0]['selling_net_minor'])->toBe(33240)
        ->and($plan['variants'][0]['selling_gross_minor'])->toBe(35899)
        ->and($plan['variants'][0]['source_label'])->toBe('SIGVARIS WRAP Standard CompreFlex Udo');
});

it('uses the VAT from the official price list even when the frozen map had a different VAT', function (): void {
    $plan = app(SigvarisOfficialPricePlanner::class)->build([
        'source' => 'sigvaris',
        'products' => [
            sigvarisOfficialPriceMappedProduct('106544', 'Śliska Stopka od Pani TERESA® MEDICA', [
                sigvarisOfficialPriceVariant('sigvaris-106544-default', 'SIGVARIS-106544-DEFAULT', currentVat: 23),
            ]),
        ],
    ]);

    $variant = $plan['variants'][0];

    expect($variant['base_net_minor'])->toBe(700)
        ->and($variant['vat_rate'])->toBe(8)
        ->and($variant['current_map_vat_rate'])->toEqual(23)
        ->and($variant['selling_net_minor'])->toBe(840)
        ->and($variant['selling_gross_minor'])->toBe(907)
        ->and($variant['source_label'])->toBe('361504');
});

it('resolves official variant specific price exceptions deterministically', function (): void {
    $plan = app(SigvarisOfficialPricePlanner::class)->build([
        'source' => 'sigvaris',
        'products' => [
            sigvarisOfficialPriceMappedProduct('8064', 'ADVANCE | Rękaw uciskowy', [
                sigvarisOfficialPriceVariant('advance-soft-no-hand', 'A-1', attributes: [
                    ['code' => 'mankiet', 'value_label' => 'Miękki'],
                    ['code' => 'dlon', 'value_label' => 'Bez dłoni'],
                ]),
                sigvarisOfficialPriceVariant('advance-self-hand', 'A-2', attributes: [
                    ['code' => 'mankiet', 'value_label' => 'Samonośny'],
                    ['code' => 'dlon', 'value_label' => 'Z dłonią'],
                ]),
            ]),
            sigvarisOfficialPriceMappedProduct('23629', 'Uniwersalny pas brzuszny „30” z zapięciem', [
                sigvarisOfficialPriceVariant('pt0101-s', 'P-1', attributes: [
                    ['code' => 'rozmiar', 'value_label' => 'S'],
                ]),
                sigvarisOfficialPriceVariant('pt0101-xxxl', 'P-2', attributes: [
                    ['code' => 'rozmiar', 'value_label' => 'XXXL'],
                ]),
            ]),
            sigvarisOfficialPriceMappedProduct('106545', 'Rajstopy DELICATE CCL2', [
                sigvarisOfficialPriceVariant('delicate-m', 'D-1', attributes: [
                    ['code' => 'rozmiar', 'value_label' => 'M'],
                ]),
                sigvarisOfficialPriceVariant('delicate-m-plus', 'D-2', attributes: [
                    ['code' => 'rozmiar', 'value_label' => 'M Plus'],
                ]),
            ]),
            sigvarisOfficialPriceMappedProduct('83043', 'Pończochy CLASSIC CCL1', [
                sigvarisOfficialPriceVariant('classic-standard', 'C-1', sourceReference: '406OKCL'),
                sigvarisOfficialPriceVariant('classic-self', 'C-2', sourceReference: '406AOKCM'),
            ]),
        ],
    ]);

    $byVariant = collect($plan['variants'])->keyBy('external_variant_id');

    expect($plan['error_count'])->toBe(0)
        ->and($byVariant['advance-soft-no-hand']['base_net_minor'])->toBe(22600)
        ->and($byVariant['advance-soft-no-hand']['selling_gross_minor'])->toBe(29290)
        ->and($byVariant['advance-self-hand']['base_net_minor'])->toBe(23000)
        ->and($byVariant['advance-self-hand']['selling_gross_minor'])->toBe(29808)
        ->and($byVariant['pt0101-s']['base_net_minor'])->toBe(5200)
        ->and($byVariant['pt0101-xxxl']['base_net_minor'])->toBe(6400)
        ->and($byVariant['delicate-m']['base_net_minor'])->toBe(8200)
        ->and($byVariant['delicate-m-plus']['base_net_minor'])->toBe(10400)
        ->and($byVariant['classic-standard']['base_net_minor'])->toBe(6200)
        ->and($byVariant['classic-self']['base_net_minor'])->toBe(6900);
});

it('keeps products without an explicit official price out of the write-ready plan', function (): void {
    $plan = app(SigvarisOfficialPricePlanner::class)->build([
        'source' => 'sigvaris',
        'products' => [
            sigvarisOfficialPriceMappedProduct('28035', 'CLASSICAL postoperative stocking', [
                sigvarisOfficialPriceVariant('ambiguous-1', 'AMB-1'),
            ]),
            sigvarisOfficialPriceMappedProduct('106565', 'AESTHETIC BODY LONG', [
                sigvarisOfficialPriceVariant('body-long-1', 'BODY-LONG-1'),
            ]),
        ],
    ]);

    expect($plan['matched_product_count'])->toBe(0)
        ->and($plan['matched_variant_count'])->toBe(0)
        ->and($plan['unmatched_product_count'])->toBe(2)
        ->and($plan['unmatched_variant_count'])->toBe(2)
        ->and($plan['error_count'])->toBe(0)
        ->and($plan['ready_for_price_write_implementation'])->toBeFalse();
});

/**
 * @param list<array<string, mixed>> $variants
 * @return array<string, mixed>
 */
function sigvarisOfficialPriceMappedProduct(string $externalId, string $name, array $variants): array
{
    return [
        'source' => 'sigvaris',
        'product' => [
            'external_id' => $externalId,
            'name' => $name,
        ],
        'variants' => $variants,
    ];
}

/**
 * @param list<array<string, mixed>> $attributes
 * @return array<string, mixed>
 */
function sigvarisOfficialPriceVariant(
    string $externalVariantId,
    string $sku,
    array $attributes = [],
    ?string $sourceReference = null,
    int|float $currentVat = 8,
): array {
    return [
        'external_variant_id' => $externalVariantId,
        'sku' => $sku,
        'source_reference' => $sourceReference,
        'price_net_minor' => 10000,
        'price_gross_minor' => 10800,
        'vat_rate' => $currentVat,
        'attributes' => $attributes,
    ];
}
