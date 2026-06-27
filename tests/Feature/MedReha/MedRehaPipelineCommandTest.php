<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('runs the MedReha full pipeline as a dry run and saves intermediate JSON files', function (): void {
    Storage::fake('local');

    Http::fake([
        'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169*' => Http::response(medrehaPipelineProductListFixture([
            ['https://sklep.medreha.pl/pl/p/Product-A/101', 'Product A'],
            ['https://sklep.medreha.pl/pl/p/Product-B/102', 'Product B'],
        ])),
        'https://sklep.medreha.pl/pl/c*' => Http::response(medrehaPipelineCategoryMenuFixture()),
        'https://sklep.medreha.pl/pl/p/Product-A/101' => Http::response(medrehaPipelineProductPageFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://sklep.medreha.pl/pl/p/Product-B/102' => Http::response(medrehaPipelineProductPageFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-B/102',
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
        )),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('medreha:pipeline', [
        '--dry-run' => true,
        '--limit' => '1',
        '--offset' => '1',
        '--request-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Running MedReha full pipeline...')
        ->expectsOutputToContain('Step 1/4: discovering MedReha categories...')
        ->expectsOutputToContain('Product URLs discovered: 2')
        ->expectsOutputToContain('Products crawled: 1')
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->assertSuccessful();

    Storage::disk('local')->assertExists('scrapers/medreha/categories.json');
    Storage::disk('local')->assertExists('scrapers/medreha/product-links.json');
    Storage::disk('local')->assertExists('scrapers/medreha/full-product-data.json');

    $categoryDiscovery = json_decode(Storage::disk('local')->get('scrapers/medreha/categories.json'), true, flags: JSON_THROW_ON_ERROR);
    $productLinkDiscovery = json_decode(Storage::disk('local')->get('scrapers/medreha/product-links.json'), true, flags: JSON_THROW_ON_ERROR);
    $productData = json_decode(Storage::disk('local')->get('scrapers/medreha/full-product-data.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($categoryDiscovery['product_category_urls'])->toBe(['https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169'])
        ->and($productLinkDiscovery['product_urls'])->toBe([
            'https://sklep.medreha.pl/pl/p/Product-A/101',
            'https://sklep.medreha.pl/pl/p/Product-B/102',
        ])
        ->and($productData['product_count'])->toBe(1)
        ->and($productData['products'][0]['external_product_id'])->toBe('102')
        ->and(Product::query()->count())->toBe(0);
});

it('can resume from full MedReha product-data JSON and import the selected product range', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/medreha/full-product-data.json', json_encode([
        'source' => 'medreha',
        'product_count' => 2,
        'products' => [
            medrehaPipelineImportPayload('201', 'Pipeline Product A', 'pipeline-product-a', 'PA-201'),
            medrehaPipelineImportPayload('202', 'Pipeline Product B', 'pipeline-product-b', 'PB-202'),
        ],
        'failed_urls' => [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $this->artisan('medreha:pipeline', [
        '--resume' => true,
        '--status' => 'active',
        '--limit' => '1',
        '--offset' => '1',
        '--no-images' => true,
        '--request-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Resuming from full product-data JSON. Discovery and crawl stages will be skipped.')
        ->expectsOutputToContain('Selected products for import: 1')
        ->expectsOutputToContain('Imported products: 1')
        ->assertSuccessful();

    expect(Product::query()->where('external_source', 'medreha')->count())->toBe(1)
        ->and(Product::query()->where('external_source', 'medreha')->where('external_id', '201')->exists())->toBeFalse();

    $product = Product::query()
        ->where('external_source', 'medreha')
        ->where('external_id', '202')
        ->firstOrFail();

    expect($product->name)->toBe('Pipeline Product B')
        ->and($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($product->variants()->count())->toBe(1)
        ->and($product->images()->count())->toBe(0);
});

function medrehaPipelineCategoryMenuFixture(): string
{
    return <<<'HTML'
        <html><body>
            <ul class="menu-list large standard">
                <li class="parent" id="hcategory_0">
                    <h3><a href="#"><span>Menu</span></a></h3>
                    <div class="submenu level1">
                        <ul class="level1">
                            <li id="hcategory_188" class="parent">
                                <h3><a href="/pl/c/ORTEZY-I-STABILIZATORY/188"><span>ORTEZY I STABILIZATORY</span></a></h3>
                                <div class="submenu level2">
                                    <ul class="level2">
                                        <li id="hcategory_145" class="parent">
                                            <h3><a href="/pl/c/PASY-ORTOPEDYCZNE/145"><span>PASY ORTOPEDYCZNE</span></a></h3>
                                            <div class="submenu level3">
                                                <ul class="level3">
                                                    <li id="hcategory_169"><h3><a href="/pl/c/PASY-NA-KREGOSLUP/169"><span>PASY NA KRĘGOSŁUP</span></a></h3></li>
                                                </ul>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </body></html>
    HTML;
}

/**
 * @param  list<array{0: string, 1: string}>  $products
 */
function medrehaPipelineProductListFixture(array $products): string
{
    $items = '';

    foreach ($products as [$url, $name]) {
        $items .= <<<HTML
            <div class="product s-grid-3 product-main-wrap">
                <a class="prodimage f-row" href="{$url}" title="{$name}"><img src="/userdata/public/fixture.webp" alt="{$name}"></a>
                <a class="prodname f-row" href="{$url}"><span>{$name}</span></a>
            </div>
        HTML;
    }

    return <<<HTML
        <html>
            <body class="shop_product_list">
                <div id="box_mainproducts">
                    <div class="products viewphot">{$items}</div>
                </div>
            </body>
        </html>
    HTML;
}

function medrehaPipelineProductPageFixture(string $canonicalUrl, string $externalProductId, string $name, string $sku): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} - MedReha</title>
                <meta name="description" content="Opis SEO {$name}">
                <meta itemprop="priceCurrency" content="PLN">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body class="shop_product product-{$externalProductId}">
                <div id="box_productfull">
                    <h1 itemprop="name">{$name}</h1>
                    <img itemprop="image" src="/userdata/public/gfx/{$externalProductId}/product.webp" alt="{$name}">
                    <p class="producer">Producent: <a href="/pl/producer/MedReha/1">MedReha</a></p>
                    <p class="availability">Dostępność: Towar dostępny od ręki</p>
                    <p class="shipping-time">Wysyłka w: 24 godziny</p>
                    <div class="price"><em class="main-price">79,90 zł</em></div>
                    <table class="product-params">
                        <tr><th>Kod produktu</th><td>{$sku}</td></tr>
                        <tr><th>Producent</th><td>MedReha</td></tr>
                    </table>
                </div>
                <div id="box_description"><div class="innerbox"><p>{$name} opis produktu z danymi medycznymi i ortezą.</p></div></div>
                <script>window.product_id = {$externalProductId};</script>
            </body>
        </html>
    HTML;
}

/**
 * @return array<string, mixed>
 */
function medrehaPipelineImportPayload(string $externalId, string $name, string $slug, string $sku): array
{
    return [
        'source' => 'medreha',
        'source_url' => 'https://sklep.medreha.pl/pl/p/'.$slug.'/'.$externalId,
        'canonical_url' => 'https://sklep.medreha.pl/pl/p/'.$slug.'/'.$externalId,
        'external_product_id' => $externalId,
        'slug' => $slug,
        'name' => $name,
        'brand' => ['name' => 'MedReha', 'slug' => 'medreha'],
        'category' => 'PASY NA KRĘGOSŁUP',
        'categories' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
        'seo_title' => 'MedReha | '.$name,
        'seo_description' => 'Opis SEO '.$name,
        'short_description' => 'Krótki opis '.$name,
        'description' => 'Opis '.$name,
        'description_html' => '<p>Opis '.$name.'</p>',
        'price_gross_amount' => 79.90,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Towar dostępny od ręki',
        'shipping_time' => '24 godziny',
        'sku' => $sku,
        'ean' => null,
        'attributes' => [],
        'images' => [
            ['url' => 'https://sklep.medreha.pl/environment/cache/images/productGfx_'.$externalId.'_500_500/product.jpg', 'alt' => $name],
        ],
        'tabs' => ['opis' => '<p>Opis '.$name.'</p>'],
        'variant_candidates' => [],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
        'source_category_name' => 'PASY NA KRĘGOSŁUP',
        'source_category_url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        'source_top_category_name' => 'ORTEZY I STABILIZATORY',
        'source_top_category_url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
        'source_category_path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
        'source_product_list_name' => $name,
    ];
}
