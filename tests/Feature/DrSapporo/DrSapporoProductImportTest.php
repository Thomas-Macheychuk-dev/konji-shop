<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can dry-run Dr Sapporo product data without database writes', function (): void {
    writeDrSapporoImportFixture('scrapers/drsapporo/import-test.json', [drSapporoImportPayload()]);

    $this->artisan('drsapporo:import', [
        '--from' => 'scrapers/drsapporo/import-test.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories referenced: 1')
        ->expectsOutputToContain('Variants to create/update: 2')
        ->expectsOutputToContain('Product images discovered: 2')
        ->expectsOutputToContain('Products without explicit VAT after override: 0')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('fails closed when a Dr Sapporo product has no reviewed VAT classification', function (): void {
    writeDrSapporoImportFixture('scrapers/drsapporo/import-test.json', [
        drSapporoImportPayload([
            'external_product_id' => 'unreviewed-drsapporo-product',
            'slug' => 'unreviewed-drsapporo-product',
            'name' => 'Unreviewed Dr Sapporo product',
        ]),
    ]);

    $this->artisan('drsapporo:import', [
        '--from' => 'scrapers/drsapporo/import-test.json',
        '--no-images' => true,
        '--show-failures' => true,
    ])
        ->expectsOutputToContain('Dr Sapporo VAT rate is not explicit.')
        ->assertFailed();

    expect(Product::query()->where('external_source', 'drsapporo')->count())->toBe(0);
});

it('imports Dr Sapporo products as drafts with deterministic internal SKUs and explicit VAT', function (): void {
    writeDrSapporoImportFixture('scrapers/drsapporo/import-test.json', [drSapporoImportPayload()]);

    $this->artisan('drsapporo:import', [
        '--from' => 'scrapers/drsapporo/import-test.json',
        '--no-images' => true,
        '--vat-rate' => '23',
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'drsapporo')
        ->where('external_id', 'poduszka-ortopedyczna-swing')
        ->firstOrFail();

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('DRS-PODUSZKA-ORTOPEDYCZNA-SWING')
        ->and($product->name)->toBe('Swing Poduszka ortopedyczna')
        ->and($product->description)->toContain('Parametry produktu')
        ->and($product->description)->toContain('Dostępne warianty')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->categories()->count())->toBe(1)
        ->and($product->categories()->firstOrFail()->name)->toBe('Poduszki ortopedyczne Dr Sapporo');

    expect(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(2);

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'drsapporo-poduszka-ortopedyczna-swing-m')
        ->firstOrFail();

    expect($variant->sku)->toBe('DRS-PODUSZKA-ORTOPEDYCZNA-SWING-M')
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variant->price_gross_amount)->toBe(29900)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_23->netFromGross(29900))
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 'm')->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'dr-sapporo')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->where('slug', 'tak')->exists())->toBeTrue()
        ->and($product->images()->count())->toBe(0);
});

it('applies per-product Dr Sapporo VAT rates from a reviewed VAT map', function (): void {
    writeDrSapporoImportFixture('scrapers/drsapporo/import-vat-map.json', [
        drSapporoImportPayload(),
        drSapporoImportPayload([
            'external_product_id' => 'poszewka-na-poduszke-rock',
            'slug' => 'poszewka-na-poduszke-rock',
            'name' => 'Rock Poszewka na poduszkę ortopedyczną',
            'category' => 'Poszewki na poduszki Dr Sapporo',
            'categories' => ['Poszewki na poduszki Dr Sapporo'],
            'source_category_name' => 'Poszewki na poduszki Dr Sapporo',
            'is_medical_device' => false,
            'price_gross_amount' => 79.0,
            'variant_candidates' => [],
        ]),
    ]);

    $vatMapPath = Storage::disk('local')->path('scrapers/drsapporo/vat-map-test.json');

    if (! is_dir(dirname($vatMapPath))) {
        mkdir(dirname($vatMapPath), 0755, true);
    }

    file_put_contents($vatMapPath, json_encode([
        'poduszka-ortopedyczna-swing' => 8,
        'poszewka-na-poduszke-rock' => 23,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $this->artisan('drsapporo:import', [
        '--from' => 'scrapers/drsapporo/import-vat-map.json',
        '--vat-map' => 'scrapers/drsapporo/vat-map-test.json',
        '--no-images' => true,
    ])
        ->expectsOutputToContain('VAT map entries: 42')
        ->assertSuccessful();

    $swing = Product::query()
        ->where('external_source', 'drsapporo')
        ->where('external_id', 'poduszka-ortopedyczna-swing')
        ->firstOrFail();

    $cover = Product::query()
        ->where('external_source', 'drsapporo')
        ->where('external_id', 'poszewka-na-poduszke-rock')
        ->firstOrFail();

    expect($swing->variants()->firstOrFail()->vat_rate)->toBe(VatRate::VAT_8)
        ->and($cover->variants()->firstOrFail()->vat_rate)->toBe(VatRate::VAT_23);
});

it('updates existing Dr Sapporo products instead of duplicating them', function (): void {
    writeDrSapporoImportFixture('scrapers/drsapporo/import-test.json', [drSapporoImportPayload()]);

    $this->artisan('drsapporo:import', [
        '--from' => 'scrapers/drsapporo/import-test.json',
        '--no-images' => true,
        '--vat-rate' => '23',
    ])->assertSuccessful();

    writeDrSapporoImportFixture('scrapers/drsapporo/import-test.json', [
        drSapporoImportPayload([
            'name' => 'Swing Poduszka ortopedyczna UPDATED',
            'price_gross_amount' => 309.0,
            'variant_candidates' => [
                [
                    'external_variant_id' => 'm',
                    'label' => 'Rozmiar: M',
                    'attributes' => [
                        ['label' => 'Rozmiar', 'value' => 'M'],
                    ],
                    'price_gross_amount' => 309.0,
                    'currency' => 'PLN',
                ],
            ],
        ]),
    ]);

    $this->artisan('drsapporo:import', [
        '--from' => 'scrapers/drsapporo/import-test.json',
        '--no-images' => true,
        '--vat-rate' => '23',
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'drsapporo')
        ->where('external_id', 'poduszka-ortopedyczna-swing')
        ->firstOrFail();

    expect(Product::query()->where('external_source', 'drsapporo')->where('external_id', 'poduszka-ortopedyczna-swing')->count())->toBe(1)
        ->and($product->name)->toBe('Swing Poduszka ortopedyczna UPDATED')
        ->and($product->variants()->count())->toBe(1)
        ->and($product->variants()->firstOrFail()->price_gross_amount)->toBe(30900);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeDrSapporoImportFixture(string $relativePath, array $products): void
{
    $path = Storage::disk('local')->path($relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'drsapporo',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function drSapporoImportPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'drsapporo',
        'source_url' => 'https://drsapporo.com/poduszka-ortopedyczna-swing',
        'canonical_url' => 'https://drsapporo.com/poduszka-ortopedyczna-swing',
        'external_product_id' => 'poduszka-ortopedyczna-swing',
        'slug' => 'poduszka-ortopedyczna-swing',
        'name' => 'Swing Poduszka ortopedyczna',
        'brand' => [
            'name' => 'Dr Sapporo',
            'slug' => 'dr-sapporo',
        ],
        'category' => 'Poduszki ortopedyczne Dr Sapporo',
        'categories' => ['Poduszki ortopedyczne Dr Sapporo'],
        'seo_description' => 'Poduszka ortopedyczna Swing od Dr Sapporo.',
        'description_html' => '<p>Poduszka Swing zapewnia ergonomiczne podparcie.</p><script>alert("x")</script>',
        'price_gross_amount' => 299.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'shipping_time' => '1 dzień roboczy',
        'sku' => null,
        'ean' => null,
        'is_medical_device' => true,
        'images' => [
            ['url' => 'https://drsapporo.com/photos/product/1/a.webp', 'alt' => 'Swing'],
            ['url' => 'https://drsapporo.com/photos/product/1/b.webp', 'alt' => 'Swing bok'],
        ],
        'attributes' => [
            ['code' => 'szerokosc', 'label' => 'Szerokość', 'value' => '55 centymetry', 'slug' => '55-centymetry'],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => 'm',
                'label' => 'Rozmiar: M',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'M'],
                ],
                'price_gross_amount' => 299.0,
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => 'l',
                'label' => 'Rozmiar: L',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'L'],
                ],
                'price_gross_amount' => 299.0,
                'currency' => 'PLN',
            ],
        ],
        'warnings' => [],
        'failed_urls' => [],
        'source_category_name' => 'Poduszki ortopedyczne Dr Sapporo',
        'source_product_list_name' => 'Swing',
    ];

    foreach ($overrides as $key => $value) {
        $payload[$key] = $value;
    }

    return $payload;
}
