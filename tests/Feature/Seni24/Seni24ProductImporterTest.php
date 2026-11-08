<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductBrandAttribute;
use App\Services\Seni24\Seni24ProductImporter;
use App\Support\Seo\ProductIdentifiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function seni24PricedFixture(): array
{
    return [
        'source' => 'seni24',
        'source_url' => 'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
        'canonical_url' => 'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
        'external_product_id' => '338',
        'slug' => 'kubek-pojnik-z-ustnikiem-200-ml',
        'name' => 'Kubek pojnik z ustnikiem 200 ml',
        'price_gross_amount' => 9.39,
        'currency' => 'PLN',
        'vat_rate' => 8,
        'availability' => 'in_stock',
        'availability_label' => 'Produkt dostępny',
        'is_on_order' => false,
        'shipping_time' => '24-48h',
        'unit' => 'szt.',
        'catalogue_number' => 'NN-SRW-AHK1-001',
        'ean' => '5905279578104',
        'source_category_path' => ['Pomoce codzienne', 'Akcesoria kuchenne'],
        'categories' => ['Pomoce codzienne', 'Akcesoria kuchenne'],
        'category' => 'Akcesoria kuchenne',
        'description_html' => '<p>Opis produktu.</p>',
        'description_plain' => 'Opis produktu.',
        'images' => [],
        'attributes' => [
            ['label' => 'Indeks', 'value' => 'NN-SRW-AHK1-001'],
            ['label' => 'ean13', 'value' => '5905279578104'],
        ],
        'variant_candidates' => [[
            'external_variant_id' => '16501',
            'label' => 'Pojemność: 200 ml',
            'attributes' => [['label' => 'Pojemność', 'value' => '200 ml']],
            'price_gross_amount' => 9.39,
            'currency' => 'PLN',
            'vat_rate' => 8,
        ]],
        'variants_unresolved' => false,
        'is_medical_device' => true,
        'medical_device_class' => null,
        'raw_context' => [
            'listing_roots' => ['https://www.seni24.pl/strona-glowna/'],
        ],
    ];
}

it('imports a resolved Seni24 product idempotently as draft using source VAT', function (): void {
    $importer = app(Seni24ProductImporter::class);
    $fixture = seni24PricedFixture();

    $first = $importer->import($fixture, null, false);
    $second = $importer->import($fixture, null, false);

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1);

    $product = $second['product']->fresh(['categories.parent', 'variants.attributeValues.attribute']);

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_id)->toBe('338')
        ->and($product->external_parent_sku)->toBe('S24-338')
        ->and($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($product->variants->first()->vat_rate)->toBe(VatRate::VAT_8)
        ->and($product->variants->first()->price_gross_amount)->toBe(939)
        ->and($product->variants->first()->sku)->toBe('S24-338-16501');

    expect(Category::query()->whereNull('parent_id')->pluck('name')->all())
        ->toContain('Seni24');

    expect($first['warnings'])->toBe([])
        ->and($second['warnings'])->toBe([]);
});

it('rejects Seni24 products with unresolved source variants', function (): void {
    $fixture = seni24PricedFixture();
    $fixture['variants_unresolved'] = true;

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'has unresolved source variants',
    );

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(0);
});

it('requires explicit Seni24 VAT when source VAT is absent', function (): void {
    $fixture = seni24PricedFixture();
    unset($fixture['vat_rate']);

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'Seni24 VAT rate is not explicit',
    );
});

it('imports each resolved Seni24 variant with its own stock state', function (): void {
    $fixture = seni24PricedFixture();

    $fixture['variant_candidates'] = [
        [
            'external_variant_id' => '18630',
            'label' => 'Rozmiar: 43-46, Kolor: Czarny',
            'attributes' => [
                ['label' => 'Rozmiar', 'value' => '43-46'],
                ['label' => 'Kolor', 'value' => 'Czarny'],
            ],
            'price_gross_amount' => 23.46,
            'currency' => 'PLN',
            'vat_rate' => 8,
            'availability' => 'out_of_stock',
            'availability_label' => 'Produkt niedostępny',
        ],
        [
            'external_variant_id' => '18632',
            'label' => 'Rozmiar: 43-46, Kolor: Ciemny szary',
            'attributes' => [
                ['label' => 'Rozmiar', 'value' => '43-46'],
                ['label' => 'Kolor', 'value' => 'Ciemny szary'],
            ],
            'price_gross_amount' => 23.46,
            'currency' => 'PLN',
            'vat_rate' => 8,
            'availability' => 'in_stock',
            'availability_label' => 'Produkt dostępny',
        ],
    ];

    $fixture['availability'] = 'out_of_stock';
    $fixture['availability_label'] = 'Produkt niedostępny';
    $fixture['variants_unresolved'] = false;

    app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    );

    $variants = ProductVariant::query()
        ->whereIn('external_variant_id', [
            'seni24-338-18630',
            'seni24-338-18632',
        ])
        ->get()
        ->keyBy('external_variant_id');

    expect($variants)->toHaveCount(2)
        ->and($variants['seni24-338-18630']->stock_status)
        ->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variants['seni24-338-18632']->stock_status)
        ->toBe(StockStatus::IN_STOCK)
        ->and($variants['seni24-338-18630']->is_default)
        ->toBeFalse()
        ->and($variants['seni24-338-18632']->is_default)
        ->toBeTrue();
});

it('canonicalizes the Seni24 size-table alias without duplicating variant size values', function (): void {
    $fixture = seni24PricedFixture();

    $fixture['variant_candidates'][0]['attributes'] = [
        ['label' => 'Rozmiar', 'value' => 'S'],
        [
            'label' => 'Rozmiar (Tabela rozmiarów)',
            'value' => 'S',
        ],
        [
            'label' => 'Ilość sztuk',
            'value' => '10 szt.',
        ],
    ];

    app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    );

    $variant = ProductVariant::query()
        ->with('attributeValues.attribute')
        ->firstOrFail();

    $attributes = $variant->attributeValues
        ->map(
            fn ($value): string => $value->attribute->name.'='.$value->value
        )
        ->sort()
        ->values()
        ->all();

    expect($attributes)
        ->toContain('Rozmiar=S')
        ->toContain('Ilość sztuk=10 szt.')
        ->not->toContain(
            'Rozmiar (Tabela rozmiarów)=S',
        )
        ->toHaveCount(2);
});

it('keeps a brand assigned outside the Seni24 import when re-importing', function (): void {
    $importer = app(Seni24ProductImporter::class);
    $product = $importer->import(seni24PricedFixture(), null, false)['product'];
    app(ProductBrandAttribute::class)->attach($product, 'Ziaja');

    $importer->import(seni24PricedFixture(), null, false);

    expect($product->fresh()->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->pluck('value')->all())
        ->toBe(['Ziaja']);
});

it('stores the scraped Seni24 brand as the manufacturer', function (): void {
    $product = app(Seni24ProductImporter::class)
        ->import([...seni24PricedFixture(), 'brand' => 'Ziaja'], null, false)['product'];

    expect(ProductIdentifiers::brand($product->fresh()))->toBe('Ziaja');
});

it('backfills Seni24 brands from scraped data onto imported products without one', function (): void {
    Storage::fake('local');
    $importer = app(Seni24ProductImporter::class);
    $unbranded = $importer->import(seni24PricedFixture(), null, false)['product'];
    $branded = $importer->import([...seni24PricedFixture(), 'external_product_id' => '339', 'slug' => 'drugi', 'brand' => 'Seni'], null, false)['product'];

    Storage::disk('local')->put('scrapers/seni24/products-full-v1.json', json_encode(['products' => [
        [...seni24PricedFixture(), 'brand' => 'Ziaja'],
        [...seni24PricedFixture(), 'external_product_id' => '339', 'slug' => 'drugi', 'brand' => 'Inna'],
        [...seni24PricedFixture(), 'external_product_id' => '999', 'slug' => 'brak', 'brand' => 'Eva'],
    ]]));

    $this->artisan('seni24:backfill-brands')
        ->expectsOutputToContain('NOT_IMPORTED=1')
        ->expectsOutputToContain('ALREADY_BRANDED=1')
        ->expectsOutputToContain('TO_ATTACH=1')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(ProductIdentifiers::brand($unbranded->fresh()))->toBeNull();

    $this->artisan('seni24:backfill-brands', ['--write' => true])
        ->expectsOutputToContain('ATTACHED=1')
        ->expectsOutputToContain('SENI24_BRANDS_WRITE=PASS')
        ->assertSuccessful();

    expect(ProductIdentifiers::brand($unbranded->fresh()))->toBe('Ziaja')
        ->and(ProductIdentifiers::brand($branded->fresh()))->toBe('Seni');
});

it('falls back to the cleaned Seni24 Producent attribute when the data has no brand', function (array $attributes, ?string $expected): void {
    expect(app(Seni24ProductImporter::class)->brandName([...seni24PricedFixture(), 'brand' => null, 'attributes' => $attributes]))
        ->toBe($expected);
})->with([
    [[['label' => 'Producent', 'value' => 'TZMO S.A. (marka Seni) Numer jednostki notyfikowanej N/D']], 'Seni'],
    [[['label' => 'Producent', 'value' => 'TZMO S.A. Numer jednostki notyfikowanej 2274']], 'TZMO'],
    [[['label' => 'Producent', 'value' => 'JJW Sp.J.']], 'JJW'],
    [[['label' => 'Indeks', 'value' => 'NN-1']], null],
]);

it('backfills a Seni24 brand from the Producent attribute of older product data', function (): void {
    Storage::fake('local');
    $product = app(Seni24ProductImporter::class)->import(seni24PricedFixture(), null, false)['product'];

    Storage::disk('local')->put('scrapers/seni24/old.json', json_encode(['products' => [
        [...seni24PricedFixture(), 'brand' => '', 'attributes' => [['label' => 'Producent', 'value' => 'ZARYS Numer jednostki notyfikowanej 0197']]],
    ]]));

    $this->artisan('seni24:backfill-brands', ['--data' => 'scrapers/seni24/old.json', '--write' => true])
        ->expectsOutputToContain('ATTACHED=1')
        ->assertSuccessful();

    expect(ProductIdentifiers::brand($product->fresh()))->toBe('ZARYS');
});

it('takes the Seni24 brand from the producer address when nothing else names the maker', function (string $address, ?string $expected): void {
    expect(app(Seni24ProductImporter::class)->brandName([
        ...seni24PricedFixture(),
        'brand' => null,
        'attributes' => [['label' => 'Adres producenta', 'value' => $address]],
    ]))->toBe($expected);
})->with([
    ['POLLENA-EWA S.A., ul. Zachodnia 25, 97-425 Zelów, Polska', 'POLLENA-EWA'],
    ['ZIAJA LTD ZAKŁAD PRODUKCJI LEKÓW SP. Z O.O. UL. JESIENNA 9 80-298 GDAŃSK', 'ZIAJA'],
    ['POLLENA Kosmetyki i Mydła Naturalne Sp. z o.o. ul. Powstańców Wlkp. 16 63-500 Ostrzeszów', 'POLLENA'],
    ['SCHMEES POLSKA Sp z o.o. ul. Przemysłowa 1, 66-500 Strzelce Krajeńskie', 'SCHMEES POLSKA'],
    ['Przedsiębiorstwo Farmaceutyczne ziołolek sp. z o.o. ul. Starołęcka 189 61-341 Poznań', 'Ziołolek'],
    ['Terumo Europe N.V. Interleuvenlaan 40,3001 Leuven,Belgium', 'Terumo Europe'],
    ['CHIRANA T. Injecta, a.s. Nám. Dr. Alberta Schweitzera 194, 916 01 Stará Turá, Slovakia', 'CHIRANA T. Injecta'],
    ['BEROMED GmbH Hospital Products Kronenstr. 19, 10117 Berlin', 'BEROMED'],
    ['Perrigo Omega Pharma Int. Venecoweg 26, 9810 Nazareth, Belgium', 'Perrigo Omega Pharma Int'],
    ['Toruńskie Zakłady Materiałów Opatrunkowych S.A, ul. Żółkiewskiego 20/26, 87-100 Toruń, Polska', 'TZMO'],
    ['ZARYS International Group sp. z o.o. sp.k., ul. Pod Borem 18, 41-808 Zabrze', 'ZARYS'],
    ['MEDILAB Firma Wytwórczo-Usługowa Sp. z o.o ul. Niedźwiedzia 60 15-531 Białystok', 'MEDILAB'],
    ['Convatec Polska Sp. z o.o Rondo Daszyńskiego 1, 00-843 Warszawa', 'ConvaTec'],
    ['-', null],
]);
