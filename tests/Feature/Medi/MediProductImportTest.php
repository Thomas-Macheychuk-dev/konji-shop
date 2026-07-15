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

uses(RefreshDatabase::class);

it('can dry-run a Medi product-data file without database writes', function (): void {
    writeMediImportFixture('scrapers/medi/test-product-data.json', [mediImportProductPayload()]);

    $this->artisan('medi:import', [
        '--from' => 'scrapers/medi/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 3')
        ->expectsOutputToContain('Variants to create/update: 3')
        ->expectsOutputToContain('Product images discovered: 2')
        ->expectsOutputToContain('Medical device products: 1')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports Medi products with exact child SKUs per-variant stock and all discovered category contexts', function (): void {
    writeMediImportFixture('scrapers/medi/test-product-data.json', [mediImportProductPayload()]);

    $this->artisan('medi:import', [
        '--from' => 'scrapers/medi/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'medi')
        ->where('external_id', '1073185')
        ->firstOrFail();

    expect($product->name)->toBe('duomed®')
        ->and($product->slug)->toBe('duomed-podkolanowki-uciskowe')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('01003S005')
        ->and($product->seo_title)->toBe('duomed podkolanówki uciskowe')
        ->and($product->seo_description)->toBe('Medyczne podkolanówki uciskowe drugiej klasy kompresji.')
        ->and($product->short_description)->toBe('<p>medyczne podkolanówki uciskowe</p>')
        ->and($product->description)->toContain('Opis produktu')
        ->and($product->description)->toContain('Parametry produktu')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->toContain('To jest wyrób medyczny')
        ->and($product->description)->toContain('https://www.youtube-nocookie.com/embed/v4MjZ-gOWLc')
        ->and($product->description)->not->toContain('Dostępne warianty')
        ->and($product->description)->not->toContain('Dane produktu zaimportowane')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('evil.example');

    $compression = Category::query()->where('slug', 'kompresja')->firstOrFail();
    $stockings = Category::query()->where('slug', 'podkolanowki-uciskowe')->firstOrFail();
    $secondClass = Category::query()->where('slug', 'podkolanowki-uciskowe-2-stopnia')->firstOrFail();

    expect($stockings->parent_id)->toBe($compression->id)
        ->and($secondClass->parent_id)->toBe($compression->id)
        ->and($product->categories()->whereKey($stockings->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($secondClass->id)->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'medi')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->where('slug', 'tak')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'sku'))->exists())->toBeFalse();

    expect($product->variants()->count())->toBe(3);

    $unavailableVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'medi-1073185-2001')
        ->firstOrFail();

    expect($unavailableVariant->sku)->toBe('01003S005-BEIGE-I')
        ->and($unavailableVariant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($unavailableVariant->is_default)->toBeFalse()
        ->and($unavailableVariant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($unavailableVariant->currency)->toBe(Currency::PLN)
        ->and($unavailableVariant->price_gross_amount)->toBe(14300)
        ->and($unavailableVariant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(14300))
        ->and($unavailableVariant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);

    $defaultVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'medi-1073185-2002')
        ->firstOrFail();

    expect($defaultVariant->sku)->toBe('01003S005-BEIGE-II')
        ->and($defaultVariant->is_default)->toBeTrue()
        ->and($defaultVariant->price_gross_amount)->toBe(14950)
        ->and($defaultVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($defaultVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'kolor'))->where('slug', 'bezowy')->exists())->toBeTrue()
        ->and($defaultVariant->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 'ii')->exists())->toBeTrue();

    $blackVariant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'medi-1073185-2003')
        ->firstOrFail();

    expect($blackVariant->sku)->toBe('01003S005-BLACK-II')
        ->and($blackVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($blackVariant->is_default)->toBeFalse();
});

it('imports a simple non-medical Medi accessory and handles repeated category names safely', function (): void {
    writeMediImportFixture('scrapers/medi/test-accessory-product-data.json', [
        mediImportProductPayload([
            'external_product_id' => '1173000',
            'source_url' => 'https://www.medi-polska.pl/shop/medi-butler-off.html',
            'canonical_url' => 'https://www.medi-polska.pl/shop/medi-butler-off.html',
            'slug' => 'medi-butler-off',
            'name' => 'medi Butler Off',
            'sku' => 'P200150',
            'price_gross_amount' => 165.0,
            'is_medical_device' => false,
            'source_category_path' => ['Akcesoria', 'Akcesoria'],
            'source_category_contexts' => [],
            'categories' => [],
            'variant_candidates' => [],
        ]),
    ]);

    $this->artisan('medi:import', [
        '--from' => 'scrapers/medi/test-accessory-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'medi')
        ->where('external_id', '1173000')
        ->firstOrFail();
    $variant = $product->variants()->firstOrFail();
    $root = Category::query()->where('slug', 'akcesoria')->firstOrFail();
    $leaf = Category::query()->where('slug', 'akcesoria-akcesoria')->firstOrFail();

    expect($root->parent_id)->toBeNull()
        ->and($leaf->parent_id)->toBe($root->id)
        ->and($leaf->id)->not->toBe($root->id)
        ->and($product->categories()->whereKey($leaf->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($variant->external_variant_id)->toBe('medi-1173000-default')
        ->and($variant->sku)->toBe('P200150')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variant->price_gross_amount)->toBe(16500)
        ->and($variant->attributeValues()->count())->toBe(0);
});

it('can import Medi products as active products', function (): void {
    writeMediImportFixture('scrapers/medi/test-active-product-data.json', [mediImportProductPayload()]);

    $this->artisan('medi:import', [
        '--from' => 'scrapers/medi/test-active-product-data.json',
        '--status' => 'active',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'medi')
        ->where('external_id', '1073185')
        ->firstOrFail();

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($product->variants()->where('status', ProductVariantStatus::ACTIVE)->count())->toBe(3);
});

it('updates existing Medi products and removes stale Magento child variants', function (): void {
    writeMediImportFixture('scrapers/medi/test-update-product-data.json', [mediImportProductPayload()]);

    $this->artisan('medi:import', [
        '--from' => 'scrapers/medi/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    writeMediImportFixture('scrapers/medi/test-update-product-data.json', [
        mediImportProductPayload([
            'name' => 'duomed® updated',
            'variant_candidates' => [
                mediImportVariant([
                    'external_variant_id' => '2002',
                    'sku' => '01003S005-BEIGE-II',
                    'price_gross_amount' => 159.99,
                    'availability' => 'out_of_stock',
                    'stock_quantity' => 0,
                ]),
            ],
        ]),
    ]);

    $this->artisan('medi:import', [
        '--from' => 'scrapers/medi/test-update-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'medi')
        ->where('external_id', '1073185')
        ->firstOrFail();
    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'medi')->where('external_id', '1073185')->count())->toBe(1)
        ->and($product->name)->toBe('duomed® updated')
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->external_variant_id)->toBe('medi-1073185-2002')
        ->and($variant->price_gross_amount)->toBe(15999)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(15999))
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variant->is_default)->toBeTrue();
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeMediImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'medi',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mediImportProductPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'medi',
        'source_url' => 'https://www.medi-polska.pl/shop/15.html',
        'canonical_url' => 'https://www.medi-polska.pl/shop/duomed-podkolanowki-uciskowe.html',
        'external_product_id' => '1073185',
        'slug' => 'duomed-podkolanowki-uciskowe',
        'name' => 'duomed®',
        'subtitle' => 'medyczne podkolanówki uciskowe',
        'brand' => [
            'name' => 'medi',
            'slug' => 'medi',
        ],
        'category' => 'Kompresja',
        'categories' => ['Kompresja'],
        'seo_title' => 'duomed podkolanówki uciskowe',
        'seo_description' => 'Medyczne podkolanówki uciskowe drugiej klasy kompresji.',
        'short_description' => 'medyczne podkolanówki uciskowe',
        'description' => 'Opis produktu duomed.',
        'description_html' => '<h2>Opis produktu</h2><p>Podkolanówki uciskowe do codziennej terapii.</p><script>alert("x")</script><iframe src="//www.youtube.com/embed/v4MjZ-gOWLc"></iframe><iframe src="https://evil.example/embed/bad"></iframe>',
        'price_gross_amount' => 143.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Wysyłka w ciągu 1-2 dni roboczych',
        'shipping_time' => '1-2 dni roboczych',
        'stock_quantity' => 9,
        'sku' => '01003S005',
        'ean' => null,
        'attributes' => [
            [
                'code' => 'sku',
                'label' => 'SKU',
                'value' => '01003S005',
                'slug' => '01003s005',
            ],
            [
                'code' => 'ccl',
                'label' => 'CCL',
                'value' => 'CCL 2',
                'slug' => 'ccl-2',
            ],
            [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => 'I | II | III | IV | V | VI | VII',
                'slug' => 'i-ii-iii-iv-v-vi-vii',
            ],
        ],
        'images' => [
            [
                'url' => 'https://s7e5a.scene7.com/is/image/medi/duomed-beige?$Product-medical-2to3$',
                'alt' => 'duomed beige',
            ],
            [
                'url' => 'https://s7e5a.scene7.com/is/image/medi/duomed-black?$Product-medical-2to3$',
                'alt' => 'duomed black',
            ],
        ],
        'tabs' => [],
        'variant_candidates' => [
            mediImportVariant([
                'external_variant_id' => '2001',
                'sku' => '01003S005-BEIGE-I',
                'label' => 'Kolor: Beżowy / Rozmiar: I',
                'attributes' => [
                    ['label' => 'Kolor', 'value' => 'Beżowy'],
                    ['label' => 'Rozmiar', 'value' => 'I'],
                ],
                'availability' => 'out_of_stock',
                'stock_quantity' => 0,
            ]),
            mediImportVariant([
                'external_variant_id' => '2002',
                'sku' => '01003S005-BEIGE-II',
                'label' => 'Kolor: Beżowy / Rozmiar: II',
                'attributes' => [
                    ['label' => 'Kolor', 'value' => 'Beżowy'],
                    ['label' => 'Rozmiar', 'value' => 'II'],
                ],
                'price_gross_amount' => 149.5,
                'availability' => 'in_stock',
                'stock_quantity' => 5,
            ]),
            mediImportVariant([
                'external_variant_id' => '2003',
                'sku' => '01003S005-BLACK-II',
                'label' => 'Kolor: Czarny / Rozmiar: II',
                'attributes' => [
                    ['label' => 'Kolor', 'value' => 'Czarny'],
                    ['label' => 'Rozmiar', 'value' => 'II'],
                ],
                'availability' => 'in_stock',
                'stock_quantity' => 4,
            ]),
        ],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
        'source_category_name' => 'Kompresja',
        'source_category_url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
        'source_top_category_name' => 'Kompresja',
        'source_top_category_url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
        'source_category_path' => ['Kompresja'],
        'source_category_contexts' => [
            [
                'source_category_name' => 'Kompresja',
                'source_category_path' => ['Kompresja'],
            ],
            [
                'source_category_name' => 'Podkolanówki uciskowe',
                'source_category_path' => ['Kompresja', 'Podkolanówki uciskowe'],
            ],
            [
                'source_category_name' => 'Podkolanówki uciskowe 2 stopnia',
                'source_category_path' => ['Kompresja', 'Podkolanówki uciskowe 2 stopnia'],
            ],
        ],
        'source_product_list_name' => 'duomed®',
    ];

    return mediImportPayloadMerge($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mediImportVariant(array $overrides = []): array
{
    return array_merge([
        'external_variant_id' => '2001',
        'sku' => '01003S005-BEIGE-I',
        'label' => 'Kolor: Beżowy / Rozmiar: I',
        'attributes' => [
            ['label' => 'Kolor', 'value' => 'Beżowy'],
            ['label' => 'Rozmiar', 'value' => 'I'],
        ],
        'price_gross_amount' => 143.0,
        'old_price_gross_amount' => 149.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'stock_quantity' => 1,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mediImportPayloadMerge(array $payload, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && array_is_list($value)) {
            $payload[$key] = $value;

            continue;
        }

        if (is_array($value) && isset($payload[$key]) && is_array($payload[$key]) && ! array_is_list($payload[$key])) {
            $payload[$key] = mediImportPayloadMerge($payload[$key], $value);

            continue;
        }

        $payload[$key] = $value;
    }

    return $payload;
}
