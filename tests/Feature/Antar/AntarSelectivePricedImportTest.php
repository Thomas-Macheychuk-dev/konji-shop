<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Antar\AntarPriceReconciliation;
use App\Services\Antar\AntarProductionPreflight;
use App\Services\Antar\AntarSelectivePricedImportPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('preflights only the frozen deterministic Antar priced cohort without database writes', function (): void {
    Storage::fake('local');

    writeAntarSelectivePricedFixture(antarSelectivePricedProducts());

    $this->artisan('antar:import-priced', [
        '--from' => 'scrapers/antar/product-data.json',
        '--reconciliation' => 'scrapers/antar/price-reconciliation-2026-09-01.json',
    ])
        ->expectsOutputToContain('Database writes: NO')
        ->expectsOutputToContain('Product status: draft (forced)')
        ->expectsOutputToContain('Source products: 5')
        ->expectsOutputToContain('Eligible priced products: 3')
        ->expectsOutputToContain('Manual price review retained outside import: 1')
        ->expectsOutputToContain('Excluded unpriced products retained outside import: 1')
        ->expectsOutputToContain('Ready for local draft import: YES')
        ->expectsOutputToContain('Selected eligible products: 3')
        ->expectsOutputToContain('Network requests: NO')
        ->expectsOutputToContain('PASS: selective priced import preflight succeeded.')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports only eligible Antar products as drafts with exact approved net gross and VAT pricing', function (): void {
    Storage::fake('local');

    writeAntarSelectivePricedFixture(antarSelectivePricedProducts());

    $this->artisan('antar:import-priced', [
        '--from' => 'scrapers/antar/product-data.json',
        '--reconciliation' => 'scrapers/antar/price-reconciliation-2026-09-01.json',
        '--execute' => true,
        '--no-images' => true,
        '--no-documents' => true,
    ])->assertSuccessful();

    expect(Product::query()->where('external_source', 'antar')->count())->toBe(3)
        ->and(Product::query()->where('external_source', 'antar')->where('external_id', 'at01001')->exists())->toBeTrue()
        ->and(Product::query()->where('external_source', 'antar')->where('external_id', 'laweczka-nawannowa-snw-500')->exists())->toBeTrue()
        ->and(Product::query()->where('external_source', 'antar')->where('external_id', 'torba-na-materac-rehabilitacyjny-trojdzielny-at03107')->exists())->toBeTrue()
        ->and(Product::query()->where('external_source', 'antar')->where('external_id', 'lozko-elektryczne-at52201')->exists())->toBeFalse()
        ->and(Product::query()->where('external_source', 'antar')->where('external_id', 'chodzik-stalowy-trzykolowy-at51004')->exists())->toBeFalse();

    $exactVariant = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'at01001')
        ->firstOrFail()
        ->variants()
        ->firstOrFail();

    expect($exactVariant->sku)->toBe('AT01001')
        ->and($exactVariant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($exactVariant->price_net_amount)->toBe(25741)
        ->and($exactVariant->price_gross_amount)->toBe(27800)
        ->and($exactVariant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($exactVariant->currency)->toBe(Currency::PLN);

    $aliasProduct = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'laweczka-nawannowa-snw-500')
        ->firstOrFail();
    $aliasVariant = $aliasProduct->variants()->firstOrFail();

    expect($aliasProduct->external_parent_sku)->toBe('SNW-500')
        ->and($aliasVariant->sku)->toBe('SNW500')
        ->and($aliasVariant->price_net_amount)->toBe(15137)
        ->and($aliasVariant->price_gross_amount)->toBe(16348)
        ->and($aliasVariant->vat_rate)->toBe(VatRate::VAT_8);

    $bagProduct = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'torba-na-materac-rehabilitacyjny-trojdzielny-at03107')
        ->firstOrFail();
    $bagVariant = $bagProduct->variants()->firstOrFail();

    expect($bagProduct->status)->toBe(ProductStatus::DRAFT)
        ->and($bagProduct->external_parent_sku)->toBe('TORBA-AT03107')
        ->and($bagVariant->sku)->toBe('TORBA-AT03107')
        ->and($bagVariant->price_net_amount)->toBe(5900)
        ->and($bagVariant->price_gross_amount)->toBe(7257)
        ->and($bagVariant->vat_rate)->toBe(VatRate::VAT_23)
        ->and($bagVariant->currency)->toBe(Currency::PLN);
});

it('resolves cross-source and duplicate source SKUs into deterministic Antar storage SKUs', function (): void {
    Storage::fake('local');

    $products = [
        antarSelectivePricedProduct([
            'external_product_id' => 'kula-lokciowa-opti-comfort',
            'slug' => 'kula-lokciowa-opti-comfort',
            'name' => 'Kula łokciowa OPTI-COMFORT',
            'sku' => 'OPTI-COMFORT',
            'canonical_url' => 'https://antar.net/produkt/kula-lokciowa-opti-comfort/',
        ]),
        antarSelectivePricedProduct([
            'external_product_id' => 'uchywty-poparcia-do-kul-comfort',
            'slug' => 'uchywty-poparcia-do-kul-comfort',
            'name' => 'Uchwyt/podparcie do kul Opti-comfort',
            'sku' => 'OPTI-COMFORT',
            'canonical_url' => 'https://antar.net/produkt/uchywty-poparcia-do-kul-comfort/',
        ]),
        antarSelectivePricedProduct([
            'external_product_id' => 'at01001',
            'slug' => 'at01001',
            'name' => 'Ultralekkie krzesło toaletowe AT01001',
            'sku' => 'AT01001',
            'canonical_url' => 'https://antar.net/produkt/at01001/',
        ]),
    ];
    writeAntarSelectivePricedFixture($products);

    $other = Product::query()->create([
        'name' => 'Existing other-source product',
        'slug' => 'existing-other-source-product',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'medi',
        'external_id' => 'existing-medi-at01001',
    ]);
    ProductVariant::query()->create([
        'product_id' => $other->id,
        'sku' => 'AT01001',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 100,
        'price_gross_amount' => 108,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::OUT_OF_STOCK,
        'is_default' => true,
        'external_variant_id' => 'medi-existing-at01001',
    ]);

    $this->artisan('antar:import-priced', [
        '--from' => 'scrapers/antar/product-data.json',
        '--reconciliation' => 'scrapers/antar/price-reconciliation-2026-09-01.json',
    ])
        ->expectsOutputToContain('Eligible source-SKU collisions with other sources: 1 (resolved with Antar-namespaced storage SKUs)')
        ->expectsOutputToContain('Duplicate source-SKU groups resolved: 1')
        ->expectsOutputToContain('Ready for local draft import: YES')
        ->assertSuccessful();
});

it('fails closed when product data changes after the saved reconciliation was generated', function (): void {
    Storage::fake('local');

    $products = antarSelectivePricedProducts();
    writeAntarSelectivePricedFixture($products);

    $products[0]['name'] = 'Changed after reconciliation';
    Storage::disk('local')->put(
        'scrapers/antar/product-data.json',
        json_encode(['products' => $products], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );

    $this->artisan('antar:import-priced', [
        '--from' => 'scrapers/antar/product-data.json',
        '--reconciliation' => 'scrapers/antar/price-reconciliation-2026-09-01.json',
        '--execute' => true,
        '--no-images' => true,
        '--no-documents' => true,
    ])
        ->expectsOutputToContain('Saved Antar reconciliation was generated from a different product-data file.')
        ->expectsOutputToContain('FAIL: selective Antar import is not ready. No database writes were performed.')
        ->assertFailed();

    expect(Product::query()->count())->toBe(0);
});

it('fails closed when saved reconciliation pricing is tampered with', function (): void {
    Storage::fake('local');

    writeAntarSelectivePricedFixture(antarSelectivePricedProducts());

    $reconciliation = json_decode(
        Storage::disk('local')->get('scrapers/antar/price-reconciliation-2026-09-01.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $reconciliation['eligible_priced_products'][0]['proposed']['price_gross_amount']++;

    Storage::disk('local')->put(
        'scrapers/antar/price-reconciliation-2026-09-01.json',
        json_encode($reconciliation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );

    $this->artisan('antar:import-priced', [
        '--from' => 'scrapers/antar/product-data.json',
        '--reconciliation' => 'scrapers/antar/price-reconciliation-2026-09-01.json',
        '--execute' => true,
        '--no-images' => true,
        '--no-documents' => true,
    ])
        ->expectsOutputToContain('Saved Antar reconciliation has drifted from the current deterministic reconciliation for eligible_priced_products.')
        ->assertFailed();

    expect(Product::query()->count())->toBe(0);
});

it('allows only explicitly reviewed production baseline rows and a reviewed legacy identity collision', function (): void {
    Storage::fake('local');

    writeAntarSelectivePricedFixture(antarSelectivePricedProducts());

    $legacy = Product::query()->create([
        'name' => 'Legacy AT01001',
        'slug' => 'legacy-at01001',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'antar',
        'external_id' => 'legacy-at01001',
        'external_parent_sku' => 'AT01001',
    ]);
    ProductVariant::query()->create([
        'product_id' => $legacy->id,
        'sku' => 'AT01001',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => null,
        'price_gross_amount' => null,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
        'external_variant_id' => 'antar-legacy-at01001-default',
    ]);

    $outside = Product::query()->create([
        'name' => 'Reviewed outside cohort',
        'slug' => 'reviewed-outside-cohort',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'antar',
        'external_id' => 'reviewed-outside-cohort',
    ]);
    ProductVariant::query()->create([
        'product_id' => $outside->id,
        'sku' => 'OUTSIDE-1',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => null,
        'price_gross_amount' => null,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
        'external_variant_id' => 'antar-reviewed-outside-default',
    ]);

    $sourceRaw = Storage::disk('local')->get('scrapers/antar/product-data.json');
    $reconciliationRaw = Storage::disk('local')->get('scrapers/antar/price-reconciliation-2026-09-01.json');
    $source = json_decode($sourceRaw, true, 512, JSON_THROW_ON_ERROR);
    $reconciliation = json_decode($reconciliationRaw, true, 512, JSON_THROW_ON_ERROR);

    $plan = app(AntarSelectivePricedImportPlan::class)->build(
        $source,
        hash('sha256', $sourceRaw),
        $reconciliation,
        hash('sha256', $reconciliationRaw),
        [
            'legacy_external_id_aliases' => [
                'at01001' => 'legacy-at01001',
            ],
            'allowed_existing_outside_eligible_external_ids' => [
                'legacy-at01001',
                'reviewed-outside-cohort',
            ],
        ],
    );

    $at01001 = collect($plan['eligible_import_rows'])
        ->firstWhere('external_id', 'at01001');

    expect($plan['hard_errors'])->toBe([])
        ->and($plan['ready_for_local_draft_import'])->toBeTrue()
        ->and($plan['summary']['existing_antar_products'])->toBe(2)
        ->and($plan['summary']['existing_antar_products_outside_eligible_cohort'])->toBe(2)
        ->and($at01001['storage_sku'] ?? null)->toBe('AT01001');
});

it('builds a fail-closed production topology report without writes', function (): void {
    Storage::fake('local');

    writeAntarSelectivePricedFixture(antarSelectivePricedProducts());

    $make = function (string $externalId, ?string $sku = null): Product {
        $product = Product::query()->create([
            'name' => $externalId,
            'slug' => $externalId,
            'status' => ProductStatus::DRAFT,
            'external_source' => 'antar',
            'external_id' => $externalId,
            'external_parent_sku' => $sku,
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'status' => ProductVariantStatus::DRAFT,
            'price_net_amount' => null,
            'price_gross_amount' => null,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::VAT_8,
            'stock_status' => StockStatus::IN_STOCK,
            'is_default' => true,
            'external_variant_id' => 'antar-'.$externalId.'-default',
        ]);

        return $product;
    };

    $legacy = $make('legacy-at01001', 'AT01001');
    $make('laweczka-nawannowa-snw-500', 'SNW500');
    $make('torba-na-materac-rehabilitacyjny-trojdzielny-at03107', 'TORBA-AT03107');
    $make('lozko-elektryczne-at52201', 'AT52201');
    $make('chodzik-stalowy-trzykolowy-at51004', 'AT51004');
    $obsolete = $make('obsolete-antar-row', 'OLD-1');

    $sourceRaw = Storage::disk('local')->get('scrapers/antar/product-data.json');
    $reconciliationRaw = Storage::disk('local')->get('scrapers/antar/price-reconciliation-2026-09-01.json');
    $source = json_decode($sourceRaw, true, 512, JSON_THROW_ON_ERROR);
    $reconciliation = json_decode($reconciliationRaw, true, 512, JSON_THROW_ON_ERROR);

    $report = app(AntarProductionPreflight::class)->inspect(
        $source,
        hash('sha256', $sourceRaw),
        $reconciliation,
        hash('sha256', $reconciliationRaw),
        [
            'source_products' => 5,
            'approved_products' => 3,
            'production_products' => 6,
            'production_variants' => 6,
            'production_live_products' => 6,
            'production_live_variants' => 6,
            'production_drafts' => 6,
            'production_variant_drafts' => 6,
            'production_unpriced_variants' => 6,
            'approved_exact_existing' => 2,
            'approved_missing_current_ids' => 1,
            'current_source_missing_from_production' => 1,
            'production_not_in_current_source' => 2,
            'current_non_approved' => 2,
            'current_non_approved_existing' => 2,
            'existing_outside_approved' => 4,
            'cross_source_base_sku_collisions' => 0,
            'duplicate_source_sku_groups' => 0,
            'namespaced_storage_skus' => 0,
            'approved_missing_external_ids' => ['at01001'],
            'current_missing_external_ids' => ['at01001'],
            'production_not_current_external_ids' => ['legacy-at01001', 'obsolete-antar-row'],
            'create_external_ids' => [],
            'legacy_aliases' => [
                'at01001' => [
                    'external_id' => 'legacy-at01001',
                    'product_id' => $legacy->id,
                    'sku' => 'AT01001',
                ],
            ],
            'obsolete_external_ids' => ['obsolete-antar-row'],
            'obsolete_rows' => [
                'obsolete-antar-row' => ['product_id' => $obsolete->id],
            ],
            'rescue_files' => [],
            'minimum_free_mib' => 0,
        ],
    );

    expect($report['errors'])->toBe([])
        ->and($report['ready_for_production_reconciliation'])->toBeTrue()
        ->and($report['database_writes'])->toBeFalse()
        ->and($report['filesystem_writes'])->toBeFalse()
        ->and($report['network_requests'])->toBeFalse();
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeAntarSelectivePricedFixture(array $products): void
{
    $source = ['products' => $products];
    $sourceRaw = json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $sourceSha256 = hash('sha256', $sourceRaw);
    $reconciliation = app(AntarPriceReconciliation::class)->build($source, $sourceSha256);

    Storage::disk('local')->put('scrapers/antar/product-data.json', $sourceRaw);
    Storage::disk('local')->put(
        'scrapers/antar/price-reconciliation-2026-09-01.json',
        json_encode($reconciliation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
}

/** @return list<array<string, mixed>> */
function antarSelectivePricedProducts(): array
{
    return [
        antarSelectivePricedProduct([
            'external_product_id' => 'at01001',
            'slug' => 'at01001',
            'name' => 'Ultralekkie krzesło toaletowe AT01001',
            'sku' => 'AT01001',
            'canonical_url' => 'https://antar.net/produkt/at01001/',
        ]),
        antarSelectivePricedProduct([
            'external_product_id' => 'laweczka-nawannowa-snw-500',
            'slug' => 'laweczka-nawannowa-snw-500',
            'name' => 'Ławeczka nawannowa SNW-500',
            'sku' => 'SNW500',
            'canonical_url' => 'https://antar.net/produkt/laweczka-nawannowa-snw-500/',
        ]),
        antarSelectivePricedProduct([
            'external_product_id' => 'torba-na-materac-rehabilitacyjny-trojdzielny-at03107',
            'slug' => 'torba-na-materac-rehabilitacyjny-trojdzielny-at03107',
            'name' => 'Torba na materac rehabilitacyjny trójdzielny AT03107',
            'sku' => null,
            'canonical_url' => 'https://antar.net/produkt/torba-na-materac-rehabilitacyjny-trojdzielny-at03107/',
            'is_medical_device' => true,
        ]),
        antarSelectivePricedProduct([
            'external_product_id' => 'lozko-elektryczne-at52201',
            'slug' => 'lozko-elektryczne-at52201',
            'name' => 'Łóżko elektryczne AT52201',
            'sku' => 'AT52201',
            'canonical_url' => 'https://antar.net/produkt/lozko-elektryczne-at52201/',
        ]),
        antarSelectivePricedProduct([
            'external_product_id' => 'chodzik-stalowy-trzykolowy-at51004',
            'slug' => 'chodzik-stalowy-trzykolowy-at51004',
            'name' => 'AT51004 Chodzik stalowy, trzykołowy',
            'sku' => 'AT51004',
            'canonical_url' => 'https://antar.net/produkt/chodzik-stalowy-trzykolowy-at51004/',
        ]),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function antarSelectivePricedProduct(array $overrides): array
{
    return array_replace([
        'external_product_id' => 'fixture-product',
        'slug' => 'fixture-product',
        'source_url' => 'https://antar.net/produkt/fixture-product/',
        'canonical_url' => 'https://antar.net/produkt/fixture-product/',
        'name' => 'Fixture Antar product',
        'sku' => null,
        'brand' => 'Antar',
        'categories' => [],
        'source_category_path' => [],
        'attributes' => [],
        'images' => [],
        'documents' => [],
        'is_medical_device' => false,
        'availability' => null,
        'availability_label' => null,
        'price_gross_amount' => null,
    ], $overrides);
}
