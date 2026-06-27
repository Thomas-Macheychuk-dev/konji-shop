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

it('can dry-run a Reh4Mat product-data file without database writes', function (): void {
    writeReh4MatImportFixture('scrapers/reh4mat/test-product-data.json', [reh4matImportProductPayload()]);

    $this->artisan('reh4mat:import', [
        '--from' => 'scrapers/reh4mat/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images or downloads were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 2')
        ->expectsOutputToContain('Pictograms discovered: 2')
        ->expectsOutputToContain('Downloads discovered: 2')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports Reh4Mat product-data as draft products with category hierarchy, default variants, local downloads, and safe accessory placeholders', function (): void {
    Storage::fake('public');
    Http::fake([
        'reh4mat.com/*' => Http::response('%PDF-1.4 test file', 200, ['Content-Type' => 'application/pdf']),
        'www.reh4mat.com/*' => Http::response('%PDF-1.4 test file', 200, ['Content-Type' => 'application/pdf']),
        '*' => Http::response('', 404),
    ]);

    writeReh4MatImportFixture('scrapers/reh4mat/test-product-data.json', [reh4matImportProductPayload()]);

    $this->artisan('reh4mat:import', [
        '--from' => 'scrapers/reh4mat/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'reh4mat')
        ->where('external_id', '3692')
        ->firstOrFail();

    expect($product->name)->toBe('Orteza kończyny dolnej AM-KD-AM/2R')
        ->and($product->slug)->toBe('aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('https://www.reh4mat.com/produkt/kolano-funkcja-pooperacyjna/aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r/')
        ->and($product->seo_title)->toBe('Orteza kończyny dolnej AM-KD-AM/2R | Reh4Mat')
        ->and($product->short_description)->toContain('Aparat szynowo-opaskowy')
        ->and($product->description)->toContain('Opis produktu')
        ->and($product->description)->toContain('Piktogramy produktu')
        ->and($product->description)->toContain('Alternatywa gipsu')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->toContain('UMDNS')
        ->and($product->description)->toContain('Do pobrania')
        ->and($product->description)->toContain('Deklaracja zgodności')
        ->and($product->description)->toContain('/storage/products/reh4mat/3692/downloads/deklaracja-zgodnosci-')
        ->and($product->description)->toContain('reh4mat-pending-accessory')
        ->and($product->description)->toContain('data-external-slug="rekaw-elastyczny-pod-orteze"')
        ->and($product->description)->toContain('Rękaw elastyczny pod ortezę')
        ->and($product->description)->toContain('TO JEST WYRÓB MEDYCZNY')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('reh4mat.com')
        ->and($product->description)->not->toContain('stabilobedsystem.pl');

    $rootCategory = Category::query()->where('slug', 'konczyna-dolna')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'ortezy-calej-konczyny-dolnej')->firstOrFail();

    expect($leafCategory->parent_id)->toBe($rootCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($rootCategory->id)->exists())->toBeTrue();

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'reh4mat-3692-default')
        ->firstOrFail();

    expect($variant->sku)->toBe('AM-KD-AM-2R')
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->currency)->toBe(Currency::PLN)
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->price_net_amount)->toBeNull()
        ->and($variant->stock_status)->toBe(StockStatus::IN_STOCK);

    expect($product->images()->count())->toBe(0);

    Storage::disk('public')->assertExists('products/reh4mat/3692/downloads/deklaracja-zgodnosci-'.substr(sha1('https://reh4mat.com/deklaracje/pl/117.pdf'), 0, 10).'.pdf');
});

it('can import Reh4Mat products as active products', function (): void {
    writeReh4MatImportFixture('scrapers/reh4mat/test-product-data-active.json', [reh4matImportProductPayload()]);

    $this->artisan('reh4mat:import', [
        '--from' => 'scrapers/reh4mat/test-product-data-active.json',
        '--status' => 'active',
        '--no-images' => true,
        '--no-downloads' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'reh4mat')
        ->where('external_id', '3692')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($variant->status)->toBe(ProductVariantStatus::ACTIVE);
});

it('updates existing Reh4Mat products by external ID instead of duplicating them', function (): void {
    writeReh4MatImportFixture('scrapers/reh4mat/test-product-data-update.json', [reh4matImportProductPayload()]);

    $this->artisan('reh4mat:import', [
        '--from' => 'scrapers/reh4mat/test-product-data-update.json',
        '--no-images' => true,
        '--no-downloads' => true,
    ])->assertSuccessful();

    writeReh4MatImportFixture('scrapers/reh4mat/test-product-data-update.json', [
        reh4matImportProductPayload([
            'name' => 'Updated Reh4Mat product',
            'price_gross_amount' => 123.45,
            'availability' => 'out_of_stock',
        ]),
    ]);

    $this->artisan('reh4mat:import', [
        '--from' => 'scrapers/reh4mat/test-product-data-update.json',
        '--no-images' => true,
        '--no-downloads' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'reh4mat')
        ->where('external_id', '3692')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'reh4mat')->where('external_id', '3692')->count())->toBe(1)
        ->and($product->name)->toBe('Updated Reh4Mat product')
        ->and($variant->price_gross_amount)->toBe(12345)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(12345))
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeReh4MatImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'reh4mat',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reh4matImportProductPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'reh4mat',
        'source_url' => 'https://www.reh4mat.com/produkt/kolano-funkcja-pooperacyjna/aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r/',
        'canonical_url' => 'https://www.reh4mat.com/produkt/kolano-funkcja-pooperacyjna/aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r/',
        'external_product_id' => '3692',
        'slug' => 'aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r',
        'name' => 'Orteza kończyny dolnej AM-KD-AM/2R',
        'brand' => '4medic',
        'category' => 'Ortezy całej kończyny dolnej',
        'categories' => ['KOŃCZYNA DOLNA', 'Ortezy całej kończyny dolnej'],
        'source_category_path' => ['KOŃCZYNA DOLNA', 'Ortezy całej kończyny dolnej'],
        'seo_title' => 'Orteza kończyny dolnej AM-KD-AM/2R | Reh4Mat',
        'seo_description' => 'Aparat szynowo-opaskowy kończyny dolnej.',
        'short_description' => 'Aparat szynowo-opaskowy kończyny dolnej.',
        'description' => 'Opis produktu.',
        'description_html' => '<h2>Opis produktu</h2><p>Aparat szynowo-opaskowy kończyny dolnej.</p><script>alert("x")</script>',
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'sku' => 'AM-KD-AM/2R',
        'product_meta' => [
            'Kod katalogowy' => 'AM-KD-AM/2R',
            'Model' => 'AM-KD-AM/2R',
        ],
        'codes' => [
            'UMDNS' => ['16210'],
            'NFZ' => ['H.03.02.00'],
        ],
        'images' => [
            [
                'url' => 'https://www.reh4mat.com/uploads/2026/05/example.jpg',
                'alt' => 'AM-KD-AM/2R',
                'position' => 1,
            ],
        ],
        'pictograms' => [
            [
                'label' => 'Alternatywa gipsu',
                'image_url' => 'https://www.reh4mat.com/uploads/2021/04/STD-alternatywa-gipsu.png',
                'description' => null,
                'source' => 'reh4mat_piktogram',
            ],
            [
                'label' => 'Ortopedia',
                'image_url' => 'https://www.reh4mat.com/uploads/2021/04/STD-ortopedia_PL.png',
                'description' => null,
                'source' => 'reh4mat_piktogram',
            ],
        ],
        'regulatory_icons' => [
            [
                'label' => 'CE',
                'image_url' => 'https://www.reh4mat.com/wp-content/themes/r4m-rwd/images/ce.png',
                'description' => 'Wyrób Medyczny klasy I.',
            ],
        ],
        'downloads' => [
            [
                'label' => 'Deklaracja zgodności',
                'url' => 'https://reh4mat.com/deklaracje/pl/117.pdf',
                'type' => 'pdf',
            ],
            [
                'label' => 'Instrukcja użytkowania',
                'url' => 'https://reh4mat.com/instrukcje/37.pdf',
                'type' => 'pdf',
            ],
        ],
        'tabs' => [
            [
                'title' => 'Opis',
                'html' => '<p>Opis produktu.</p>',
                'text' => 'Opis produktu.',
            ],
            [
                'title' => 'Rozmiary',
                'html' => '<table><tbody><tr><td>M</td><td>30 cm</td></tr></tbody></table>',
                'text' => 'M 30 cm',
            ],
            [
                'title' => 'Akcesoria',
                'html' => '<h3>AKCESORIA/PRODUKTY DO STOSOWANIA RAZEM Z WYROBEM</h3><ul><li><a href="https://www.reh4mat.com/produkt/akcesoria/rekaw-elastyczny-pod-orteze/">Rękaw elastyczny pod ortezę</a></li><li><a href="https://www.reh4mat.com/produkt/akcesoria/rekaw-ochronny-pod-orteze-am-rb/">Rękaw ochronny pod ortezę AM-RB</a></li></ul>',
                'text' => 'Rękaw elastyczny pod ortezę Rękaw ochronny pod ortezę AM-RB',
            ],
        ],
        'medical_device_notice' => 'TO JEST WYRÓB MEDYCZNY. UŻYWAJ GO ZGODNIE Z INSTRUKCJĄ UŻYWANIA LUB ETYKIETĄ.',
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ], $overrides);
}
