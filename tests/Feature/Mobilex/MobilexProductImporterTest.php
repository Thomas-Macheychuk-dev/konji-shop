<?php

use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can dry-run a Mobilex product-data file without database writes', function (): void {
    $path = storage_path('app/mobilex/test-product-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [mobilexImporterProductPayload()],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(Attribute::query()->count())->toBe(0);
});

it('imports Mobilex inspected JSON as active products with categories producer metadata and variants from specification', function (): void {
    $path = storage_path('app/mobilex/test-product-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [mobilexImporterProductPayload()],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '30369')
        ->firstOrFail();

    expect($product->name)->toBe('Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR')
        ->and($product->slug)->toBe('wozek-inwalidzki-flipper')
        ->and($product->status->isActive())->toBeTrue()
        ->and($product->seo_title)->toBe('Flipper SEO title')
        ->and($product->description)->not->toContain('themify-builder');

    $topCategory = Category::query()->where('slug', 'wozki-inwalidzkie')->firstOrFail();
    $childCategory = Category::query()->where('slug', 'wozki-podstawowe')->firstOrFail();

    expect($childCategory->parent_id)->toBe($topCategory->id)
        ->and($product->categories()->whereKey($childCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'mobilex')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'material-ramy'))->exists())->toBeFalse();

    expect(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(2);

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', '271940')
        ->firstOrFail();

    expect($variant->sku)->toBe('271940')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(250000))
        ->and($variant->price_gross_amount)->toBe(250000)
        ->and($variant->grossPriceAmount())->toBe(250000)
        ->and($variant->stock_status->isInStock())->toBeTrue()
        ->and($variant->attributeValues()->where('value', 'nr art. 271940')->exists())->toBeTrue();

    expect($product->images()->count())->toBe(0);
});


it('does not import description-derived product attributes as selectable attributes', function (): void {
    $path = storage_path('app/mobilex/test-long-attribute-product-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $longAttributeValue = 'kółka przeciwwywrotne (anti-tip), zagłówek z regulacją 360° i regulacją wysokości, napęd ręczny jednostronny (wózek inwalidzki z napędem ręcznym jednostronnym – wariant pod kod S.16.01.00 NFZ), poduszka siedziska – dostępna w wersji doposażonej (w wersji podstawowej poduszki brak w standardzie).';

    $payload = mobilexImporterProductPayload([
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => 'Mobilex',
                'slug' => 'mobilex',
            ],
            [
                'code' => 'wyposazenie_opcjonalne_za_doplata',
                'label' => 'Wyposażenie opcjonalne za dopłatą',
                'value' => $longAttributeValue,
                'slug' => 'kolka-przeciwwywrotne-anti-tip-zaglowek-z-regulacja-360-i-regulacja-wysokosci-naped-reczny-jednostronny',
            ],
        ],
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-long-attribute-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '30369')
        ->firstOrFail();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'mobilex')->exists())->toBeTrue()
        ->and(Attribute::query()->where('slug', 'wyposazenie-opcjonalne-za-doplata')->exists())->toBeFalse();
});

it('imports products without variant candidates with a single default variant', function (): void {
    $path = storage_path('app/mobilex/test-scholl-product-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $payload = mobilexImporterProductPayload([
        'external_product_id' => '30861',
        'slug' => 'tanisha-med-brown',
        'source_url' => 'https://mobilex.pl/produkty/tanisha-med-brown/',
        'name' => 'TANISHA MED',
        'category' => [
            'top_name' => 'Obuwie Scholl',
            'top_url' => 'https://mobilex.pl/obuwie-scholl/',
            'name' => 'Anatomiczne buty damskie',
            'url' => 'https://mobilex.pl/kategoria-produktu/anatomiczne-buty-damskie/',
        ],
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => 'Scholl',
                'slug' => 'scholl',
            ],
        ],
        'variant_candidates' => [],
        'price_gross_amount' => 39900,
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-scholl-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '30861')
        ->firstOrFail();

    expect($product->name)->toBe('TANISHA MED Scholl')
        ->and($product->categories()->whereHas('parent', fn ($query) => $query->where('slug', 'obuwie-scholl'))->where('slug', 'anatomiczne-buty-damskie')->exists())->toBeTrue()
        ->and($product->variants()->count())->toBe(1)
        ->and($product->variants()->first()->attributeValues()->count())->toBe(0)
        ->and($product->variants()->first()->vat_rate)->toBe(VatRate::VAT_23)
        ->and($product->variants()->first()->price_net_amount)->toBe(VatRate::VAT_23->netFromGross(39900));
});


it('does not append Mobilex to imported product names', function (): void {
    $path = storage_path('app/mobilex/test-mobilex-product-name-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $payload = mobilexImporterProductPayload([
        'external_product_id' => '99902',
        'slug' => 'mobilex-product-with-producer',
        'name' => 'Mobilex product with producer',
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => 'Mobilex',
                'slug' => 'mobilex',
            ],
        ],
        'variant_candidates' => [],
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-mobilex-product-name-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '99902')
        ->firstOrFail();

    expect($product->name)->toBe('Mobilex product with producer');
});

it('removes service heading sections from imported descriptions', function (): void {
    $path = storage_path('app/mobilex/test-service-description-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $payload = mobilexImporterProductPayload([
        'external_product_id' => '99903',
        'slug' => 'description-with-service-section',
        'name' => 'Description with service section',
        'description_html' => '<p>Keep this intro.</p><h2>Serwis wózka inwalidzkiego Flipper – wsparcie Mobilex</h2><p>Remove this service paragraph.</p><h3>FAQ</h3><p>Keep this FAQ paragraph.</p>',
        'variant_candidates' => [],
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-service-description-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '99903')
        ->firstOrFail();

    expect($product->description)->toContain('Keep this intro.')
        ->and($product->description)->not->toContain('Serwis wózka')
        ->and($product->description)->not->toContain('Remove this service paragraph.')
        ->and($product->description)->toContain('Keep this FAQ paragraph.');
});

it('removes paragraphs containing the Mobilex service link from imported descriptions', function (): void {
    $path = storage_path('app/mobilex/test-service-link-description-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $payload = mobilexImporterProductPayload([
        'external_product_id' => '99904',
        'slug' => 'description-with-service-link-paragraph',
        'name' => 'Description with service link paragraph',
        'description_html' => '<p>Keep intro.</p><p>Need help? <a href="https://mobilex.pl/serwis/" target="_blank">serwis sprzętu rehabilitacyjnego</a>.</p><p>Keep final paragraph.</p>',
        'variant_candidates' => [],
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-service-link-description-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '99904')
        ->firstOrFail();

    expect($product->description)->toContain('Keep intro.')
        ->and($product->description)->not->toContain('https://mobilex.pl/serwis/')
        ->and($product->description)->not->toContain('serwis sprzętu rehabilitacyjnego')
        ->and($product->description)->toContain('Keep final paragraph.');
});

it('removes links and iframes from imported descriptions', function (): void {
    $path = storage_path('app/mobilex/test-link-and-iframe-description-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $payload = mobilexImporterProductPayload([
        'external_product_id' => '99905',
        'slug' => 'description-with-links-and-iframe',
        'name' => 'Description with links and iframe',
        'description_html' => '<p>Read <a href="https://example.com/manual" target="_blank">manual text</a> before use.</p><iframe src="https://www.youtube.com/embed/example"><p>Hidden video text</p></iframe><p>Plain link https://example.com/plain should disappear.</p>',
        'variant_candidates' => [],
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-link-and-iframe-description-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '99905')
        ->firstOrFail();

    expect($product->description)->toContain('manual text')
        ->and($product->description)->not->toContain('<a ')
        ->and($product->description)->not->toContain('href=')
        ->and($product->description)->not->toContain('https://example.com/manual')
        ->and($product->description)->not->toContain('https://example.com/plain')
        ->and($product->description)->not->toContain('<iframe')
        ->and($product->description)->not->toContain('Hidden video text');
});

it('does not append the producer twice when the inspected product name already ends with it', function (): void {
    $path = storage_path('app/mobilex/test-product-name-suffix-data.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $payload = mobilexImporterProductPayload([
        'external_product_id' => '99901',
        'slug' => 'already-branded-scholl',
        'name' => 'Already Branded Scholl',
        'brand' => [
            'name' => 'Scholl',
            'url' => null,
            'logo_url' => null,
        ],
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => 'Scholl',
                'slug' => 'scholl',
            ],
        ],
        'variant_candidates' => [],
    ]);

    file_put_contents($path, json_encode([
        'source' => 'mobilex',
        'products' => [$payload],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mobilex:import', [
        '--data' => 'mobilex/test-product-name-suffix-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'mobilex')
        ->where('external_id', '99901')
        ->firstOrFail();

    expect($product->name)->toBe('Already Branded Scholl');
});

function mobilexImporterProductPayload(array $overrides = []): array
{
    return array_replace([
        'source' => 'mobilex',
        'external_product_id' => '30369',
        'source_url' => 'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
        'canonical_url' => 'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
        'slug' => 'wozek-inwalidzki-flipper',
        'name' => 'Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR',
        'brand' => [
            'name' => 'Mobilex',
            'url' => 'https://mobilex.pl/producent/mobilex/',
            'logo_url' => null,
        ],
        'category' => [
            'top_name' => 'Wózki inwalidzkie',
            'top_url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
            'name' => 'wózki podstawowe',
            'url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        ],
        'seo_title' => 'Flipper SEO title',
        'seo_description' => 'Flipper SEO description',
        'short_description' => 'Short Flipper description',
        'description_html' => '<p>Opis Flippera.</p> <!-- wp:themify-builder/canvas /-->',
        'images' => [
            [
                'url' => 'https://mobilex.pl/wp-content/uploads/2025/07/Flipper.jpg',
                'alt' => 'Flipper',
                'source' => 'gallery',
            ],
        ],
        'documents' => [],
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'value' => 'Mobilex',
                'slug' => 'mobilex',
            ],
            [
                'code' => 'material_ramy',
                'label' => 'Materiał ramy',
                'value' => 'aluminiowa',
                'slug' => 'aluminiowa',
            ],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => '271940',
                'sku' => '271940',
                'label' => 'nr art. 271940',
                'attributes' => [
                    [
                        'label' => 'Szerokość siedziska',
                        'value' => '40 cm',
                    ],
                ],
                'price_gross_amount' => 250000,
                'regular_price_gross_amount' => 250000,
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => '271944',
                'sku' => '271944',
                'label' => 'nr art. 271944',
                'attributes' => [
                    [
                        'label' => 'Szerokość siedziska',
                        'value' => '44 cm',
                    ],
                ],
                'price_gross_amount' => 280000,
                'regular_price_gross_amount' => 280000,
                'currency' => 'PLN',
            ],
        ],
        'warnings' => [],
    ], $overrides);
}
