<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can dry-run a Butterfly product-data file without database writes', function (): void {
    writeButterflyImportFixture('scrapers/butterfly/test-product-data.json', [butterflyImportProductPayload()]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-product-data.json',
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

it('imports Butterfly product-data as draft products with category hierarchy attributes variants and safe descriptions', function (): void {
    writeButterflyImportFixture('scrapers/butterfly/test-product-data.json', [butterflyImportProductPayload()]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'butterfly')
        ->where('external_id', '76')
        ->firstOrFail();

    expect($product->name)->toBe('Magnetyczny stabilizator kręgosłupa Harmonium')
        ->and($product->slug)->toBe('magnetyczny-stabilizator-kregoslupa-harmonium')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('HARMONIUM')
        ->and($product->seo_title)->toBe('Butterfly | Magnetyczny stabilizator kręgosłupa Harmonium')
        ->and($product->seo_description)->toBe('Magnetyczny stabilizator kręgosłupa Harmonium opis SEO')
        ->and($product->short_description)->toBe('<p>Magnetyczny stabilizator kręgosłupa Harmonium opis SEO</p>')
        ->and($product->description)->toContain('Opis produktu')
        ->and($product->description)->toContain('Parametry produktu')
        ->and($product->description)->toContain('Dostępne warianty')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->not->toContain('<th>Wysyłka</th>')
        ->and($product->description)->not->toContain('<td>24 godziny</td>')
        ->and($product->description)->toContain('To jest wyrób medyczny')
        ->and($product->description)->toContain('Pasy Euromag')
        ->and($product->description)->toContain('oryginalny sklep')
        ->and($product->description)->not->toContain('<a')
        ->and($product->description)->not->toContain('</a>')
        ->and($product->description)->not->toContain('href=')
        ->and($product->description)->not->toContain('butterfly-mag.com/pl/p/original')
        ->and($product->description)->toContain('https://www.youtube-nocookie.com/embed/v4MjZ-gOWLc')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('evil.example');

    $topCategory = Category::query()->where('slug', 'magnetyczne-pasy-na-kregoslup')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'pasy-harmonium')->firstOrFail();

    expect($leafCategory->parent_id)->toBe($topCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($topCategory->id)->exists())->toBeTrue()
        ->and(Category::query()->where('name', 'Jesteś tutaj::')->exists())->toBeFalse()
        ->and(Category::query()->where('name', 'Strona główna')->exists())->toBeFalse()
        ->and(Category::query()->where('name', 'like', '> %')->exists())->toBeFalse();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'butterfly-bio-magnetic-system')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->where('slug', 'tak')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->exists())->toBeFalse();

    expect(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(2);

    $smallVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'butterfly-76-s')
        ->firstOrFail();

    expect($smallVariant->sku)->toBe('HARMONIUM-S')
        ->and($smallVariant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($smallVariant->is_default)->toBeTrue()
        ->and($smallVariant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($smallVariant->currency)->toBe(Currency::PLN)
        ->and($smallVariant->price_gross_amount)->toBe(25000)
        ->and($smallVariant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(25000))
        ->and($smallVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($smallVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 's')->exists())->toBeTrue();

    $mediumVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'butterfly-76-m')
        ->firstOrFail();

    expect($mediumVariant->sku)->toBe('HARMONIUM-M')
        ->and($mediumVariant->is_default)->toBeFalse()
        ->and($mediumVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 'm')->exists())->toBeTrue();

    expect($product->images()->count())->toBe(0);
});

it('downloads and rewrites Butterfly embedded description images during image imports', function (): void {
    Storage::fake('public');

    Http::fake([
        'butterfly-mag.com/*' => Http::response('fake-image-contents', 200, ['Content-Type' => 'image/png']),
    ]);

    writeButterflyImportFixture('scrapers/butterfly/test-description-image-product-data.json', [
        butterflyImportProductPayload([
            'images' => [],
            'description_html' => '<h2>Opis produktu</h2><p><img src="/userdata/public/assets//harmonium.png" srcset="/userdata/public/assets//harmonium.png 1x" data-src="/userdata/public/assets//lazy.jpg" alt="Tabela rozmiarów" width="280" height="374"></p>',
        ]),
    ]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-description-image-product-data.json',
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'butterfly')
        ->where('external_id', '76')
        ->firstOrFail();

    $descriptionFiles = Storage::disk('public')->allFiles('products/butterfly/76/description');

    expect($descriptionFiles)->toHaveCount(1)
        ->and($product->description)->toContain('/storage/products/butterfly/76/description/')
        ->and($product->description)->toContain('alt="Tabela rozmiarów"')
        ->and($product->description)->not->toContain('/userdata/public/assets')
        ->and($product->description)->not->toContain('srcset=')
        ->and($product->description)->not->toContain('data-src=');
});

it('imports Butterfly products without variant candidates with a single default variant', function (): void {
    writeButterflyImportFixture('scrapers/butterfly/test-single-variant-product-data.json', [
        butterflyImportProductPayload([
            'external_product_id' => '78',
            'slug' => 'magnetyczna-poduszka-ortopedyczna-ort-butterfly',
            'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
            'sku' => 'ORT-BUTTERFLY',
            'price_gross_amount' => 180.0,
            'variant_candidates' => [],
        ]),
    ]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-single-variant-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'butterfly')
        ->where('external_id', '78')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->variants()->count())->toBe(1)
        ->and($variant->external_variant_id)->toBe('butterfly-78-default')
        ->and($variant->sku)->toBe('ORT-BUTTERFLY')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->price_gross_amount)->toBe(18000)
        ->and($variant->attributeValues()->count())->toBe(0);
});

it('can import Butterfly products as active products', function (): void {
    writeButterflyImportFixture('scrapers/butterfly/test-active-product-data.json', [butterflyImportProductPayload()]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-active-product-data.json',
        '--status' => 'active',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'butterfly')
        ->where('external_id', '76')
        ->firstOrFail();

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($product->variants()->where('status', ProductVariantStatus::ACTIVE)->count())->toBe(2);
});

it('updates existing Butterfly products by external ID instead of duplicating them', function (): void {
    writeButterflyImportFixture('scrapers/butterfly/test-update-product-data.json', [butterflyImportProductPayload()]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    writeButterflyImportFixture('scrapers/butterfly/test-update-product-data.json', [
        butterflyImportProductPayload([
            'name' => 'Updated Butterfly product',
            'price_gross_amount' => 299.99,
            'availability' => 'out_of_stock',
            'availability_label' => 'brak towaru',
            'variant_candidates' => [
                [
                    'external_variant_id' => 'S',
                    'sku' => null,
                    'label' => 'S',
                    'attributes' => [
                        ['label' => 'Rozmiar:', 'value' => 'S'],
                    ],
                    'price_gross_amount' => 299.99,
                    'currency' => 'PLN',
                ],
            ],
        ]),
    ]);

    $this->artisan('butterfly:import', [
        '--from' => 'scrapers/butterfly/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'butterfly')
        ->where('external_id', '76')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'butterfly')->where('external_id', '76')->count())->toBe(1)
        ->and($product->name)->toBe('Updated Butterfly product')
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->price_gross_amount)->toBe(29999)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(29999))
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeButterflyImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'butterfly',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function butterflyImportProductPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'butterfly',
        'source_url' => 'https://butterfly-mag.com/pl/p/Magnetyczny-stabilizator-kregoslupa-Harmonium/76',
        'canonical_url' => 'https://butterfly-mag.com/pl/p/Magnetyczny-stabilizator-kregoslupa-Harmonium/76',
        'external_product_id' => '76',
        'slug' => 'magnetyczny-stabilizator-kregoslupa-harmonium',
        'name' => 'Magnetyczny stabilizator kręgosłupa Harmonium',
        'brand' => [
            'name' => 'Butterfly Bio Magnetic System',
            'slug' => 'butterfly-bio-magnetic-system',
        ],
        'category' => 'Pasy Harmonium',
        'categories' => ['Magnetyczne pasy na kręgosłup', 'Pasy Harmonium'],
        'seo_title' => 'Butterfly | Magnetyczny stabilizator kręgosłupa Harmonium',
        'seo_description' => 'Magnetyczny stabilizator kręgosłupa Harmonium opis SEO',
        'short_description' => 'Magnetyczny stabilizator kręgosłupa Harmonium opis SEO',
        'description' => 'Opis produktu',
        'description_html' => '<h2>Opis produktu</h2><p>Magnetyczny stabilizator kręgosłupa Harmonium. Sprawdź <a href="/pl/c/Pasy-Euromag/20">Pasy Euromag</a> i <a href="https://butterfly-mag.com/pl/p/original/1" target="_blank">oryginalny sklep</a>.</p><script>alert("x")</script><iframe src="//www.youtube.com/embed/v4MjZ-gOWLc"></iframe><iframe src="https://evil.example/embed/bad"></iframe>',
        'price_gross_amount' => 250.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Towar dostępny od ręki',
        'shipping_time' => '24 godziny',
        'stock_quantity' => null,
        'sku' => 'HARMONIUM',
        'ean' => null,
        'attributes' => [
            [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => 'S M L XL',
                'slug' => 's-m-l-xl',
            ],
        ],
        'images' => [
            [
                'url' => 'https://butterfly-mag.com/environment/cache/images/productGfx_719_300_300/HARMONIUM.jpg',
                'alt' => 'Magnetyczny stabilizator kręgosłupa Harmonium',
            ],
            [
                'url' => 'https://butterfly-mag.com/environment/cache/images/productGfx_70cb984622f8d3560e56114f74143830_300_300.jpg',
                'alt' => 'Harmonim drugi widok',
            ],
        ],
        'tabs' => [
            'opis' => '<p>Opis produktu</p>',
            'parametry' => [
                [
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'S M L XL',
                    'slug' => 's-m-l-xl',
                ],
            ],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => 'S',
                'sku' => null,
                'label' => 'S',
                'attributes' => [
                    ['label' => 'Rozmiar:', 'value' => 'S'],
                ],
                'price_gross_amount' => 250.0,
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => 'M',
                'sku' => null,
                'label' => 'M',
                'attributes' => [
                    ['label' => 'Rozmiar:', 'value' => 'M'],
                ],
                'price_gross_amount' => 250.0,
                'currency' => 'PLN',
            ],
        ],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
        'source_category_name' => 'Pasy Harmonium',
        'source_category_url' => 'https://butterfly-mag.com/pl/c/Pasy-Harmonium/22',
        'source_top_category_name' => 'Magnetyczne pasy na kręgosłup',
        'source_top_category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19',
        'source_category_path' => ['Strona główna', 'Jesteś tutaj::', '> Magnetyczne pasy na kręgosłup', '> Pasy Harmonium'],
        'source_product_list_name' => 'Magnetyczny stabilizator kręgosłupa Harmonium',
    ];

    return butterflyImportPayloadMerge($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function butterflyImportPayloadMerge(array $payload, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && array_is_list($value)) {
            $payload[$key] = $value;

            continue;
        }

        if (is_array($value) && isset($payload[$key]) && is_array($payload[$key]) && ! array_is_list($payload[$key])) {
            $payload[$key] = butterflyImportPayloadMerge($payload[$key], $value);

            continue;
        }

        $payload[$key] = $value;
    }

    return $payload;
}
