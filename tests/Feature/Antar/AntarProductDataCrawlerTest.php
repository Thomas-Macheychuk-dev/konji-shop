<?php

use App\Services\Antar\AntarProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls Antar product data from product-link discovery with category context', function (): void {
    Http::fake([
        'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/' => Http::response(antarProductPageFixture()),
        'https://antar.net/produkt/blocked-product/' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery(antarProductLinksDiscoveryFixture());

    expect($result['source'])->toBe('antar')
        ->and($result['product_count'])->toBe(1)
        ->and($result['source_product_url_count'])->toBe(2)
        ->and($result['total_product_url_count'])->toBe(2)
        ->and($result['failed_url_counts'])->toBe(['HTTP 403' => 1])
        ->and($result['products'][0])->toMatchArray([
            'source' => 'antar',
            'external_product_id' => 'accutex-orteza-stawu-lokciowego-2986',
            'name' => 'Orteza stawu łokciowego AccuTex 2986',
            'sku' => '2986',
            'category' => 'Ortezy stawu łokciowego',
            'source_category_name' => 'Ortezy stawu łokciowego',
            'source_top_category_name' => 'Ortopedia',
            'source_category_path' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
        ]);
});

it('deduplicates Antar products by source URL canonical URL and external product ID', function (): void {
    Http::fake([
        'https://antar.net/produkt/product-a/' => Http::response(antarProductPageFixture(
            canonicalUrl: 'https://antar.net/produkt/product-a/',
        )),
        'https://antar.net/produkt/product-a-copy/' => Http::response(antarProductPageFixture(
            canonicalUrl: 'https://antar.net/produkt/product-a/',
        )),
        'https://antar.net/produkt/product-a-duplicate-id/' => Http::response(antarProductPageFixture(
            canonicalUrl: 'https://antar.net/produkt/product-a-duplicate-id/',
        )),
        '*' => Http::response('', 404),
    ]);

    $discovery = [
        'source' => 'antar',
        'product_urls' => [
            'https://antar.net/produkt/product-a/',
            'https://antar.net/produkt/product-a/',
            'https://antar.net/produkt/product-a-copy/',
            'https://antar.net/produkt/product-a-duplicate-id/',
        ],
        'products' => [
            [
                'source' => 'antar',
                'url' => 'https://antar.net/produkt/product-a/',
                'external_id' => 'product-a',
                'slug' => 'product-a',
            ],
            [
                'source' => 'antar',
                'url' => 'https://antar.net/produkt/product-a-copy/',
                'external_id' => 'product-a-copy',
                'slug' => 'product-a-copy',
            ],
            [
                'source' => 'antar',
                'url' => 'https://antar.net/produkt/product-a-duplicate-id/',
                'external_id' => 'product-a',
                'slug' => 'product-a-duplicate-id',
            ],
        ],
    ];

    $result = app(AntarProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery($discovery);

    expect($result['product_count'])->toBe(1)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(2)
        ->and($result['skipped_duplicate_urls'][0]['reason'])->toBe('duplicate_source_url')
        ->and($result['skipped_duplicate_urls'][1]['reason'])->toBe('duplicate_canonical_url')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'][0]['external_product_id'])->toBe('product-a');
});

it('can save crawled Antar product data from a saved product-link discovery JSON file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/antar/product-links-test.json', json_encode(antarProductLinksDiscoveryFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    Storage::disk('local')->delete('scrapers/antar/product-data-test.json');

    Http::fake([
        'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/' => Http::response(antarProductPageFixture()),
        'https://antar.net/produkt/blocked-product/' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('antar:crawl-product-data', [
        '--from' => 'scrapers/antar/product-links-test.json',
        '--save' => 'scrapers/antar/product-data-test.json',
        '--request-delay-ms' => '0',
        '--no-progress' => true,
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Storage::disk('local')->get('scrapers/antar/product-data-test.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('antar')
        ->and($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['external_product_id'])->toBe('accutex-orteza-stawu-lokciowego-2986')
        ->and($decoded['failed_url_counts'])->toBe(['HTTP 403' => 1]);
});

it('supports limit and offset while crawling Antar product data', function (): void {
    Http::fake([
        'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/' => Http::response(antarProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://antar.net/produkt/blocked-product/',
            'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
        ], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe(['https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/'])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('accutex-orteza-stawu-lokciowego-2986');
});

/**
 * @return array<string, mixed>
 */
function antarProductLinksDiscoveryFixture(): array
{
    return [
        'source' => 'antar',
        'product_urls' => [
            'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
            'https://antar.net/produkt/blocked-product/',
        ],
        'products' => [
            [
                'source' => 'antar',
                'url' => 'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
                'external_id' => 'accutex-orteza-stawu-lokciowego-2986',
                'slug' => 'accutex-orteza-stawu-lokciowego-2986',
            ],
            [
                'source' => 'antar',
                'url' => 'https://antar.net/produkt/blocked-product/',
                'external_id' => 'blocked-product',
                'slug' => 'blocked-product',
            ],
        ],
        'category_results' => [
            [
                'source' => 'antar',
                'external_category_id' => 'ortopedia/ortezy-konczyn-gornych/ortezy-stawu-lokciowego',
                'name' => 'Ortezy stawu łokciowego',
                'url' => 'https://antar.net/produkty/ortopedia/ortezy-konczyn-gornych/ortezy-stawu-lokciowego/',
                'category_path' => ['Ortopedia', 'Ortezy kończyn górnych', 'Ortezy stawu łokciowego'],
                'product_urls' => [
                    'https://antar.net/produkt/accutex-orteza-stawu-lokciowego-2986/',
                ],
            ],
            [
                'source' => 'antar',
                'external_category_id' => 'rehabilitacja',
                'name' => 'Rehabilitacja',
                'url' => 'https://antar.net/produkty/rehabilitacja/',
                'category_path' => ['Rehabilitacja'],
                'product_urls' => [
                    'https://antar.net/produkt/blocked-product/',
                ],
            ],
        ],
    ];
}

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
