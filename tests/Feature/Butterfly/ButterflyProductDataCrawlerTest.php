<?php

use App\Services\Butterfly\ButterflyProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls Butterfly product data from discovered product links and preserves category context', function (): void {
    Http::fake([
        'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            externalProductId: '501',
            name: 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
            sku: 'ORT-78',
        )),
        'https://butterfly-mag.com/pl/p/KOMPLET-MAGNETYCZNY-FORTE/670' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/KOMPLET-MAGNETYCZNY-FORTE/670',
            externalProductId: '670',
            name: 'KOMPLET MAGNETYCZNY FORTE',
            sku: 'FORTE-95',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery(butterflyCrawlerProductLinksDiscoveryDataFixture());

    expect($result['source'])->toBe('butterfly')
        ->and($result['product_count'])->toBe(2)
        ->and($result['source_product_url_count'])->toBe(2)
        ->and($result['total_product_url_count'])->toBe(2)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['products'][0])->toMatchArray([
            'source' => 'butterfly',
            'external_product_id' => '501',
            'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
            'sku' => 'ORT-78',
            'category' => 'Magnetyczne poduszki ortopedyczne',
            'source_category_name' => 'Magnetyczne poduszki ortopedyczne',
            'source_top_category_name' => 'Magnetoterapia',
            'source_category_path' => ['Magnetoterapia', 'Poduszki', 'Magnetyczne poduszki ortopedyczne'],
        ]);
});

it('deduplicates Butterfly crawled products by source URL canonical URL and external product ID', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/p/Product-A/101' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://butterfly-mag.com/product-a-copy' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-A/101',
            externalProductId: '102',
            name: 'Product A canonical copy',
            sku: 'A-102',
        )),
        'https://butterfly-mag.com/pl/p/Product-A-duplicate-id/103' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-A-duplicate-id/103',
            externalProductId: '101',
            name: 'Product A ID copy',
            sku: 'A-103',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://butterfly-mag.com/pl/p/Product-A/101',
            'https://butterfly-mag.com/pl/p/Product-A/101',
            'https://butterfly-mag.com/product-a-copy',
            'https://butterfly-mag.com/pl/p/Product-A-duplicate-id/103',
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(2)
        ->and($result['skipped_duplicate_urls'][0]['reason'])->toBe('duplicate_source_url')
        ->and($result['skipped_duplicate_urls'][1]['reason'])->toBe('duplicate_canonical_url')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'][0]['external_product_id'])->toBe('101');
});

it('records failed Butterfly product requests and stops on rate limiting', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/p/Rate-Limited/429' => Http::response('', 429),
        'https://butterfly-mag.com/pl/p/Product-A/101' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://butterfly-mag.com/pl/p/Rate-Limited/429',
            'https://butterfly-mag.com/pl/p/Product-A/101',
        ]);

    expect($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([
            'https://butterfly-mag.com/pl/p/Rate-Limited/429' => 'HTTP 429',
        ])
        ->and($result['failed_url_counts'])->toBe(['HTTP 429' => 1])
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['stop_reason'])->toBe('HTTP 429 rate limit or temporary block from Butterfly');
});

it('can use a saved Butterfly product-link discovery JSON file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/butterfly/product-links-test.json', json_encode(butterflyCrawlerProductLinksDiscoveryDataFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    Storage::disk('local')->delete('scrapers/butterfly/full-product-data-test.json');

    Http::fake([
        'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            externalProductId: '501',
            name: 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
            sku: 'ORT-78',
        )),
        'https://butterfly-mag.com/pl/p/KOMPLET-MAGNETYCZNY-FORTE/670' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('butterfly:crawl-product-data', [
        '--from' => 'scrapers/butterfly/product-links-test.json',
        '--save' => 'scrapers/butterfly/full-product-data-test.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Storage::disk('local')->get('scrapers/butterfly/full-product-data-test.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['external_product_id'])->toBe('501')
        ->and($decoded['failed_url_counts'])->toBe(['HTTP 403' => 1]);
});

it('can print crawled Butterfly product data as JSON', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/p/Product-A/101' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('butterfly:crawl-product-data', [
        '--url' => ['https://butterfly-mag.com/pl/p/Product-A/101'],
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('butterfly')
        ->and($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['name'])->toBe('Product A')
        ->and($decoded['products'][0]['price_gross_amount'])->toBe(79.90);
});

it('supports limit and offset while crawling Butterfly product data', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/p/Product-A/101' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://butterfly-mag.com/pl/p/Product-B/102' => Http::response(butterflyCrawlerProductPageDataFixture(
            canonicalUrl: 'https://butterfly-mag.com/pl/p/Product-B/102',
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://butterfly-mag.com/pl/p/Product-A/101',
            'https://butterfly-mag.com/pl/p/Product-B/102',
        ], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe(['https://butterfly-mag.com/pl/p/Product-B/102'])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('102');
});

/**
 * @return array<string, mixed>
 */
function butterflyCrawlerProductLinksDiscoveryDataFixture(): array
{
    return [
        'source' => 'butterfly',
        'product_urls' => [
            'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            'https://butterfly-mag.com/pl/p/KOMPLET-MAGNETYCZNY-FORTE/670',
        ],
        'products' => [
            [
                'url' => 'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
                'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
                'category_name' => 'Magnetyczne poduszki ortopedyczne',
                'category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
                'top_category_name' => 'Magnetoterapia',
                'top_category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
                'category_path' => ['Magnetoterapia', 'Poduszki', 'Magnetyczne poduszki ortopedyczne'],
            ],
            [
                'url' => 'https://butterfly-mag.com/pl/p/KOMPLET-MAGNETYCZNY-FORTE/670',
                'name' => 'KOMPLET MAGNETYCZNY FORTE',
                'category_name' => 'Materace i podkłady magnetyczne',
                'category_url' => 'https://butterfly-mag.com/pl/c/Materace-i-podklady-magnetyczne/32',
                'top_category_name' => 'Magnetoterapia',
                'top_category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
                'category_path' => ['Magnetoterapia', 'Materace i podkłady magnetyczne'],
            ],
        ],
    ];
}

function butterflyCrawlerProductPageDataFixture(string $canonicalUrl, string $externalProductId, string $name, string $sku): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} - Butterfly</title>
                <meta name="description" content="Opis SEO {$name}">
                <meta itemprop="priceCurrency" content="PLN">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body class="shop_product product-{$externalProductId}">
                <div class="breadcrumbs"><a href="/">Strona główna</a><a href="/pl/c/Magnetyczne-poduszki-ortopedyczne/18">Magnetyczne poduszki ortopedyczne</a><span>{$name}</span></div>
                <div id="box_productfull">
                    <h1 itemprop="name">{$name}</h1>
                    <img itemprop="image" src="/userdata/public/gfx/{$externalProductId}/product.webp" alt="{$name}">
                    <p class="producer">Producent: <a href="/pl/producer/Butterfly/1">Butterfly</a></p>
                    <p class="availability">Dostępność: Towar dostępny od ręki</p>
                    <p class="shipping-time">Wysyłka w: 24 godziny</p>
                    <div class="price"><em class="main-price">79,90 zł</em></div>
                    <table class="product-params">
                        <tr><th>Kod produktu</th><td>{$sku}</td></tr>
                        <tr><th>Producent</th><td>Butterfly</td></tr>
                    </table>
                </div>
                <div id="box_description"><div class="innerbox"><p>{$name} opis produktu z danymi medycznymi i ortezą.</p></div></div>
                <script>window.product_id = {$externalProductId};</script>
            </body>
        </html>
    HTML;
}
