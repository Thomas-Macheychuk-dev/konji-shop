<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can dry-run a RelaxSan product-data file without database writes', function (): void {
    writeRelaxSanImportFixture('scrapers/relaxsan/test-product-data.json', [relaxsanImportProductPayload()]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 3')
        ->expectsOutputToContain('Variants to create/update: 2')
        ->expectsOutputToContain('Product images discovered: 2')
        ->expectsOutputToContain('Medical device products: 1')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports RelaxSan product-data as draft products with category hierarchy attributes variants and safe descriptions', function (): void {
    writeRelaxSanImportFixture('scrapers/relaxsan/test-product-data.json', [relaxsanImportProductPayload()]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'relaxsan')
        ->where('external_id', '94')
        ->firstOrFail();

    expect($product->name)->toBe('Podkolanówki testowe RelaxSan')
        ->and($product->slug)->toBe('podkolanowki-testowe-relaxsan')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('A-05')
        ->and($product->seo_title)->toBe('RelaxSan | Podkolanówki testowe')
        ->and($product->seo_description)->toBe('Podkolanówki z kontrolowanym uciskiem')
        ->and($product->short_description)->toBe('<p>Podkolanówki z kontrolowanym uciskiem</p>')
        ->and($product->description)->toContain('Opis produktu')
        ->and($product->description)->toContain('Parametry produktu')
        ->and($product->description)->toContain('Dostępne warianty')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->toContain('To jest wyrób medyczny')
        ->and($product->description)->toContain('https://www.youtube-nocookie.com/embed/v4MjZ-gOWLc')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('evil.example');

    $topCategory = Category::query()->where('slug', 'przeciwzylakowe')->firstOrFail();
    $middleCategory = Category::query()->where('slug', 'podkolanowki-uciskowe')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'podkolanowki-uciskowe-profilaktyczne')->firstOrFail();

    expect($middleCategory->parent_id)->toBe($topCategory->id)
        ->and($leafCategory->parent_id)->toBe($middleCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($topCategory->id)->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'relaxsan')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->where('slug', 'tak')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->exists())->toBeFalse();

    expect(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(2);

    $smallVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'relaxsan-94-41')
        ->firstOrFail();

    expect($smallVariant->sku)->toBe('A-05-S')
        ->and($smallVariant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($smallVariant->is_default)->toBeTrue()
        ->and($smallVariant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($smallVariant->currency)->toBe(Currency::PLN)
        ->and($smallVariant->price_gross_amount)->toBe(7800)
        ->and($smallVariant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(7800))
        ->and($smallVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($smallVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 's')->exists())->toBeTrue();

    $mediumVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'relaxsan-94-42')
        ->firstOrFail();

    expect($mediumVariant->sku)->toBe('A-05-M')
        ->and($mediumVariant->is_default)->toBeFalse()
        ->and($mediumVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 'm')->exists())->toBeTrue();

    expect($product->images()->count())->toBe(0);
});

it('downloads and rewrites RelaxSan embedded description images during image imports', function (): void {
    Storage::fake('public');

    Http::fake([
        'relaxsansklep.pl/*' => Http::response('fake-image-contents', 200, ['Content-Type' => 'image/png']),
    ]);

    writeRelaxSanImportFixture('scrapers/relaxsan/test-description-image-product-data.json', [
        relaxsanImportProductPayload([
            'images' => [],
            'description_html' => '<h2>Opis produktu</h2><p><img src="/userdata/public/assets//5704-pack3d.jpg" srcset="/userdata/public/assets//5704-pack3d.jpg 1x" data-src="/userdata/public/assets//lazy.jpg" alt="Tabela rozmiarów" width="280" height="374"></p>',
        ]),
    ]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-description-image-product-data.json',
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'relaxsan')
        ->where('external_id', '94')
        ->firstOrFail();

    $descriptionFiles = Storage::disk('public')->allFiles('products/relaxsan/94/description');

    expect($descriptionFiles)->toHaveCount(1)
        ->and($product->description)->toContain('/storage/products/relaxsan/94/description/')
        ->and($product->description)->toContain('alt="Tabela rozmiarów"')
        ->and($product->description)->not->toContain('/userdata/public/assets')
        ->and($product->description)->not->toContain('srcset=')
        ->and($product->description)->not->toContain('data-src=');
});

it('imports RelaxSan products without variant candidates with a single default variant', function (): void {
    writeRelaxSanImportFixture('scrapers/relaxsan/test-single-variant-product-data.json', [
        relaxsanImportProductPayload([
            'external_product_id' => '670',
            'slug' => 'cisnieniomierz-nadgarstkowy',
            'name' => 'Ciśnieniomierz Nadgarstkowy Elektroniczny',
            'brand' => ['name' => 'RelaxSan', 'slug' => 'relaxsan'],
            'sku' => 'CI-01',
            'price_gross_amount' => 89.99,
            'is_medical_device' => false,
            'variant_candidates' => [],
        ]),
    ]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-single-variant-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'relaxsan')
        ->where('external_id', '670')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->variants()->count())->toBe(1)
        ->and($variant->external_variant_id)->toBe('relaxsan-670-default')
        ->and($variant->sku)->toBe('CI-01')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variant->price_gross_amount)->toBe(8999)
        ->and($variant->attributeValues()->count())->toBe(0);
});

it('can import RelaxSan products as active products', function (): void {
    writeRelaxSanImportFixture('scrapers/relaxsan/test-active-product-data.json', [relaxsanImportProductPayload()]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-active-product-data.json',
        '--status' => 'active',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'relaxsan')
        ->where('external_id', '94')
        ->firstOrFail();

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($product->variants()->where('status', ProductVariantStatus::ACTIVE)->count())->toBe(2);
});

it('updates existing RelaxSan products by external ID instead of duplicating them', function (): void {
    writeRelaxSanImportFixture('scrapers/relaxsan/test-update-product-data.json', [relaxsanImportProductPayload()]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    writeRelaxSanImportFixture('scrapers/relaxsan/test-update-product-data.json', [
        relaxsanImportProductPayload([
            'name' => 'Updated RelaxSan product',
            'price_gross_amount' => 99.99,
            'availability' => 'out_of_stock',
            'availability_label' => 'brak towaru',
            'variant_candidates' => [
                [
                    'external_variant_id' => '41',
                    'sku' => null,
                    'label' => 'S',
                    'attributes' => [
                        ['label' => 'Rozmiar:', 'value' => 'S'],
                    ],
                    'price_gross_amount' => 99.99,
                    'currency' => 'PLN',
                ],
            ],
        ]),
    ]);

    $this->artisan('relaxsan:import', [
        '--from' => 'scrapers/relaxsan/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'relaxsan')
        ->where('external_id', '94')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'relaxsan')->where('external_id', '94')->count())->toBe(1)
        ->and($product->name)->toBe('Updated RelaxSan product')
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->price_gross_amount)->toBe(9999)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(9999))
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeRelaxSanImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'relaxsan',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function relaxsanImportProductPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'relaxsan',
        'source_url' => 'https://relaxsansklep.pl/pl/p/Podkolanowki-testowe-RelaxSan/94',
        'canonical_url' => 'https://relaxsansklep.pl/pl/p/Podkolanowki-testowe-RelaxSan/94',
        'external_product_id' => '94',
        'slug' => 'podkolanowki-testowe-relaxsan',
        'name' => 'Podkolanówki testowe RelaxSan',
        'brand' => [
            'name' => 'RelaxSan',
            'slug' => 'relaxsan',
        ],
        'category' => 'Podkolanówki uciskowe profilaktyczne',
        'categories' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
        'seo_title' => 'RelaxSan | Podkolanówki testowe',
        'seo_description' => 'Podkolanówki z kontrolowanym uciskiem',
        'short_description' => 'Podkolanówki z kontrolowanym uciskiem',
        'description' => 'Opis produktu',
        'description_html' => '<h2>Opis produktu</h2><p>Podkolanówki uciskowe do codziennej profilaktyki.</p><script>alert("x")</script><iframe src="//www.youtube.com/embed/v4MjZ-gOWLc"></iframe><iframe src="https://evil.example/embed/bad"></iframe>',
        'price_gross_amount' => 78.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'duża ilość Wysyłka w: 24 godziny',
        'shipping_time' => '24 godziny',
        'stock_quantity' => null,
        'sku' => 'A-05',
        'ean' => null,
        'attributes' => [
            [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => 'obwód talii zaraz pod pępkiem S 70 - 85 cm M 85 - 100 cm L 100 - 115 cm XL 115 - 130 cm XXL 130 - 145 cm',
                'slug' => 'obwod-talii-zaraz-pod-pepkiem-s-70-85-cm-m-85-100-cm-l-100-115-cm-xl-115-130-cm-xxl-130-145-cm',
            ],
        ],
        'images' => [
            [
                'url' => 'https://relaxsansklep.pl/environment/cache/images/productGfx_2516_500_500/08.jpg',
                'alt' => 'Podkolanówki testowe RelaxSan',
            ],
            [
                'url' => 'https://relaxsansklep.pl/environment/cache/images/productGfx_2518_500_500/10.jpg',
                'alt' => '10.jpg',
            ],
        ],
        'tabs' => [
            'opis' => '<p>Opis produktu</p>',
            'parametry' => [
                [
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'S 70 - 85 cm M 85 - 100 cm',
                    'slug' => 's-70-85-cm-m-85-100-cm',
                ],
            ],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => '41',
                'sku' => null,
                'label' => 'S',
                'attributes' => [
                    ['label' => 'Rozmiar:', 'value' => 'S'],
                ],
                'price_gross_amount' => 78.0,
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => '42',
                'sku' => null,
                'label' => 'M',
                'attributes' => [
                    ['label' => 'Rozmiar:', 'value' => 'M'],
                ],
                'price_gross_amount' => 78.0,
                'currency' => 'PLN',
            ],
        ],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
        'source_category_name' => 'Podkolanówki uciskowe profilaktyczne',
        'source_category_url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe',
        'source_top_category_name' => 'Przeciwżylakowe',
        'source_top_category_url' => 'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
        'source_category_path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
        'source_product_list_name' => 'Podkolanówki testowe RelaxSan',
    ];

    return relaxsanImportPayloadMerge($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function relaxsanImportPayloadMerge(array $payload, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && array_is_list($value)) {
            $payload[$key] = $value;

            continue;
        }

        if (is_array($value) && isset($payload[$key]) && is_array($payload[$key]) && ! array_is_list($payload[$key])) {
            $payload[$key] = relaxsanImportPayloadMerge($payload[$key], $value);

            continue;
        }

        $payload[$key] = $value;
    }

    return $payload;
}
