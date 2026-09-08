<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Antar\AntarPriceReconciliation;
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
