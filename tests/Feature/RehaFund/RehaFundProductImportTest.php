<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can dry-run a RehaFund product-data file without database writes', function (): void {
    writeRehaFundImportFixture('scrapers/rehafund/test-product-data.json', [rehafundImportProductPayload()]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 2')
        ->expectsOutputToContain('Variants to create/update: 2')
        ->expectsOutputToContain('Product images discovered: 2')
        ->expectsOutputToContain('Medical device products: 1')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports RehaFund product-data as draft products with safe descriptions null prices categories attributes and variants', function (): void {
    writeRehaFundImportFixture('scrapers/rehafund/test-product-data.json', [rehafundImportProductPayload()]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'rehafund')
        ->where('external_id', '1002')
        ->firstOrFail();

    expect($product->name)->toBe('Siedzisko do podnośnika kąpielowe')
        ->and($product->slug)->toBe('siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('RF-1012-M')
        ->and($product->seo_title)->toBe('Siedzisko do podnośnika kąpielowe - RehaFund')
        ->and($product->seo_description)->toBe('Siedzisko do podnośnika kąpielowe do rehabilitacji.')
        ->and($product->short_description)->toBe('<p>Siedzisko do podnośnika kąpielowe do rehabilitacji.</p>')
        ->and($product->description)->toContain('Opis towaru')
        ->and($product->description)->toContain('Parametry produktu')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->toContain('To jest wyrób medyczny')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('href=')
        ->and($product->description)->not->toContain('https://sklep.rehafund.pl/produkty')
        ->and($product->description)->not->toContain('<th>Dostępność</th>')
        ->and($product->description)->not->toContain('<th>Wysyłka</th>')
        ->and($product->description)->not->toContain('Od ręki');

    $rootCategory = Category::query()->where('slug', 'rehabilitacja')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'podnosniki')->firstOrFail();

    expect($leafCategory->parent_id)->toBe($rootCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($rootCategory->id)->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'symbol'))->where('value', 'RF-1012/M')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->where('slug', 'tak')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'dostepnosc'))->exists())->toBeFalse()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wysylka'))->exists())->toBeFalse();

    expect(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(2);

    $mediumVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'rehafund-1002-1')
        ->firstOrFail();

    expect((bool) preg_match('/^RF-1012-M(?:-\\d+)?$/', $mediumVariant->sku))->toBeTrue()
        ->and($mediumVariant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($mediumVariant->is_default)->toBeTrue()
        ->and($mediumVariant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($mediumVariant->currency)->toBe(Currency::PLN)
        ->and($mediumVariant->price_gross_amount)->toBeNull()
        ->and($mediumVariant->price_net_amount)->toBeNull()
        ->and($mediumVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($mediumVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 'm')->exists())->toBeTrue();

    $largeVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'rehafund-1002-2')
        ->firstOrFail();

    expect(str_starts_with($largeVariant->sku, 'RF-1012-M'))->toBeTrue()
        ->and($largeVariant->is_default)->toBeFalse()
        ->and($largeVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 'l')->exists())->toBeTrue();

    expect($product->images()->count())->toBe(0);
});

it('downloads RehaFund gallery and description images when image import is enabled', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://sklep.rehafund.pl/img/large/2905439/rf-1012-siedzisko' => Http::response(rehafundTinyPng(), 200, ['Content-Type' => 'image/png']),
        'https://sklep.rehafund.pl/img/large/2905440/rf-1012-siedzisko-bok' => Http::response(rehafundTinyPng(), 200, ['Content-Type' => 'image/png']),
        'https://sklep.rehafund.pl/usr/rf-1012-opis.jpg' => Http::response(rehafundTinyPng(), 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('', 404),
    ]);

    writeRehaFundImportFixture('scrapers/rehafund/test-product-data-images.json', [rehafundImportProductPayload()]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-product-data-images.json',
        '--image-limit' => '1',
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'rehafund')
        ->where('external_id', '1002')
        ->firstOrFail();

    expect($product->images()->count())->toBe(1)
        ->and($product->images()->firstOrFail()->is_main)->toBeTrue()
        ->and(Storage::disk('public')->allFiles('products/rehafund/1002/gallery'))->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles('products/rehafund/1002/description'))->toHaveCount(1)
        ->and($product->description)->toContain('/storage/products/rehafund/1002/description/')
        ->and($product->description)->not->toContain('data-lazy=')
        ->and($product->description)->not->toContain('srcset=');
});

it('imports RehaFund products without variant candidates with a single default variant and generated SKU fallback', function (): void {
    writeRehaFundImportFixture('scrapers/rehafund/test-single-variant-product-data.json', [
        rehafundImportProductPayload([
            'external_product_id' => '649',
            'slug' => 'balkonik-rehabilitacyjny-luca-803-1',
            'name' => 'Balkonik rehabilitacyjny Luca 803/1',
            'sku' => null,
            'ean' => null,
            'is_medical_device' => true,
            'variant_candidates' => [],
        ]),
    ]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-single-variant-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'rehafund')
        ->where('external_id', '649')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->variants()->count())->toBe(1)
        ->and($variant->external_variant_id)->toBe('rehafund-649-default')
        ->and($variant->sku)->toBe('REHAFUND-649')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->attributeValues()->count())->toBe(0);
});

it('skips unsafe long RehaFund variant attributes before creating filter values', function (): void {
    $unsafeManufacturerBlock = 'Zhenjiang Assure Medical Equipment Co.,Ltd No. 297, Chuqiao road, Zhenjiang city, Jiangsu province, China Upoważniony przedstawiciel EU REP Lotus NL B.V. Koningin Julianaplein 10, 2595 AA, s Gravenhage, The Netherlands Importer Reha Fund sp. z o.o. ul. Staniewicka 14, 03-310 Warszawa, www.rehafund.pl, e-mail info@rehafund.pl';

    writeRehaFundImportFixture('scrapers/rehafund/test-long-variant-attribute-product-data.json', [
        rehafundImportProductPayload([
            'external_product_id' => '1181',
            'slug' => 'standardowy-wozek-inwalidzki-lezakowy-szer-42-cm',
            'name' => 'Standardowy wózek inwalidzki leżakowy',
            'sku' => 'YJ-011JA/42/PN/S-624/PU',
            'variant_candidates' => [
                [
                    'name' => '42 cm',
                    'sku' => null,
                    'price_gross_amount' => null,
                    'currency' => 'PLN',
                    'availability' => 'unknown',
                    'attributes' => [
                        ['code' => 'szerokosc', 'label' => 'Szerokość', 'value' => '42 cm', 'slug' => '42-cm'],
                        ['code' => 'producent', 'label' => 'Producent', 'value' => $unsafeManufacturerBlock, 'slug' => 'producent'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-long-variant-attribute-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'rehafund')
        ->where('external_id', '1181')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($variant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'szerokosc'))->where('slug', '42-cm')->exists())->toBeTrue()
        ->and($variant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->exists())->toBeFalse()
        ->and(AttributeValue::query()->where('value', 'like', 'Zhenjiang Assure Medical Equipment%')->exists())->toBeFalse();
});

it('updates existing RehaFund products by external ID instead of duplicating them', function (): void {
    writeRehaFundImportFixture('scrapers/rehafund/test-update-product-data.json', [rehafundImportProductPayload()]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    writeRehaFundImportFixture('scrapers/rehafund/test-update-product-data.json', [
        rehafundImportProductPayload([
            'name' => 'Updated RehaFund product',
            'availability' => 'out_of_stock',
            'availability_label' => 'Dostępność: Niedostępny',
            'variant_candidates' => [
                [
                    'name' => 'M',
                    'sku' => null,
                    'price_gross_amount' => null,
                    'currency' => 'PLN',
                    'availability' => 'unknown',
                    'attributes' => [
                        ['code' => 'rozmiar', 'label' => 'Rozmiar', 'value' => 'M', 'slug' => 'm'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('rehafund:import', [
        '--from' => 'scrapers/rehafund/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'rehafund')
        ->where('external_id', '1002')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'rehafund')->where('external_id', '1002')->count())->toBe(1)
        ->and($product->name)->toBe('Updated RehaFund product')
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->price_net_amount)->toBeNull()
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeRehaFundImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'rehafund',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rehafundImportProductPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'rehafund',
        'source_url' => 'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002',
        'canonical_url' => 'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-342-1002',
        'external_product_id' => '1002',
        'slug' => 'siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012',
        'name' => 'Siedzisko do podnośnika kąpielowe',
        'brand' => ['name' => 'Reha Fund', 'slug' => 'reha-fund'],
        'category' => 'Podnośniki',
        'categories' => ['Rehabilitacja', 'Podnośniki'],
        'seo_title' => 'Siedzisko do podnośnika kąpielowe - RehaFund',
        'seo_description' => 'Siedzisko do podnośnika kąpielowe do rehabilitacji.',
        'short_description' => 'Siedzisko do podnośnika kąpielowe do rehabilitacji.',
        'description' => 'Opis towaru',
        'description_html' => '<h2>Opis towaru</h2><p>Siedzisko do kąpieli. <a href="https://sklep.rehafund.pl/produkty/2">Link dostawcy</a></p><img src="https://sklep.rehafund.pl/usr/rf-1012-opis.jpg" data-lazy="https://sklep.rehafund.pl/usr/rf-1012-opis.jpg" srcset="https://sklep.rehafund.pl/usr/rf-1012-opis.jpg 1x" alt="RF-1012 opis"><script>alert("x")</script>',
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępność: Od ręki',
        'shipping_time' => null,
        'stock_quantity' => null,
        'sku' => 'RF-1012/M',
        'ean' => '5903940707792',
        'attributes' => [
            ['code' => 'symbol', 'label' => 'Symbol', 'value' => 'RF-1012/M', 'slug' => 'rf-1012-m'],
            ['code' => 'kod-ean', 'label' => 'Kod EAN', 'value' => '5903940707792', 'slug' => '5903940707792'],
            ['code' => 'dostepnosc', 'label' => 'Dostępność', 'value' => 'Dostępność: Od ręki', 'slug' => 'od-reki'],
            ['code' => 'wysylka', 'label' => 'Wysyłka', 'value' => '24 godziny', 'slug' => '24-godziny'],
        ],
        'images' => [
            ['url' => 'https://sklep.rehafund.pl/img/large/2905439/rf-1012-siedzisko', 'alt' => 'RF-1012-siedzisko', 'sort_order' => 0],
            ['url' => 'https://sklep.rehafund.pl/img/large/2905440/rf-1012-siedzisko-bok', 'alt' => 'RF-1012 bok', 'sort_order' => 1],
        ],
        'tabs' => [
            'opis' => '<p>Opis towaru</p>',
            'parametry' => [
                ['code' => 'symbol', 'label' => 'Symbol', 'value' => 'RF-1012/M', 'slug' => 'rf-1012-m'],
                ['code' => 'dostepnosc', 'label' => 'Dostępność', 'value' => 'Dostępność: Od ręki', 'slug' => 'od-reki'],
            ],
        ],
        'variant_candidates' => [
            [
                'name' => 'M',
                'sku' => null,
                'price_gross_amount' => null,
                'currency' => 'PLN',
                'availability' => 'unknown',
                'attributes' => [
                    ['code' => 'rozmiar', 'label' => 'Rozmiar', 'value' => 'M', 'slug' => 'm'],
                ],
            ],
            [
                'name' => 'L',
                'sku' => null,
                'price_gross_amount' => null,
                'currency' => 'PLN',
                'availability' => 'unknown',
                'attributes' => [
                    ['code' => 'rozmiar', 'label' => 'Rozmiar', 'value' => 'L', 'slug' => 'l'],
                ],
            ],
        ],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
        'product_link_category_path' => ['Rehabilitacja', 'Podnośniki'],
    ];

    return rehafundImportPayloadMerge($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rehafundImportPayloadMerge(array $payload, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && array_is_list($value)) {
            $payload[$key] = $value;

            continue;
        }

        if (is_array($value) && isset($payload[$key]) && is_array($payload[$key]) && ! array_is_list($payload[$key])) {
            $payload[$key] = rehafundImportPayloadMerge($payload[$key], $value);

            continue;
        }

        $payload[$key] = $value;
    }

    return $payload;
}

function rehafundTinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=') ?: 'png';
}
