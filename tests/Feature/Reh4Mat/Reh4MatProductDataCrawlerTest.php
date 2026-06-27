<?php

use App\Services\Reh4Mat\Reh4MatProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls Reh4Mat product data from discovered product links and skips individual 403 pages', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07',
            externalProductId: '341026',
            name: 'AM-SP-07',
            sku: 'AM-SP-07',
        )),
        'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery(reh4MatCrawlerProductLinksDiscoveryFixture());

    expect($result['source'])->toBe('reh4mat')
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'])->toHaveCount(1)
        ->and($result['products'][0]['name'])->toBe('AM-SP-07')
        ->and($result['products'][0]['category'])->toBe('Ortezy dłoni')
        ->and($result['products'][0]['categories'])->toBe(['KOŃCZYNA GÓRNA', 'Ortezy dłoni'])
        ->and($result['products'][0]['source_category_name'])->toBe('Ortezy dłoni')
        ->and($result['skipped_failed_products'])->toBe([
            [
                'url' => 'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/',
                'reason' => 'HTTP 403',
            ],
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/' => 'HTTP 403',
        ])
        ->and($result['failed_url_counts'])->toBe(['HTTP 403' => 1])
        ->and($result['stopped_early'])->toBeFalse();
});

it('deduplicates Reh4Mat products by canonical URL and external product ID', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07',
            externalProductId: '341026',
            name: 'AM-SP-07',
            sku: 'AM-SP-07',
        )),
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07-copy/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07-copy',
            externalProductId: '341026',
            name: 'AM-SP-07 copy',
            sku: 'AM-SP-07-COPY',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07-copy/',
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(1)
        ->and($result['skipped_duplicate_urls'][0]['reason'])->toBe('duplicate_source_url')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'][0]['external_product_id'])->toBe('341026');
});

it('stops Reh4Mat product crawling on HTTP 429 rate limiting', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/rate-limited/' => Http::response('', 429),
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07',
            externalProductId: '341026',
            name: 'AM-SP-07',
            sku: 'AM-SP-07',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://www.reh4mat.com/produkt/ortezy-dloni/rate-limited/',
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
        ]);

    expect($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([
            'https://www.reh4mat.com/produkt/ortezy-dloni/rate-limited/' => 'HTTP 429',
        ])
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['stop_reason'])->toBe('HTTP 429 rate limit or temporary block from Reh4Mat');
});

it('can use a saved Reh4Mat product-link discovery JSON file', function (): void {
    Storage::disk('local')->put('scrapers/reh4mat/product-links-test.json', json_encode(reh4MatCrawlerProductLinksDiscoveryFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    Storage::disk('local')->delete('scrapers/reh4mat/full-product-data-test.json');

    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07',
            externalProductId: '341026',
            name: 'AM-SP-07',
            sku: 'AM-SP-07',
        )),
        'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('reh4mat:crawl-product-data', [
        '--from' => 'scrapers/reh4mat/product-links-test.json',
        '--save' => 'scrapers/reh4mat/full-product-data-test.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $path = storage_path('app/scrapers/reh4mat/full-product-data-test.json');

    expect(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['external_product_id'])->toBe('341026')
        ->and($decoded['failed_url_counts'])->toBe(['HTTP 403' => 1]);
});

it('can print crawled Reh4Mat product data as JSON', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07',
            externalProductId: '341026',
            name: 'AM-SP-07',
            sku: 'AM-SP-07',
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('reh4mat:crawl-product-data', [
        '--url' => ['https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/'],
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('reh4mat')
        ->and($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['name'])->toBe('AM-SP-07')
        ->and($decoded['products'][0]['pictograms'][0]['label'])->toBe('Orteza palca');
});

it('supports limit and offset while crawling Reh4Mat product data', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-sp-07',
            externalProductId: '341026',
            name: 'AM-SP-07',
            sku: 'AM-SP-07',
        )),
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-d-07/' => Http::response(reh4MatCrawlerProductFixture(
            slug: 'am-d-07',
            externalProductId: '341027',
            name: 'AM-D-07',
            sku: 'AM-D-07',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-d-07/',
        ], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe(['https://www.reh4mat.com/produkt/ortezy-dloni/am-d-07/'])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('341027');
});

/**
 * @return array<string, mixed>
 */
function reh4MatCrawlerProductLinksDiscoveryFixture(): array
{
    return [
        'source' => 'reh4mat',
        'product_urls' => [
            'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
            'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/',
        ],
        'products' => [
            [
                'url' => 'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
                'name' => 'AM-SP-07',
                'category_name' => 'Ortezy dłoni',
                'category_url' => 'https://www.reh4mat.com/produkt/ortezy-dloni/',
                'top_category_name' => 'KOŃCZYNA GÓRNA',
                'top_category_url' => 'https://www.reh4mat.com/produkt/konczyna-gorna/',
                'category_path' => ['KOŃCZYNA GÓRNA', 'Ortezy dłoni'],
            ],
            [
                'url' => 'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/',
                'name' => '4ARMY-SK-11',
                'category_name' => 'Ortezy całej kończyny dolnej',
                'category_url' => 'https://www.reh4mat.com/produkt/cala-konczyna-dolna/',
                'top_category_name' => 'KOŃCZYNA DOLNA',
                'top_category_url' => 'https://www.reh4mat.com/produkt/konczyna-dolna/',
                'category_path' => ['KOŃCZYNA DOLNA', 'Ortezy całej kończyny dolnej'],
            ],
        ],
    ];
}

function reh4MatCrawlerProductFixture(string $slug, string $externalProductId, string $name, string $sku): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} | Reh4Mat</title>
                <meta name="description" content="Orteza palca Marka: 4medic Kod UMDNS: 16210">
                <link rel="canonical" href="https://www.reh4mat.com/produkt/ortezy-dloni/{$slug}/">
                <link rel="shortlink" href="https://www.reh4mat.com/?p={$externalProductId}">
            </head>
            <body class="single single-produkt postid-{$externalProductId}">
                <div id="content">
                    <div class="column" id="opis-produktu">
                        <h1 class="product-title"><a href="https://www.reh4mat.com/produkt/ortezy-dloni/{$slug}/" rel="bookmark">{$name}</a></h1>
                        <img src="https://www.reh4mat.com/uploads/2026/05/{$slug}.jpg" class="header-picture" alt="{$name}">
                        <div class="piktogramy-container">
                            <div class="piktogram"><img src="https://www.reh4mat.com/uploads/2021/04/orteza-palca.png" alt="Orteza palca"><span>Orteza palca</span></div>
                        </div>
                        <div id="kody"><div class="kody-content">Marka: <a href="https://www.reh4mat.com/marka/4medic/" rel="tag">4medic</a> Kod UMDNS: <a href="https://www.reh4mat.com/umdns/16210/" rel="tag">16210</a></div></div>
                        <h2 class="tabtitle">Opis</h2>
                        <div class="tabcontent"><p>Opis produktu {$name}.</p></div>
                        <div class="product-meta"><table><tr><td>Kod katalogowy</td><td>{$sku}</td></tr><tr><td>Model</td><td>{$sku}</td></tr></table></div>
                    </div>
                </div>
            </body>
        </html>
    HTML;
}
