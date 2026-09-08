<?php

declare(strict_types=1);

use App\Services\Antar\AntarPriceReconciliation;
use Illuminate\Support\Facades\Storage;

it('classifies exact supplier codes as eligible without database writes', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct('AT01001', 'Exact product', 'https://antar.net/produkt/exact-product/'),
    ]), 'source-sha');

    expect($result['database_writes'])->toBeFalse()
        ->and($result['network_requests'])->toBeFalse()
        ->and($result['summary'])->toMatchArray([
            'source_products' => 1,
            'eligible_priced_products' => 1,
            'exact_price_matches' => 1,
            'manual_price_review' => 0,
            'excluded_unpriced_products' => 0,
            'hard_errors' => 0,
        ])
        ->and($result['ready_for_selective_priced_import'])->toBeTrue()
        ->and($result['ready_for_full_catalogue_import'])->toBeTrue()
        ->and($result['eligible_priced_products'][0]['match_method'])->toBe('exact_supplier_code')
        ->and($result['eligible_priced_products'][0]['proposed'])->toBe([
            'price_net_amount' => 25741,
            'price_gross_amount' => 27800,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ]);
});

it('uses only explicit approved SKU aliases for reconciled catalogue prices', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct('SNW500', 'Ławeczka nawannowa SNW-500', 'https://antar.net/produkt/laweczka-nawannowa-snw-500/'),
        antarReconciliationProduct('HF6002-ALU', 'E materac rurowy Xi (ALU)', 'https://antar.net/produkt/e-materac-rurowy-xi-alu/'),
    ]));

    expect($result['summary']['eligible_priced_products'])->toBe(2)
        ->and($result['summary']['explicit_sku_alias_matches'])->toBe(2)
        ->and($result['eligible_priced_products'][0]['normalized_supplier_code'])->toBe('SNW-500')
        ->and($result['eligible_priced_products'][0]['proposed']['price_net_amount'])->toBe(15137)
        ->and($result['eligible_priced_products'][1]['normalized_supplier_code'])->toBe('XI-RUROWY-ALU')
        ->and($result['eligible_priced_products'][1]['proposed']['price_net_amount'])->toBe(42027);
});

it('recovers the exact Torba AT03107 supplier row and does not use the mattress price', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct(null, 'Torba na materac rehabilitacyjny trójdzielny AT03107', 'https://antar.net/produkt/torba-na-materac-rehabilitacyjny-trojdzielny-at03107/'),
    ]));

    $eligible = $result['eligible_priced_products'][0];

    expect($eligible['match_method'])->toBe('explicit_url_recovery')
        ->and($eligible['normalized_supplier_code'])->toBe('TORBA-AT03107')
        ->and($eligible['proposed'])->toBe([
            'price_net_amount' => 5900,
            'price_gross_amount' => 7257,
            'vat_rate' => 23,
            'currency' => 'PLN',
        ]);
});

it('uses the exact supplier spreadsheet row for the Opti-Comfort replacement handle instead of the crutch price', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct('OPTI-COMFORT', 'Kula łokciowa OPTI-COMFORT', 'https://antar.net/produkt/kula-lokciowa-opti-comfort/'),
        antarReconciliationProduct('OPTI-COMFORT', 'Uchwyt/podparcie do kul Opti-comfort', 'https://antar.net/produkt/uchywty-poparcia-do-kul-comfort/'),
    ]));

    expect($result['summary']['eligible_priced_products'])->toBe(2)
        ->and($result['summary']['exact_price_matches'])->toBe(1)
        ->and($result['summary']['explicit_supplier_row_override_matches'])->toBe(1)
        ->and($result['eligible_priced_products'][0]['proposed'])->toBe([
            'price_net_amount' => 4284,
            'price_gross_amount' => 4627,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ])
        ->and($result['eligible_priced_products'][1]['match_method'])->toBe('explicit_supplier_row_override')
        ->and($result['eligible_priced_products'][1]['approved_catalogue_sku'])->toBe('OPTI-COMFORT')
        ->and($result['eligible_priced_products'][1]['supplier']['source_rows'])->toBe([805])
        ->and($result['eligible_priced_products'][1]['proposed'])->toBe([
            'price_net_amount' => 1591,
            'price_gross_amount' => 1957,
            'vat_rate' => 23,
            'currency' => 'PLN',
        ]);
});

it('does not infer a supplier SKU from a number embedded in a product name or URL', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct(null, 'Uchwyt specjalny 150°', 'https://antar.net/produkt/uchwyt-specjalny-150/'),
    ]));

    expect($result['summary']['eligible_priced_products'])->toBe(0)
        ->and($result['summary']['manual_price_review'])->toBe(0)
        ->and($result['summary']['excluded_unpriced_products'])->toBe(1)
        ->and($result['excluded_unpriced_products'][0]['reason'])->toBe('missing_scraped_sku')
        ->and($result['excluded_unpriced_products'][0]['note'])->toContain('substring matching is intentionally forbidden');
});

it('keeps suffix and multi-code products in manual review instead of collapsing them', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct('AT51112-NH', 'Chodzik AT51112 NH', 'https://antar.net/produkt/chodzik-at51112-nh/'),
        antarReconciliationProduct('AT5141-0-AT51411-AT51412', 'Piłka do rehabilitacji', 'https://antar.net/produkt/pilka-do-rehabilitacji-55-cm-at51410/'),
    ]));

    expect($result['summary']['eligible_priced_products'])->toBe(0)
        ->and($result['summary']['manual_price_review'])->toBe(2)
        ->and($result['summary']['explicit_manual_review_products'])->toBe(2)
        ->and($result['manual_price_review'][0]['reason'])->toBe('suffix_requires_review')
        ->and($result['manual_price_review'][0]['candidate_supplier_codes'])->toBe(['AT51112'])
        ->and($result['manual_price_review'][1]['reason'])->toBe('variant_split_required')
        ->and($result['manual_price_review'][1]['candidate_supplier_codes'])->toBe(['AT51410', 'AT51411', 'AT51412']);
});

it('keeps exact supplier codes with conflicting spreadsheet prices in manual review', function (): void {
    $result = app(AntarPriceReconciliation::class)->build(antarReconciliationSource([
        antarReconciliationProduct('AT04602', 'Pas brzuszny AT04602', 'https://antar.net/produkt/pas-brzuszny-at04602/'),
    ]));

    expect($result['summary']['manual_price_review'])->toBe(1)
        ->and($result['summary']['ambiguous_supplier_price_products'])->toBe(1)
        ->and($result['manual_price_review'][0]['reason'])->toBe('ambiguous_supplier_price')
        ->and($result['manual_price_review'][0]['candidate_supplier_codes'])->toBe(['AT04602'])
        ->and($result['ready_for_full_catalogue_import'])->toBeFalse();
});

it('runs the reconciliation command from the Laravel local disk and saves read-only evidence', function (): void {
    Storage::fake('local');

    $sourcePath = 'scrapers/antar/product-data-test.json';
    $savePath = 'scrapers/antar/price-reconciliation-test.json';

    Storage::disk('local')->put($sourcePath, json_encode(antarReconciliationSource([
        antarReconciliationProduct('AT01001', 'Exact product', 'https://antar.net/produkt/exact-product/'),
        antarReconciliationProduct('SNW500', 'Ławeczka nawannowa SNW-500', 'https://antar.net/produkt/laweczka-nawannowa-snw-500/'),
        antarReconciliationProduct(null, 'Uchwyt specjalny 150°', 'https://antar.net/produkt/uchwyt-specjalny-150/'),
    ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $this->artisan('antar:price-reconcile', [
        '--from' => $sourcePath,
        '--save' => $savePath,
        '--show-review' => true,
    ])
        ->expectsOutputToContain('Database writes: NO')
        ->expectsOutputToContain('Fuzzy/name substring matching: NO')
        ->expectsOutputToContain('Scraped products: 3')
        ->expectsOutputToContain('Eligible priced products: 2')
        ->expectsOutputToContain('Excluded unpriced products: 1')
        ->expectsOutputToContain('Ready for selective priced import: YES')
        ->expectsOutputToContain('Ready for full Antar catalogue import: NO')
        ->assertSuccessful();

    Storage::disk('local')->assertExists($savePath);

    $saved = json_decode(Storage::disk('local')->get($savePath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['summary']['eligible_priced_products'])->toBe(2)
        ->and($saved['summary']['excluded_unpriced_products'])->toBe(1);
});

/** @param list<array<string, mixed>> $products */
function antarReconciliationSource(array $products): array
{
    return [
        'source' => 'antar',
        'product_count' => count($products),
        'products' => $products,
    ];
}

/** @return array<string, mixed> */
function antarReconciliationProduct(?string $sku, string $name, string $url): array
{
    return [
        'source' => 'antar',
        'external_product_id' => trim((string) parse_url($url, PHP_URL_PATH), '/'),
        'name' => $name,
        'sku' => $sku,
        'canonical_url' => $url,
        'images' => [['url' => 'https://antar.net/example.jpg']],
        'documents' => [['url' => 'https://antar.net/example.pdf']],
    ];
}
