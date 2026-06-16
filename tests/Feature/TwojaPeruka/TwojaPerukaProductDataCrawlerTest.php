<?php

use App\Services\TwojaPeruka\TwojaPerukaProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls TwojaPeruka product data from discovered product links and deduplicates products', function (): void {
    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaCrawlerProductFixture('iris', 'TP-IRIS', 'Peruka syntetyczna IRIS', 540.00)),
        'https://twojaperuka.pl/iris-copy' => Http::response(twojaPerukaCrawlerProductFixture('iris-copy', 'TP-IRIS', 'Peruka syntetyczna IRIS kopia', 540.00)),
        'https://twojaperuka.pl/aurora' => Http::response(twojaPerukaCrawlerProductFixture('aurora', 'TP-AURORA', 'Peruka syntetyczna AURORA', 620.00)),
        'https://twojaperuka.pl/missing' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'twojaperuka',
            'product_urls' => [
                'https://twojaperuka.pl/iris',
                'https://twojaperuka.pl/iris',
                'https://twojaperuka.pl/iris-copy',
                'https://twojaperuka.pl/aurora',
                'https://twojaperuka.pl/missing',
            ],
        ]);

    expect($result['source'])->toBe('twojaperuka')
        ->and($result['product_count'])->toBe(2)
        ->and($result['products'])->toHaveCount(2)
        ->and($result['products'][0]['external_product_id'])->toBe('TP-IRIS')
        ->and($result['products'][1]['external_product_id'])->toBe('TP-AURORA')
        ->and($result['skipped_duplicate_urls'])->toHaveCount(1)
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['failed_urls'])->toBe([
            'https://twojaperuka.pl/missing' => 'HTTP 500',
        ]);
});

it('can use a saved TwojaPeruka product-link discovery JSON file', function (): void {
    Storage::disk('local')->put('scrapers/twojaperuka/product-links-test.json', json_encode([
        'source' => 'twojaperuka',
        'product_urls' => [
            'https://twojaperuka.pl/iris',
            'https://twojaperuka.pl/aurora',
        ],
    ], JSON_THROW_ON_ERROR));

    Storage::disk('local')->delete('scrapers/twojaperuka/full-product-data-test.json');

    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaCrawlerProductFixture('iris', 'TP-IRIS', 'Peruka syntetyczna IRIS', 540.00)),
        'https://twojaperuka.pl/aurora' => Http::response(twojaPerukaCrawlerProductFixture('aurora', 'TP-AURORA', 'Peruka syntetyczna AURORA', 620.00)),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('twojaperuka:crawl-product-data', [
        '--from' => 'scrapers/twojaperuka/product-links-test.json',
        '--save' => 'scrapers/twojaperuka/full-product-data-test.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $path = storage_path('app/scrapers/twojaperuka/full-product-data-test.json');

    expect(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_count'])->toBe(2)
        ->and($decoded['products'][0]['external_product_id'])->toBe('TP-IRIS')
        ->and($decoded['products'][1]['external_product_id'])->toBe('TP-AURORA');
});

it('can print crawled TwojaPeruka product data as JSON', function (): void {
    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaCrawlerProductFixture('iris', 'TP-IRIS', 'Peruka syntetyczna IRIS', 540.00)),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('twojaperuka:crawl-product-data', [
        '--url' => ['https://twojaperuka.pl/iris'],
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('twojaperuka')
        ->and($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['name'])->toBe('Peruka syntetyczna IRIS')
        ->and($decoded['products'][0]['price_gross_amount'])->toBe(540.00);
});

it('supports limit and offset while crawling TwojaPeruka product data', function (): void {
    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaCrawlerProductFixture('iris', 'TP-IRIS', 'Peruka syntetyczna IRIS', 540.00)),
        'https://twojaperuka.pl/aurora' => Http::response(twojaPerukaCrawlerProductFixture('aurora', 'TP-AURORA', 'Peruka syntetyczna AURORA', 620.00)),
        'https://twojaperuka.pl/luna' => Http::response(twojaPerukaCrawlerProductFixture('luna', 'TP-LUNA', 'Peruka syntetyczna LUNA', 450.00)),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([
            'https://twojaperuka.pl/iris',
            'https://twojaperuka.pl/aurora',
            'https://twojaperuka.pl/luna',
        ], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe(['https://twojaperuka.pl/aurora'])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['external_product_id'])->toBe('TP-AURORA');
});

function twojaPerukaCrawlerProductFixture(string $slug, string $externalId, string $name, float $price): string
{
    $priceText = number_format($price, 2, '.', '');

    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} - TwojaPeruka.pl</title>
                <meta name="description" content="Opis SEO {$name}">
                <meta property="og:title" content="{$name}">
                <meta property="og:price:amount" content="{$priceText}">
                <meta property="og:price:currency" content="PLN">
                <link rel="canonical" href="https://twojaperuka.pl/{$slug}">
            </head>
            <body>
                <main>
                    <nav class="breadcrumbs">
                        <ol>
                            <li><a href="https://twojaperuka.pl">twojaperuka.pl</a></li>
                            <li><a href="https://twojaperuka.pl/peruki">Peruki</a></li>
                            <li><a href="https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48">Peruki syntetyczne</a></li>
                            <li><a href="https://twojaperuka.pl/flower">Peruki Flower Collection</a></li>
                            <li><span>{$name}</span></li>
                        </ol>
                    </nav>
                    <h1>{$name}</h1>
                    <input type="hidden" name="product_id" value="{$externalId}">
                    <meta itemprop="sku" content="{$externalId}">
                    <p>EAN: 5900000000001</p>
                    <div data-producer="NAH"></div>
                    <div class="product-availability__image-and-description"><strong>dostępny</strong></div>
                    <div class="product-short-description"><p>Krótki opis {$name}</p></div>
                    <div class="product-description">
                        <div class="product-description__content grid__col resetcss">
                            <p>{$name} opis produktu.</p>
                            <p><strong>Peruka jest wyrobem medycznym. Używaj zgodnie z instrukcją lub etykietą.</strong></p>
                        </div>
                    </div>
                    <div class="product-gallery">
                        <a class="js__gallery-anchor-image" href="/environment/cache/images/productGfx_{$slug}_0_0/{$slug}.webp">
                            <img src="/environment/cache/images/productGfx_{$slug}_500_500/{$slug}.webp" alt="{$name}">
                        </a>
                    </div>
                    <script type="application/json">
                    {
                        "id": "{$externalId}",
                        "name": "{$name}",
                        "price": "{$priceText}",
                        "producer": "NAH",
                        "category": "Peruki Flower Collection",
                        "currency": "PLN"
                    }
                    </script>
                </main>
            </body>
        </html>
    HTML;
}
