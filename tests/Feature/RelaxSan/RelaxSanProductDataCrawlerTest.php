<?php

use App\Services\RelaxSan\RelaxSanProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls RelaxSan product data from discovered product links and preserves category context', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/pl/p/Product-A/101' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://relaxsansklep.pl/pl/p/Product-B/102' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-B/102',
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery(relaxSanCrawlerProductLinksDiscoveryDataFixture());

    expect($result)->toMatchArray([
        'source' => 'relaxsan',
        'product_count' => 2,
        'source_product_url_count' => 2,
        'total_product_url_count' => 2,
        'failed_urls' => [],
        'stopped_early' => false,
    ])
        ->and($result['products'][0])->toMatchArray([
            'external_product_id' => '101',
            'name' => 'Product A',
            'source_category_name' => 'Podkolanówki uciskowe profilaktyczne',
            'source_top_category_name' => 'Przeciwżylakowe',
            'source_category_path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
        ])
        ->and($result['products'][1]['external_product_id'])->toBe('102');
});

it('deduplicates RelaxSan products by source URL, canonical URL, and external product ID', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/pl/p/Product-A/101' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://relaxsansklep.pl/pl/p/Product-A-Copy/102' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '102',
            name: 'Product A canonical copy',
            sku: 'A-102',
        )),
        'https://relaxsansklep.pl/pl/p/Product-A-Duplicate-Id/103' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A-Duplicate-Id/103',
            externalProductId: '101',
            name: 'Product A ID copy',
            sku: 'A-103',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://relaxsansklep.pl/pl/p/Product-A/101',
            'https://relaxsansklep.pl/pl/p/Product-A/101',
            'https://relaxsansklep.pl/pl/p/Product-A-Copy/102',
            'https://relaxsansklep.pl/pl/p/Product-A-Duplicate-Id/103',
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(2)
        ->and($result['skipped_duplicate_urls'][0]['reason'])->toBe('duplicate_source_url')
        ->and($result['skipped_duplicate_urls'][1]['reason'])->toBe('duplicate_canonical_url')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'][0]['external_product_id'])->toBe('101');
});

it('records failed RelaxSan product requests and stops on rate limiting', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/pl/p/Rate-Limited/429' => Http::response('', 429),
        'https://relaxsansklep.pl/pl/p/Product-A/101' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://relaxsansklep.pl/pl/p/Rate-Limited/429',
            'https://relaxsansklep.pl/pl/p/Product-A/101',
        ]);

    expect($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([
            'https://relaxsansklep.pl/pl/p/Rate-Limited/429' => 'HTTP 429',
        ])
        ->and($result['failed_url_counts'])->toBe(['HTTP 429' => 1])
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['stop_reason'])->toBe('HTTP 429 rate limit or temporary block from RelaxSan');
});

it('can use a saved RelaxSan product-link discovery JSON file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/relaxsan/product-links-test.json', json_encode(relaxSanCrawlerProductLinksDiscoveryDataFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    Storage::disk('local')->delete('scrapers/relaxsan/full-product-data-test.json');

    Http::fake([
        'https://relaxsansklep.pl/pl/p/Product-A/101' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://relaxsansklep.pl/pl/p/Product-B/102' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('relaxsan:crawl-product-data', [
        '--from' => 'scrapers/relaxsan/product-links-test.json',
        '--save' => 'scrapers/relaxsan/full-product-data-test.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Storage::disk('local')->get('scrapers/relaxsan/full-product-data-test.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['external_product_id'])->toBe('101')
        ->and($decoded['failed_url_counts'])->toBe(['HTTP 403' => 1]);
});

it('can print crawled RelaxSan product data as JSON', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/pl/p/Product-A/101' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('relaxsan:crawl-product-data', [
        '--url' => ['https://relaxsansklep.pl/pl/p/Product-A/101'],
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('relaxsan')
        ->and($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['name'])->toBe('Product A')
        ->and($decoded['products'][0]['price_gross_amount'])->toBe(79.90);
});

it('supports limit and offset while crawling RelaxSan product data', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/pl/p/Product-A/101' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-A/101',
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
        )),
        'https://relaxsansklep.pl/pl/p/Product-B/102' => Http::response(relaxSanCrawlerProductPageDataFixture(
            canonicalUrl: 'https://relaxsansklep.pl/pl/p/Product-B/102',
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://relaxsansklep.pl/pl/p/Product-A/101',
            'https://relaxsansklep.pl/pl/p/Product-B/102',
        ], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe(['https://relaxsansklep.pl/pl/p/Product-B/102'])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('102');
});

/**
 * @return array<string, mixed>
 */
function relaxSanCrawlerProductLinksDiscoveryDataFixture(): array
{
    return [
        'source' => 'relaxsan',
        'product_urls' => [
            'https://relaxsansklep.pl/pl/p/Product-A/101',
            'https://relaxsansklep.pl/pl/p/Product-B/102',
        ],
        'products' => [
            [
                'url' => 'https://relaxsansklep.pl/pl/p/Product-A/101',
                'name' => 'Product A',
                'category_name' => 'Podkolanówki uciskowe profilaktyczne',
                'category_url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
                'top_category_name' => 'Przeciwżylakowe',
                'top_category_url' => 'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
                'category_path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
            ],
            [
                'url' => 'https://relaxsansklep.pl/pl/p/Product-B/102',
                'name' => 'Product B',
                'category_name' => 'Bielizna ciążowa',
                'category_url' => 'https://relaxsansklep.pl/bielizna-ciazowa',
                'top_category_name' => 'W ciąży',
                'top_category_url' => 'https://relaxsansklep.pl/w-ciazy',
                'category_path' => ['W ciąży', 'Bielizna ciążowa'],
            ],
        ],
    ];
}

function relaxSanCrawlerProductPageDataFixture(string $canonicalUrl, string $externalProductId, string $name, string $sku): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} | Sklep RelaxSan</title>
                <meta name="description" content="Opis SEO {$name}">
                <meta itemprop="priceCurrency" content="PLN">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body id="shop_product{$externalProductId}" class="shop_product">
                <ul class="path"><a href="/">Strona główna</a><a href="/podkolanowki-uciskowe-profilaktyczne">Podkolanówki uciskowe profilaktyczne</a><span>{$name}</span></ul>
                <div id="box_productfull">
                    <h1 itemprop="name">{$name}</h1>
                    <div class="productimg"><img itemprop="image" src="/userdata/public/gfx/{$externalProductId}/product.webp" alt="{$name}"></div>
                    <a href="/pl/producer/RelaxSan/40">RelaxSan</a>
                    <div class="availability"><span class="first">Dostępność:</span><span class="second">Dostępny</span></div>
                    <div class="delivery"><span class="first">Wysyłka w:</span><span class="second">48 godzin</span></div>
                    <div class="price"><em class="main-price">79,90 zł</em></div>
                    <div class="code"><em>Kod produktu:</em><span>{$sku}</span></div>
                </div>
                <div id="box_description"><div class="resetcss" itemprop="description"><p>{$name} opis produktu kompresyjnego.</p></div></div>
            </body>
        </html>
        HTML;
}
