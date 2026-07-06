<?php

use App\Services\Antar\AntarProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts Antar WooCommerce product detail data', function (): void {
    $html = antarProductPageFixture();

    $result = app(AntarProductScraper::class)->extract(
        $html,
        'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        [
            'url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
            'external_id' => 'accutex-orteza-stawu-lokciowego-2986',
            'slug' => 'accutex-orteza-stawu-lokciowego-2986',
            'source_category_name' => 'Ortezy stawu łokciowego',
            'source_category_url' => 'https://antar.net/produkty/ortopedia/ortezy-konczyn-gornych/ortezy-stawu-lokciowego/',
            'source_top_category_name' => 'Ortopedia',
            'source_category_path' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
        ],
    );

    expect($result)->toMatchArray([
        'source' => 'antar',
        'source_url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        'canonical_url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        'external_product_id' => 'accutex-orteza-stawu-lokciowego-2986',
        'slug' => 'accutex-orteza-stawu-lokciowego-2986',
        'name' => 'Orteza stawu łokciowego AccuTex 2986',
        'brand' => 'OPPO MEDICAL INC',
        'category' => 'Ortezy stawu łokciowego',
        'categories' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
        'sku' => '2986',
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'is_medical_device' => true,
        'source_product_external_id' => 'accutex-orteza-stawu-lokciowego-2986',
        'source_category_name' => 'Ortezy stawu łokciowego',
        'source_top_category_name' => 'Ortopedia',
        'source_category_path' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
    ])
        ->and($result['short_description'])->toContain('zapewnia stabilność')
        ->and($result['description'])->toContain('Cechy produktu')
        ->and($result['description_html'])->toContain('<h2>Opis</h2>')
        ->and($result['images'])->toHaveCount(2)
        ->and($result['images'][0])->toMatchArray([
            'url' => 'https://antar.net/wp-content/uploads/2024/01/2986.jpg',
            'alt' => 'Orteza stawu łokciowego AccuTex 2986',
            'position' => 1,
        ])
        ->and($result['documents'])->toHaveCount(1)
        ->and($result['documents'][0])->toMatchArray([
            'url' => 'https://antar.net/wp-content/uploads/2024/01/instrukcja-2986.pdf',
            'label' => 'Instrukcja',
            'extension' => 'pdf',
        ])
        ->and($result['tabs'])->not->toBeEmpty()
        ->and($result['attributes'])->toContain([
            'code' => 'catalogue_number',
            'label' => 'Numer katalogowy',
            'value' => '2986',
            'slug' => '2986',
        ]);
});

it('records failed Antar product requests', function (): void {
    Http::fake([
        'https://antar.net/produkt/missing-product/' => Http::response('', 503),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://antar.net/produkt/missing-product/');

    expect($result['name'])->toBe('')
        ->and($result['failed_urls'])->toBe([
            'https://antar.net/produkt/missing-product/' => 'HTTP 503',
        ])
        ->and($result['warnings'])->toContain('Unable to fetch Antar product page.');
});

if (! function_exists('antarProductPageFixture')) {
    function antarProductPageFixture(string $canonicalUrl = 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/'): string
    {
        return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Orteza stawu łokciowego AccuTex 2986 - Antar</title>
                <link rel="canonical" href="{$canonicalUrl}" />
                <meta name="description" content="Orteza stawu łokciowego AccuTex 2986 zapewnia stabilność dzięki strukturze w kształcie litery X." />
                <meta property="og:image" content="https://antar.net/wp-content/uploads/2024/01/2986-og.jpg" />
            </head>
            <body>
                <nav class="woocommerce-breadcrumb">
                    <a href="https://antar.net/">Strona główna</a> /
                    <a href="https://antar.net/produkty/ortopedia/">Ortopedia</a> /
                    <a href="https://antar.net/produkty/ortopedia/ortezy-konczyn-gornych/">Ortezy kończyn górnych</a> /
                    Orteza stawu łokciowego AccuTex 2986
                </nav>
                <main class="product">
                    <div class="woocommerce-product-gallery__image">
                        <a href="https://antar.net/wp-content/uploads/2024/01/2986.jpg">
                            <img class="wp-post-image" src="https://antar.net/wp-content/uploads/2024/01/2986-600x600.jpg" alt="Orteza stawu łokciowego AccuTex 2986" />
                        </a>
                    </div>
                    <div class="woocommerce-product-gallery__image">
                        <img src="/wp-content/uploads/2024/01/2986-detail.jpg" alt="Detal ortezy" />
                    </div>
                    <div class="summary entry-summary">
                        <h1 class="product_title entry-title">Orteza stawu łokciowego AccuTex 2986</h1>
                        <div class="product_meta">
                            <span class="sku_wrapper">Numer katalogowy: <span class="sku">2986</span></span>
                            <span class="posted_in">Kategorie:
                                <a href="https://antar.net/produkty/ortopedia/">Ortopedia</a>,
                                <a href="https://antar.net/produkty/ortopedia/ortezy-konczyn-gornych/">Ortezy kończyn górnych</a>,
                                <a href="https://antar.net/produkty/ortopedia/ortezy-konczyn-gornych/ortezy-stawu-lokciowego/">Ortezy stawu łokciowego</a>
                            </span>
                        </div>
                        <div class="woocommerce-product-details__short-description">
                            <p>Orteza stawu łokciowego AccuTex 2986 zapewnia stabilność dzięki strukturze w kształcie litery X.</p>
                        </div>
                    </div>
                    <div class="woocommerce-tabs wc-tabs-wrapper">
                        <div id="tab-description" class="woocommerce-Tabs-panel woocommerce-Tabs-panel--description panel entry-content wc-tab">
                            <h2>Opis</h2>
                            <p>Orteza AccuTex 2986 to nowoczesny produkt z linii AccuTex.</p>
                            <h3>Cechy produktu:</h3>
                            <ul>
                                <li>Struktura w kształcie litery X</li>
                                <li>Wewnętrzne wkładki silikonowe</li>
                            </ul>
                            <p>Producent: OPPO MEDICAL INC</p>
                            <p>Upoważniony przedstawiciel: MT Promedt Consulting GmbH</p>
                            <p>Podmiot prowadzący reklamę: Antar Spółka Jawna</p>
                        </div>
                        <div id="tab-size-table" class="woocommerce-Tabs-panel panel entry-content wc-tab">
                            <h2>TABELA ROZMIARÓW</h2>
                            <table>
                                <tr><th>ROZMIAR</th><td>S</td><td>M</td><td>L</td></tr>
                                <tr><th>OBWÓD ŁOKCIA (CM)</th><td>21-23</td><td>23-25</td><td>25-27</td></tr>
                            </table>
                        </div>
                        <div id="tab-documents" class="woocommerce-Tabs-panel panel entry-content wc-tab">
                            <h2>Dokumenty do pobrania</h2>
                            <a href="/wp-content/uploads/2024/01/instrukcja-2986.pdf">Instrukcja</a>
                        </div>
                    </div>
                </main>
                <footer>TO JEST WYRÓB MEDYCZNY. UŻYWAJ GO ZGODNIE Z INSTRUKCJĄ UŻYWANIA LUB ETYKIETĄ.</footer>
            </body>
        </html>
        HTML;
    }
}
