<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\Wojdak\WojdakProductImporter;
use App\Services\Wojdak\WojdakProductNormalizer;
use App\Services\Wojdak\WojdakProductPayloadExtractor;
use App\Services\Wojdak\WojdakVariantBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('extracts Wojdak shop product payload from a WooCommerce clothing page', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(
        wojdakShopFemaleBlouseHtml(),
        'https://sklep.wojdak.pl/produkt/bluza-e2002/'
    );

    expect($payload['external_id'])->toBe('bluza-e2002')
        ->and($payload['name'])->toBe('Bluza E2002')
        ->and($payload['parent_sku'])->toBe('M2002')
        ->and($payload['canonical_url'])->toBe('https://sklep.wojdak.pl/produkt/bluza-e2002/')
        ->and($payload['category_slug'])->toBe('bluzy-damskie')
        ->and($payload['category_url'])->toBe('https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/')
        ->and($payload['size_table_type'])->toBe('clothing')
        ->and($payload['images'])->toBe([
            'https://sklep.wojdak.pl/wp-content/uploads/2023/11/bluza2002.png',
            'https://sklep.wojdak.pl/wp-content/uploads/2024/03/M200201191906-1.jpg',
        ])
        ->and($payload['woocommerce_variations'])->toHaveCount(2)
        ->and($payload['woocommerce_variations'][0]['sku'])->toBe('M2002011919060343')
        ->and($payload['woocommerce_variations'][0]['price_gross_amount'])->toBe(13100)
        ->and($payload['woocommerce_variations'][0]['weight_grams'])->toBe(351)
        ->and(wojdakVariantAttributeSummary($payload['woocommerce_variations'][0]['attributes']))->toBe([
            ['code' => 'rozmiar_damski', 'name' => 'Rozmiar damski', 'value' => '34'],
            ['code' => 'wzrost_damski', 'name' => 'Wzrost damski', 'value' => '164 (161-166 cm)'],
            ['code' => 'kolory', 'name' => 'Kolor', 'value' => '196'],
            ['code' => 'tkaniny', 'name' => 'Tkanina', 'value' => 'Charlotte (50%BW 50%PES 180g/m2)'],
            ['code' => 'dlugosc_rekawa', 'name' => 'Długość rękawa', 'value' => 'Krótki'],
        ]);
});

it('builds variants from Wojdak WooCommerce variation JSON instead of generic size-table assumptions', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakShopFemaleBlouseHtml(), 'https://sklep.wojdak.pl/produkt/bluza-e2002/');
    $result = app(WojdakVariantBuilder::class)->build($payload);

    expect($result['warnings'])->toBe([])
        ->and($result['variants'])->toHaveCount(2)
        ->and($result['variants'][0]['external_variant_id'])->toBe('woocommerce-4847')
        ->and($result['variants'][0]['sku'])->toBe('M2002011919060343')
        ->and($result['variants'][0]['status'])->toBe('active')
        ->and($result['variants'][0]['stock_status'])->toBe('in_stock')
        ->and($result['variants'][0]['price_gross_amount'])->toBe(13100)
        ->and($result['variants'][1]['stock_status'])->toBe('out_of_stock');
});

it('extracts footwear variants from Wojdak shop pages', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(
        wojdakShopMaleShoeHtml(),
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-meskie-bw-104/'
    );
    $result = app(WojdakVariantBuilder::class)->build($payload);

    expect($payload['external_id'])->toBe('obuwie-medyczne-meskie-bw-104')
        ->and($payload['name'])->toBe('Obuwie medyczne męskie BW 104')
        ->and($payload['category_slug'])->toBe('obuwie-meskie')
        ->and($payload['size_table_type'])->toBe('footwear')
        ->and($result['warnings'])->toBe([])
        ->and($result['variants'])->toHaveCount(2)
        ->and(wojdakVariantAttributeSummary($result['variants'][0]['attributes']))->toBe([
            ['code' => 'rozmiar_obuwia_meskiego', 'name' => 'Rozmiar obuwia męskiego', 'value' => '40'],
        ]);
});

it('normalizes Wojdak shop products with Wojdak suffix in the product name', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakShopFemaleBlouseHtml(), 'https://sklep.wojdak.pl/produkt/bluza-e2002/');
    $normalized = app(WojdakProductNormalizer::class)->normalize($payload);

    expect($normalized['name'])->toBe('Bluza E2002 Wojdak')
        ->and($normalized['slug'])->toBe('bluza-e2002-wojdak')
        ->and($normalized['external_parent_sku'])->toBe('WOJDAK-M2002')
        ->and($normalized['variants'])->toHaveCount(2);
});

it('imports a Wojdak shop product as draft with scraped active priced variants, attributes, category and images', function (): void {
    Storage::fake('public');

    Http::fake([
        'https://sklep.wojdak.pl/wp-content/uploads/2023/11/bluza2002.png' => Http::response('fake-png-one', 200, ['Content-Type' => 'image/png']),
        'https://sklep.wojdak.pl/wp-content/uploads/2024/03/M200201191906-1.jpg' => Http::response('fake-jpeg-two', 200, ['Content-Type' => 'image/jpeg']),
        '*' => Http::response('', 404),
    ]);

    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakShopFemaleBlouseHtml(), 'https://sklep.wojdak.pl/produkt/bluza-e2002/');
    $normalized = app(WojdakProductNormalizer::class)->normalize($payload);
    $product = app(WojdakProductImporter::class)->import($normalized);

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->external_source)->toBe('wojdak')
        ->and($product->external_id)->toBe('bluza-e2002')
        ->and($product->name)->toBe('Bluza E2002 Wojdak')
        ->and($product->slug)->toBe('bluza-e2002-wojdak')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->variants)->toHaveCount(2)
        ->and($product->images)->toHaveCount(2)
        ->and($product->categories)->toHaveCount(1)
        ->and($product->categories->first()->slug)->toBe('bluzy-damskie');

    $firstVariant = $product->variants->first();

    expect($firstVariant->sku)->toBe('M2002011919060343')
        ->and($firstVariant->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($firstVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($firstVariant->price_net_amount)->toBe(VatRate::VAT_23->netFromGross(13100))
        ->and($firstVariant->package_weight_grams)->toBe(351)
        ->and($firstVariant->attributeValues->pluck('value')->all())->toBe([
            '34',
            '164 (161-166 cm)',
            '196',
            'Charlotte (50%BW 50%PES 180g/m2)',
            'Krótki',
        ]);

    expect(Attribute::query()->where('external_attribute_id', 'wojdak-rozmiar_damski')->exists())->toBeTrue()
        ->and(Attribute::query()->where('external_attribute_id', 'wojdak-wzrost_damski')->exists())->toBeTrue()
        ->and(Attribute::query()->where('external_attribute_id', 'wojdak-tkaniny')->exists())->toBeTrue();
});

it('keeps the old configured size-table fallback when no WooCommerce variation JSON is present', function (): void {
    $originalConfig = Config::get('wojdak');

    try {
        Config::set('wojdak', []);

        $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakLegacyFemaleBlouseHtml(), 'https://wojdak.pl/product/bluza-2002/');
        $result = app(WojdakVariantBuilder::class)->build($payload);

        expect($result['warnings'])->toBe([])
            ->and($result['variants'])->toHaveCount(24);
    } finally {
        Config::set('wojdak', $originalConfig);
    }
});

/**
 * @param  array<int, array<string, mixed>>  $attributes
 * @return array<int, array{code:string, name:string, value:string}>
 */
function wojdakVariantAttributeSummary(array $attributes): array
{
    return collect($attributes)
        ->map(fn (array $attribute): array => [
            'code' => (string) $attribute['code'],
            'name' => (string) $attribute['name'],
            'value' => (string) $attribute['value'],
        ])
        ->values()
        ->all();
}

function wojdakShopFemaleBlouseHtml(): string
{
    $variations = htmlspecialchars(json_encode([
        [
            'attributes' => [
                'attribute_pa_rozmiar-damski' => '34',
                'attribute_pa_wzrost-damski' => '164-161-166cm',
                'attribute_pa_kolory' => '196',
                'attribute_pa_tkaniny' => '6',
                'attribute_pa_dlugosc-rekawa' => 'krotki',
            ],
            'display_price' => 131.00,
            'display_regular_price' => 174.66,
            'image' => ['full_src' => 'https://sklep.wojdak.pl/wp-content/uploads/2024/03/M200201191906-1.jpg'],
            'is_in_stock' => true,
            'is_purchasable' => true,
            'max_qty' => 2,
            'sku' => 'M2002011919060343',
            'variation_id' => 4847,
            'variation_is_active' => true,
            'variation_is_visible' => true,
            'weight' => '0.351',
        ],
        [
            'attributes' => [
                'attribute_pa_rozmiar-damski' => '50',
                'attribute_pa_wzrost-damski' => '170-167-172cm',
                'attribute_pa_kolory' => '201',
                'attribute_pa_tkaniny' => '1',
                'attribute_pa_dlugosc-rekawa' => 'krotki',
            ],
            'display_price' => 123.62,
            'display_regular_price' => 164.82,
            'image' => ['full_src' => 'https://sklep.wojdak.pl/wp-content/uploads/2024/03/M200201191906-1.jpg'],
            'is_in_stock' => false,
            'is_purchasable' => true,
            'max_qty' => 0,
            'sku' => 'M2002011919060504',
            'variation_id' => 4848,
            'variation_is_active' => true,
            'variation_is_visible' => true,
            'weight' => '0.351',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return <<<HTML
        <!DOCTYPE html>
        <html lang="pl-PL">
        <head>
            <title>Bluza E2002 – Wojdak</title>
            <link rel="canonical" href="https://sklep.wojdak.pl/produkt/bluza-e2002/" />
        </head>
        <body class="single-product">
            <nav class="woocommerce-breadcrumb">
                <a href="https://sklep.wojdak.pl">Strona główna</a>
                <a href="https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/">Odzież medyczna</a>
                <a href="https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/">Odzież damska</a>
                Bluza E2002
            </nav>
            <div id="product-4846" class="product type-product product_cat-bluzy-damskie product_cat-odziez-damska product_cat-odziez-medyczna">
                <div class="woocommerce-product-gallery__image">
                    <a href="https://sklep.wojdak.pl/wp-content/uploads/2023/11/bluza2002.png">
                        <img data-large_image="https://sklep.wojdak.pl/wp-content/uploads/2023/11/bluza2002.png" alt="Bluza E2002">
                    </a>
                </div>
                <div class="summary entry-summary">
                    <h1 class="product_title entry-title">Bluza E2002</h1>
                    <div class="product_meta"><span class="sku_wrapper">SKU: <span class="sku">M2002</span></span></div>
                    <form class="variations_form cart" data-product_id="4846" data-product_variations="{$variations}">
                        <select name="attribute_pa_rozmiar-damski" data-attribute_name="attribute_pa_rozmiar-damski">
                            <option value="">Wybierz opcję</option><option value="34">34</option><option value="50">50</option>
                        </select>
                        <select name="attribute_pa_wzrost-damski" data-attribute_name="attribute_pa_wzrost-damski">
                            <option value="">Wybierz opcję</option><option value="164-161-166cm">164-161-166cm</option><option value="170-167-172cm">170-167-172cm</option>
                        </select>
                        <select name="attribute_pa_kolory" data-attribute_name="attribute_pa_kolory">
                            <option value="">Wybierz opcję</option><option value="196">196</option><option value="201">201</option>
                        </select>
                        <select name="attribute_pa_tkaniny" data-attribute_name="attribute_pa_tkaniny">
                            <option value="">Wybierz opcję</option><option value="6">Charlotte (50%BW 50%PES 180g/m2)</option><option value="1">Teredo (35%BW 65%PES 195g/m2)</option>
                        </select>
                        <select name="attribute_pa_dlugosc-rekawa" data-attribute_name="attribute_pa_dlugosc-rekawa">
                            <option value="">Wybierz opcję</option><option value="krotki">Krótki</option>
                        </select>
                    </form>
                </div>
                <div class="product-description">
                    <h2 class="product-description__heading">Opis</h2>
                    <p>bluza chirurgiczna</p>
                    <p>Ten model dostępny jest z krótkim rękawem.</p>
                </div>
            </div>
        </body>
        </html>
    HTML;
}

function wojdakShopMaleShoeHtml(): string
{
    $variations = htmlspecialchars(json_encode([
        [
            'attributes' => ['attribute_pa_rozmiar-obuwia-meskiego' => '40'],
            'display_price' => 160.52,
            'display_regular_price' => 178.35,
            'image' => ['full_src' => 'https://sklep.wojdak.pl/wp-content/uploads/2024/01/bw-104-c-1.jpg'],
            'is_in_stock' => true,
            'is_purchasable' => true,
            'max_qty' => 5,
            'sku' => 'BW104-40',
            'variation_id' => 5876,
            'variation_is_active' => true,
            'variation_is_visible' => true,
        ],
        [
            'attributes' => ['attribute_pa_rozmiar-obuwia-meskiego' => '41'],
            'display_price' => 160.52,
            'display_regular_price' => 178.35,
            'image' => ['full_src' => 'https://sklep.wojdak.pl/wp-content/uploads/2024/01/bw-104-c-1.jpg'],
            'is_in_stock' => true,
            'is_purchasable' => true,
            'max_qty' => 1,
            'sku' => 'BW104-41',
            'variation_id' => 5877,
            'variation_is_active' => true,
            'variation_is_visible' => true,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return <<<HTML
        <!DOCTYPE html>
        <html lang="pl-PL">
        <head>
            <title>Obuwie medyczne męskie BW 104 – Wojdak</title>
            <link rel="canonical" href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-meskie-bw-104/" />
        </head>
        <body class="single-product">
            <nav class="woocommerce-breadcrumb">
                <a href="https://sklep.wojdak.pl">Strona główna</a>
                <a href="https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/">Obuwie medyczne</a>
                <a href="https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-meskie/">Obuwie męskie</a>
                Obuwie medyczne męskie BW 104
            </nav>
            <div id="product-5875" class="product type-product product_cat-obuwie-medyczne product_cat-obuwie-meskie">
                <div class="woocommerce-product-gallery__image"><a href="https://sklep.wojdak.pl/wp-content/uploads/2024/01/bw-104-c-1.jpg"><img data-large_image="https://sklep.wojdak.pl/wp-content/uploads/2024/01/bw-104-c-1.jpg"></a></div>
                <h1 class="product_title entry-title">Obuwie medyczne męskie BW 104</h1>
                <div class="product_meta"><span class="sku_wrapper">SKU: <span class="sku">BW104</span></span></div>
                <form class="variations_form cart" data-product_id="5875" data-product_variations="{$variations}">
                    <select name="attribute_pa_rozmiar-obuwia-meskiego" data-attribute_name="attribute_pa_rozmiar-obuwia-meskiego">
                        <option value="">Wybierz opcję</option><option value="40">40</option><option value="41">41</option>
                    </select>
                </form>
                <div class="product-description"><p>Obuwie medyczne męskie.</p></div>
            </div>
        </body>
        </html>
    HTML;
}

function wojdakLegacyFemaleBlouseHtml(): string
{
    return <<<'HTML'
        <!DOCTYPE html>
        <html lang="pl-PL">
        <head>
            <title>Bluza 2002 - Wojdak</title>
            <link rel="canonical" href="https://wojdak.pl/product/bluza-2002/" />
        </head>
        <body>
            <main>
                <div class="container container--medium">
                    <ul class="ms-breadcrumbs">
                        <li><a href="https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/">« Powrót do listy kategorii</a></li>
                    </ul>
                </div>
                <div class="single-product__about">
                    <h1 class="single-product__about-title">Bluza 2002</h1>
                    <div class="single-product__about-text">
                        <p>Ten model dostępny jest z krótkim rękawem.</p>
                        <p>Odzież dostępna w tkaninach we wszystkich rozmiarach.</p>
                    </div>
                    <a href="https://wojdak.pl/wp-content/uploads/2023/05/Tabela-rozmiarow-odziez.pdf">Tabela</a>
                </div>
            </main>
        </body>
        </html>
    HTML;
}
