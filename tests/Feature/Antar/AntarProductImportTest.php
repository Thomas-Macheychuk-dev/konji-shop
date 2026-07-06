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

it('can dry-run an Antar product-data file without database writes', function (): void {
    writeAntarImportFixture('scrapers/antar/test-product-data.json', [antarImportProductPayload()]);

    $this->artisan('antar:import', [
        '--from' => 'scrapers/antar/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images or documents were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 3')
        ->expectsOutputToContain('Default variants to create/update: 1')
        ->expectsOutputToContain('Product images discovered: 1')
        ->expectsOutputToContain('Documents discovered: 2')
        ->expectsOutputToContain('Medical device products: 1')
        ->expectsOutputToContain('Products without SKU: 0')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports Antar product-data as draft products with categories metadata documents and a default variant', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://antar.net/wp-content/uploads/2025/01/OP-MDR01-INS-PL-CS-SK_03-1.pdf' => Http::response('%PDF-antar-instruction', 200, ['Content-Type' => 'application/pdf']),
        '*' => Http::response('', 404),
    ]);

    writeAntarImportFixture('scrapers/antar/test-product-data.json', [antarImportProductPayload()]);

    $this->artisan('antar:import', [
        '--from' => 'scrapers/antar/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'accutex-orteza-stawu-lokciowego-2986')
        ->firstOrFail();

    expect($product->name)->toBe('Orteza stawu łokciowego AccuTex 2986')
        ->and($product->slug)->toBe('accutex-orteza-stawu-lokciowego-2986')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('2986')
        ->and($product->seo_title)->toBe('Orteza stawu łokciowego AccuTex 2986 - Antar')
        ->and($product->seo_description)->toBe('Zapewnij swojemu łokciu stabilność i komfort z ortezą AccuTex 2986!')
        ->and($product->short_description)->toBe('<p>Orteza stawu łokciowego AccuTex 2986 zapewnia stabilność.</p>')
        ->and($product->description)->toContain('Opis')
        ->and($product->description)->toContain('Tabela Rozmiarow')
        ->and($product->description)->toContain('Parametry produktu')
        ->and($product->description)->toContain('Dokumenty do pobrania')
        ->and($product->description)->toContain('/storage/products/antar/accutex-orteza-stawu-lokciowego-2986/documents/instrukcja-')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->toContain('To jest wyrób medyczny')
        ->and($product->description)->not->toContain('Polityka Prywatności')
        ->and($product->description)->not->toContain('Antar_polityka_prywatnosci.pdf')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('<img');

    $topCategory = Category::query()->where('slug', 'ortopedia')->firstOrFail();
    $middleCategory = Category::query()->where('slug', 'ortezy-konczyn-gornych')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'ortezy-stawu-lokciowego')->firstOrFail();

    expect($middleCategory->parent_id)->toBe($topCategory->id)
        ->and($leafCategory->parent_id)->toBe($middleCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($topCategory->id)->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->where('slug', 'oppo-medical-inc')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->where('slug', 'tak')->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'rozmiar'))->where('slug', 's-m-l-xl-xxl')->exists())->toBeTrue();

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'antar-accutex-orteza-stawu-lokciowego-2986-default')
        ->firstOrFail();

    expect($variant->sku)->toBe('2986')
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->currency)->toBe(Currency::PLN)
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->price_net_amount)->toBeNull()
        ->and($variant->stock_status)->toBe(StockStatus::IN_STOCK);

    expect($product->images()->count())->toBe(0);
    expect(Storage::disk('public')->allFiles('products/antar/accutex-orteza-stawu-lokciowego-2986/documents'))->toHaveCount(1);
});

it('imports Antar products without catalogue number with nullable SKU instead of inventing one', function (): void {
    writeAntarImportFixture('scrapers/antar/test-no-sku-product-data.json', [antarImportProductPayload([
        'external_product_id' => 'inhalator',
        'slug' => 'inhalator',
        'source_url' => 'https://antar.net/produkt/inhalator/',
        'canonical_url' => 'https://antar.net/produkt/inhalator/',
        'name' => 'Inhalator',
        'brand' => 'Foshan Hongfeng Co., Ltd.a',
        'category' => 'Inhalatory',
        'categories' => ['Sprzęt pomocniczy i sanitarny', 'Tlenoterapia', 'Inhalatory'],
        'source_category_path' => ['Sprzęt pomocniczy i sanitarny', 'Tlenoterapia', 'Inhalatory'],
        'sku' => null,
        'attributes' => [
            [
                'code' => 'producer',
                'label' => 'Producent',
                'value' => 'Foshan Hongfeng Co., Ltd.a',
                'slug' => 'foshan-hongfeng-co-ltda',
            ],
        ],
        'documents' => [],
        'warnings' => ['Product catalogue number was not found.'],
    ])]);

    $this->artisan('antar:import', [
        '--from' => 'scrapers/antar/test-no-sku-product-data.json',
        '--no-images' => true,
        '--no-documents' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'inhalator')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->external_parent_sku)->toBeNull()
        ->and($variant->sku)->toBeNull()
        ->and($variant->external_variant_id)->toBe('antar-inhalator-default')
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8);
});

it('can import Antar products as active products', function (): void {
    writeAntarImportFixture('scrapers/antar/test-active-product-data.json', [antarImportProductPayload()]);

    $this->artisan('antar:import', [
        '--from' => 'scrapers/antar/test-active-product-data.json',
        '--status' => 'active',
        '--no-images' => true,
        '--no-documents' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'accutex-orteza-stawu-lokciowego-2986')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($variant->status)->toBe(ProductVariantStatus::ACTIVE);
});

it('updates existing Antar products by external ID instead of duplicating them', function (): void {
    writeAntarImportFixture('scrapers/antar/test-update-product-data.json', [antarImportProductPayload()]);

    $this->artisan('antar:import', [
        '--from' => 'scrapers/antar/test-update-product-data.json',
        '--no-images' => true,
        '--no-documents' => true,
    ])->assertSuccessful();

    writeAntarImportFixture('scrapers/antar/test-update-product-data.json', [antarImportProductPayload([
        'name' => 'Updated Antar product',
        'sku' => '2986-UPD',
        'price_gross_amount' => 123.45,
        'availability' => 'out_of_stock',
        'availability_label' => 'brak towaru',
    ])]);

    $this->artisan('antar:import', [
        '--from' => 'scrapers/antar/test-update-product-data.json',
        '--no-images' => true,
        '--no-documents' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'antar')
        ->where('external_id', 'accutex-orteza-stawu-lokciowego-2986')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'antar')->where('external_id', 'accutex-orteza-stawu-lokciowego-2986')->count())->toBe(1)
        ->and($product->name)->toBe('Updated Antar product')
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->sku)->toBe('2986-UPD')
        ->and($variant->price_gross_amount)->toBe(12345)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(12345))
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeAntarImportFixture(string $relativePath, array $products): void
{
    Storage::disk('local')->put($relativePath, json_encode([
        'source' => 'antar',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function antarImportProductPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'antar',
        'source_url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        'canonical_url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        'external_product_id' => 'accutex-orteza-stawu-lokciowego-2986',
        'slug' => 'accutex-orteza-stawu-lokciowego-2986',
        'name' => 'Orteza stawu łokciowego AccuTex 2986',
        'brand' => 'OPPO MEDICAL INC',
        'category' => 'Ortezy stawu łokciowego',
        'categories' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
        'seo_title' => 'Orteza stawu łokciowego AccuTex 2986 - Antar',
        'seo_description' => 'Zapewnij swojemu łokciu stabilność i komfort z ortezą AccuTex 2986!',
        'short_description' => 'Orteza stawu łokciowego AccuTex 2986 zapewnia stabilność.',
        'description' => 'Opis produktu',
        'description_html' => '<h2>Opis</h2><p><strong>Orteza stawu łokciowego AccuTex 2986</strong></p><p>Opis produktu.</p><script>alert(1)</script><p><img src="https://antar.net/wp-content/uploads/2023/11/logo.png" alt="Logo"></p>',
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'availability_label' => null,
        'stock_quantity' => null,
        'sku' => '2986',
        'ean' => null,
        'attributes' => [
            [
                'code' => 'catalogue_number',
                'label' => 'Numer katalogowy',
                'value' => '2986',
                'slug' => '2986',
            ],
            [
                'code' => 'producer',
                'label' => 'Producent',
                'value' => 'OPPO MEDICAL INC',
                'slug' => 'oppo-medical-inc',
            ],
            [
                'code' => 'rozmiar',
                'label' => 'ROZMIAR',
                'value' => 'S | M | L | XL | XXL',
                'slug' => 's-m-l-xl-xxl',
            ],
        ],
        'images' => [
            [
                'url' => 'https://antar.net/wp-content/uploads/2023/11/2986.jpg',
                'alt' => 'Orteza stawu łokciowego AccuTex 2986',
                'position' => 1,
            ],
        ],
        'documents' => [
            [
                'url' => 'https://antar.net/wp-content/uploads/2025/01/OP-MDR01-INS-PL-CS-SK_03-1.pdf',
                'label' => 'Instrukcja',
                'extension' => 'pdf',
            ],
            [
                'url' => 'https://antar.net/wp-content/uploads/2026/01/Antar_polityka_prywatnosci.pdf',
                'label' => 'Polityka Prywatności',
                'extension' => 'pdf',
            ],
        ],
        'tabs' => [
            [
                'title' => 'Opis',
                'content' => 'Opis produktu',
                'content_html' => '<h2>Opis</h2><p>Duplicate main description.</p>',
            ],
            [
                'title' => 'Tabela Rozmiarow',
                'content' => 'TABELA ROZMIARÓW',
                'content_html' => '<table><tbody><tr><th>ROZMIAR</th><td>S</td><td>M</td></tr></tbody></table>',
            ],
            [
                'title' => 'Dokumenty Do Pobrania',
                'content' => 'Dokumenty do pobrania',
                'content_html' => '<p><a href="https://antar.net/wp-content/uploads/2025/01/OP-MDR01-INS-PL-CS-SK_03-1.pdf"><img src="https://antar.net/wp-content/uploads/2023/11/download-pdf-1.png" alt=""></a></p><p>INSTRUKCJA</p>',
            ],
        ],
        'variant_candidates' => [],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
        'source_product_url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        'source_product_external_id' => 'accutex-orteza-stawu-lokciowego-2986',
        'source_product_slug' => 'accutex-orteza-stawu-lokciowego-2986',
        'source_category_name' => 'Ortezy stawu łokciowego',
        'source_category_url' => 'https://antar.net/produkty/ortopedia/ortezy-konczyn-gornych/ortezy-stawu-lokciowego/',
        'source_top_category_name' => 'Ortopedia',
        'source_category_path' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
    ];

    return antarImportPayloadMerge($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function antarImportPayloadMerge(array $payload, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && array_is_list($value)) {
            $payload[$key] = $value;

            continue;
        }

        if (is_array($value) && isset($payload[$key]) && is_array($payload[$key]) && ! array_is_list($payload[$key])) {
            $payload[$key] = antarImportPayloadMerge($payload[$key], $value);

            continue;
        }

        $payload[$key] = $value;
    }

    return $payload;
}
