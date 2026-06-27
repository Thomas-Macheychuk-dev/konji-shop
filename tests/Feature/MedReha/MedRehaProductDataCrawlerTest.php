<?php

use App\Services\MedReha\MedRehaProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls MedReha product data from discovered product links and preserves category context', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            externalProductId: '501',
            name: 'Pas Lędźwiowy Gorset Ortopedyczny',
            sku: 'PAS-001',
        )),
        'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
            externalProductId: '670',
            name: 'Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
            sku: 'DBP-2253',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery(medRehaCrawlerProductLinksDiscoveryDataFixture());

    expect($result['source'])->toBe('medreha')
        ->and($result['product_count'])->toBe(2)
        ->and($result['source_product_url_count'])->toBe(2)
        ->and($result['total_product_url_count'])->toBe(2)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['products'][0])->toMatchArray([
            'source' => 'medreha',
            'external_product_id' => '501',
            'name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
            'sku' => 'PAS-001',
            'category' => 'PASY NA KRĘGOSŁUP',
            'source_category_name' => 'PASY NA KRĘGOSŁUP',
            'source_top_category_name' => 'ORTEZY I STABILIZATORY',
            'source_category_path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
        ]);
});

it('deduplicates MedReha crawled products by source URL canonical URL and external product ID', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/p/Product-A/101' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://sklep.medreha.pl/product-a-copy' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A/101',
            externalProductId: '102',
            name: 'Product A canonical copy',
            sku: 'A-102',
        )),
        'https://sklep.medreha.pl/pl/p/Product-A-duplicate-id/103' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A-duplicate-id/103',
            externalProductId: '101',
            name: 'Product A ID copy',
            sku: 'A-103',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://sklep.medreha.pl/pl/p/Product-A/101',
            'https://sklep.medreha.pl/pl/p/Product-A/101',
            'https://sklep.medreha.pl/product-a-copy',
            'https://sklep.medreha.pl/pl/p/Product-A-duplicate-id/103',
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(2)
        ->and($result['skipped_duplicate_urls'][0]['reason'])->toBe('duplicate_source_url')
        ->and($result['skipped_duplicate_urls'][1]['reason'])->toBe('duplicate_canonical_url')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'][0]['external_product_id'])->toBe('101');
});

it('records failed MedReha product requests and stops on rate limiting', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/p/Rate-Limited/429' => Http::response('', 429),
        'https://sklep.medreha.pl/pl/p/Product-A/101' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://sklep.medreha.pl/pl/p/Rate-Limited/429',
            'https://sklep.medreha.pl/pl/p/Product-A/101',
        ]);

    expect($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([
            'https://sklep.medreha.pl/pl/p/Rate-Limited/429' => 'HTTP 429',
        ])
        ->and($result['failed_url_counts'])->toBe(['HTTP 429' => 1])
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['stop_reason'])->toBe('HTTP 429 rate limit or temporary block from MedReha');
});

it('can use a saved MedReha product-link discovery JSON file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/medreha/product-links-test.json', json_encode(medRehaCrawlerProductLinksDiscoveryDataFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    Storage::disk('local')->delete('scrapers/medreha/full-product-data-test.json');

    Http::fake([
        'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            externalProductId: '501',
            name: 'Pas Lędźwiowy Gorset Ortopedyczny',
            sku: 'PAS-001',
        )),
        'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('medreha:crawl-product-data', [
        '--from' => 'scrapers/medreha/product-links-test.json',
        '--save' => 'scrapers/medreha/full-product-data-test.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Storage::disk('local')->get('scrapers/medreha/full-product-data-test.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['external_product_id'])->toBe('501')
        ->and($decoded['failed_url_counts'])->toBe(['HTTP 403' => 1]);
});

it('can print crawled MedReha product data as JSON', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/p/Product-A/101' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('medreha:crawl-product-data', [
        '--url' => ['https://sklep.medreha.pl/pl/p/Product-A/101'],
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('medreha')
        ->and($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['name'])->toBe('Product A')
        ->and($decoded['products'][0]['price_gross_amount'])->toBe(79.90);
});

it('supports limit and offset while crawling MedReha product data', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/p/Product-A/101' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://sklep.medreha.pl/pl/p/Product-B/102' => Http::response(medRehaCrawlerProductPageDataFixture(
            canonicalUrl: 'https://sklep.medreha.pl/pl/p/Product-B/102',
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://sklep.medreha.pl/pl/p/Product-A/101',
            'https://sklep.medreha.pl/pl/p/Product-B/102',
        ], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe(['https://sklep.medreha.pl/pl/p/Product-B/102'])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('102');
});

/**
 * @return array<string, mixed>
 */
function medRehaCrawlerProductLinksDiscoveryDataFixture(): array
{
    return [
        'source' => 'medreha',
        'product_urls' => [
            'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
        ],
        'products' => [
            [
                'url' => 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
                'name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
                'category_name' => 'PASY NA KRĘGOSŁUP',
                'category_url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
                'top_category_name' => 'ORTEZY I STABILIZATORY',
                'top_category_url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
                'category_path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
            ],
            [
                'url' => 'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
                'name' => 'Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
                'category_name' => 'CIŚNIENIOMIERZE',
                'category_url' => 'https://sklep.medreha.pl/pl/c/CISNIENIOMIERZE/173',
                'top_category_name' => 'SPRZĘT MEDYCZNY',
                'top_category_url' => 'https://sklep.medreha.pl/sprzet-medyczny',
                'category_path' => ['SPRZĘT MEDYCZNY', 'CIŚNIENIOMIERZE'],
            ],
        ],
    ];
}

function medRehaCrawlerProductPageDataFixture(string $canonicalUrl, string $externalProductId, string $name, string $sku): string
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
                <div class="breadcrumbs"><a href="/">Strona główna</a><a href="/pl/c/PASY-NA-KREGOSLUP/169">PASY NA KRĘGOSŁUP</a><span>{$name}</span></div>
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
