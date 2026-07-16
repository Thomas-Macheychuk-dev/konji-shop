<?php

use App\Services\Vaya\VayaProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls Vaya product-link discovery data and preserves multiple category paths', function (): void {
    $firstUrl = 'https://www.vaya.com.pl/pl/p/Product-A/101';
    $secondUrl = 'https://www.vaya.com.pl/pl/p/Product-B/102';

    Http::fake([
        $firstUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $firstUrl,
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
            price: '29.90',
            imageIds: ['1010'],
        )),
        $secondUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $secondUrl,
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
            price: '39.90',
            imageIds: ['1020'],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'vaya',
            'product_urls' => [$firstUrl, $secondUrl],
            'products' => [
                [
                    'url' => $firstUrl,
                    'name' => 'Product A',
                    'category_urls' => [
                        'https://www.vaya.com.pl/pl/c/Wkladki-na-modzele/132',
                        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
                    ],
                    'category_paths' => [
                        ['Wkładki ortopedyczne', 'Wkładki na modzele'],
                        ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
                    ],
                ],
                [
                    'url' => $secondUrl,
                    'name' => 'Product B',
                    'category_urls' => ['https://www.vaya.com.pl/pl/c/Termometry/112'],
                    'category_paths' => [['Produkty Medyczne', 'Akcesoria medyczne', 'Termometry']],
                ],
            ],
        ]);

    expect($result)->toMatchArray([
        'source' => 'vaya',
        'product_count' => 2,
        'source_product_url_count' => 2,
        'total_product_url_count' => 2,
        'offset' => 0,
        'failed_urls' => [],
        'stopped_early' => false,
        'stop_reason' => null,
    ])->and($result['products'][0]['source_category_paths'])->toBe([
        ['Wkładki ortopedyczne', 'Wkładki na modzele'],
        ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
    ])->and($result['products'][1]['source_category_path'])->toBe([
        'Produkty Medyczne',
        'Akcesoria medyczne',
        'Termometry',
    ]);
});

it('deduplicates Vaya source canonical and external product identifiers', function (): void {
    $canonicalUrl = 'https://www.vaya.com.pl/pl/p/Product-A/101';
    $canonicalCopyUrl = 'https://www.vaya.com.pl/pl/p/Product-A-copy/102';
    $duplicateIdUrl = 'https://www.vaya.com.pl/pl/p/Product-A-duplicate-id/103';

    Http::fake([
        $canonicalUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $canonicalUrl,
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
            price: '29.90',
            imageIds: ['1010'],
        )),
        $canonicalCopyUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $canonicalUrl,
            externalProductId: '102',
            name: 'Product A canonical copy',
            sku: 'A-102',
            price: '29.90',
            imageIds: ['1020'],
        )),
        $duplicateIdUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $duplicateIdUrl,
            externalProductId: '101',
            name: 'Product A ID copy',
            sku: 'A-103',
            price: '29.90',
            imageIds: ['1030'],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            $canonicalUrl,
            $canonicalUrl,
            $canonicalCopyUrl,
            $duplicateIdUrl,
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(2)
        ->and($result['skipped_duplicate_urls'][0]['reason'])->toBe('duplicate_source_url')
        ->and($result['skipped_duplicate_urls'][1]['reason'])->toBe('duplicate_canonical_url')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'][0]['external_product_id'])->toBe('101');
});

it('records failed Vaya product requests and stops after rate limiting', function (): void {
    $rateLimitedUrl = 'https://www.vaya.com.pl/pl/p/Rate-Limited/429';
    $nextUrl = 'https://www.vaya.com.pl/pl/p/Product-A/101';

    Http::fake([
        $rateLimitedUrl => Http::response('', 429),
        $nextUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $nextUrl,
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
            price: '29.90',
            imageIds: ['1010'],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(1, 0)
        ->crawlProductUrls([$rateLimitedUrl, $nextUrl]);

    expect($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([$rateLimitedUrl => 'HTTP 429'])
        ->and($result['failed_url_counts'])->toBe(['HTTP 429' => 1])
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['stop_reason'])->toBe('HTTP 429 rate limit or temporary block from Vaya');

    Http::assertSentCount(1);
});

it('runs the Vaya product-data command from saved product links and saves JSON', function (): void {
    Storage::fake('local');

    $url = 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577';
    $from = 'scrapers/vaya/tests/product-links.json';
    $save = 'scrapers/vaya/tests/product-data.json';
    $absoluteSave = storage_path('app/'.$save);

    @unlink($absoluteSave);

    Storage::disk('local')->put($from, json_encode([
        'source' => 'vaya',
        'product_urls' => [$url],
        'products' => [
            [
                'url' => $url,
                'name' => 'Termometr elektroniczny medyczny',
                'category_urls' => ['https://www.vaya.com.pl/pl/c/Termometry/112'],
                'category_paths' => [['Produkty Medyczne', 'Akcesoria medyczne', 'Termometry']],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    Http::fake([
        $url => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $url,
            externalProductId: '577',
            name: 'Termometr elektroniczny medyczny',
            sku: 'HK-902',
            price: '11.40',
            ean: '6932053201728',
            imageIds: ['4337'],
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('vaya:crawl-product-data', [
        '--from' => $from,
        '--save' => $save,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Source product URLs: 1')
        ->and($output)->toContain('Scraped products: 1')
        ->and($output)->toContain('External ID: 577')
        ->and($output)->toContain('Saved full product data to storage/app/'.$save)
        ->and(is_file($absoluteSave))->toBeTrue();

    $saved = json_decode((string) file_get_contents($absoluteSave), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('vaya')
        ->and($saved['product_count'])->toBe(1)
        ->and($saved['products'][0]['ean'])->toBe('6932053201728')
        ->and($saved['products'][0]['source_category_path'])->toBe([
            'Produkty Medyczne',
            'Akcesoria medyczne',
            'Termometry',
        ]);

    @unlink($absoluteSave);
});

it('supports limit and offset while crawling Vaya product data', function (): void {
    $firstUrl = 'https://www.vaya.com.pl/pl/p/Product-A/101';
    $secondUrl = 'https://www.vaya.com.pl/pl/p/Product-B/102';

    Http::fake([
        $firstUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $firstUrl,
            externalProductId: '101',
            name: 'Product A',
            sku: 'A-101',
            price: '29.90',
            imageIds: ['1010'],
        )),
        $secondUrl => Http::response(vayaCrawlerProductPageFixture(
            canonicalUrl: $secondUrl,
            externalProductId: '102',
            name: 'Product B',
            sku: 'B-102',
            price: '39.90',
            imageIds: ['1020'],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$firstUrl, $secondUrl], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe([$secondUrl])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('102');
});

/**
 * @param  array<int, string>  $imageIds
 */
function vayaCrawlerProductPageFixture(
    string $canonicalUrl,
    string $externalProductId,
    string $name,
    string $sku,
    string $price,
    ?string $ean = null,
    array $imageIds = [],
): string {
    $imageLinks = '';

    foreach ($imageIds as $imageId) {
        $imageLinks .= '<link itemprop="image" href="/userdata/public/gfx/'.$imageId.'/product-'.$imageId.'.png">';
    }

    $eanMeta = $ean === null ? '' : '<meta itemprop="gtin" content="'.$ean.'">';

    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} | Vaya Medical</title>
                <meta name="description" content="Opis SEO {$name}">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body class="shop_product">
                <div class="breadcrumbs">
                    <a href="/">Strona główna</a>
                    <a href="/pl/c/Termometry/112">Termometry</a>
                </div>
                <div id="box_productfull">
                    <div class="productimg">{$imageLinks}</div>
                    <div class="product-container">
                        <h1 itemprop="name">{$name}</h1>
                        <div class="manufacturer"><a class="brand">Vaya Medical</a></div>
                        <div class="availability">
                            <meta itemprop="sku" content="{$sku}">
                            {$eanMeta}
                            <div class="availability"><span class="second">duża ilość</span></div>
                            <div class="delivery"><span class="second">24 godziny</span></div>
                            <meta itemprop="brand" content="Vaya Medical">
                        </div>
                        <div itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                            <div class="price"><em class="main-price">{$price} zł</em><span itemprop="price">{$price}</span></div>
                            <meta itemprop="priceCurrency" content="PLN">
                            <link itemprop="availability" href="https://schema.org/InStock">
                            <input type="hidden" name="product_id" value="{$externalProductId}">
                        </div>
                    </div>
                </div>
                <div id="box_description"><div class="innerbox"><div itemprop="description"><p>Opis produktu medycznego {$name}.</p></div></div></div>
                <div id="box_productsafety"><div class="innerbox"><p>Posiada oznaczenie CE.</p></div></div>
            </body>
        </html>
    HTML;
}
