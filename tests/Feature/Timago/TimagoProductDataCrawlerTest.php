<?php

use App\Services\Timago\TimagoProductDataCrawler;
use App\Services\Timago\TimagoProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('extracts Timago product details from a product page', function (): void {
    $html = timagoProductPageFixture();

    $product = app(TimagoProductScraper::class)->extract(
        $html,
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
        [
            'category_name' => 'Wózki elektryczne',
            'category_url' => 'https://www.timago.com/pl/rehabilitacja/wozki-elektryczne/',
            'top_category_name' => 'Rehabilitacja',
            'top_category_url' => 'https://www.timago.com/pl/rehabilitacja/',
            'category_path' => ['Rehabilitacja', 'Wózki elektryczne'],
        ],
    );

    expect($product)->toMatchArray([
        'source' => 'timago',
        'source_url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
        'canonical_url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
        'external_product_id' => '1306',
        'slug' => 'wozek-inwalidzki-elektryczny-maya',
        'name' => 'Wózek inwalidzki elektryczny - Maya',
        'category' => 'Wózki elektryczne',
        'categories' => ['Rehabilitacja', 'Wózki elektryczne'],
        'seo_title' => 'Wózek inwalidzki elektryczny - Maya | Timago',
        'seo_description' => 'Lekki elektryczny wózek inwalidzki Maya.',
        'short_description' => 'Lekki elektryczny wózek inwalidzki Maya.',
        'price_gross_amount' => '4999.00',
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'shipping_time' => '48 godzin',
        'sku' => 'MAYA',
        'ean' => '5900000000001',
        'is_medical_device' => true,
        'product_link_category_name' => 'Wózki elektryczne',
    ])
        ->and($product['brand'])->toBe(['name' => 'Timago', 'slug' => 'timago'])
        ->and($product['attributes'])->toContain([
            'code' => 'kod-produktu',
            'label' => 'Kod produktu',
            'value' => 'MAYA',
            'slug' => 'maya',
        ])
        ->and($product['images'])->toHaveCount(3)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://www.timago.com/_pliki_/produkty/1306/b-wozek-inwalidzki-elektryczny-maya.jpg',
            'alt' => 'Wózek inwalidzki elektryczny - Maya',
            'sort_order' => 0,
        ])
        ->and($product['variant_candidates'])->toHaveCount(2)
        ->and($product['variant_candidates'][0]['attributes'][0])->toMatchArray([
            'code' => 'rozmiar',
            'label' => 'rozmiar',
            'value' => 'S',
            'slug' => 's',
        ])
        ->and($product['description_html'])->toContain('Bardzo lekki elektryczny wózek')
        ->and($product['description_html'])->not->toContain('href=');
});

it('crawls Timago product data from product-link discovery JSON and skips duplicate products', function (): void {
    $discovery = timagoProductLinkDiscoveryForCrawlerFixture();

    $duplicateHtml = str_replace(
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya-duplicate.html',
        timagoProductPageFixture(),
    );

    Http::fake([
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html' => Http::response(timagoProductPageFixture()),
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya-duplicate.html' => Http::response($duplicateHtml),
    ]);

    $result = app(TimagoProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery($discovery);

    expect($result['source'])->toBe('timago')
        ->and($result['source_product_url_count'])->toBe(2)
        ->and($result['total_product_url_count'])->toBe(2)
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['name'])->toBe('Wózek inwalidzki elektryczny - Maya')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['failed_urls'])->toBe([]);
});

it('can save Timago full product data from a saved product-link discovery file', function (): void {
    $productLinksPath = storage_path('app/scrapers/timago/product-links-crawler-test.json');
    $savedPath = storage_path('app/scrapers/timago/full-product-data-crawler-test.json');

    if (! is_dir(dirname($productLinksPath))) {
        mkdir(dirname($productLinksPath), 0755, true);
    }

    @unlink($productLinksPath);
    @unlink($savedPath);

    file_put_contents(
        $productLinksPath,
        json_encode(timagoProductLinkDiscoveryForCrawlerFixture(limit: 1), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    Http::fake([
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html' => Http::response(timagoProductPageFixture()),
    ]);

    $exitCode = Artisan::call('timago:crawl-product-data', [
        '--from' => 'scrapers/timago/product-links-crawler-test.json',
        '--save' => 'scrapers/timago/full-product-data-crawler-test.json',
        '--no-progress' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file($savedPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($savedPath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('timago')
        ->and($saved['product_count'])->toBe(1)
        ->and($saved['products'][0]['source'])->toBe('timago')
        ->and($saved['products'][0]['name'])->toBe('Wózek inwalidzki elektryczny - Maya');

    @unlink($productLinksPath);
    @unlink($savedPath);
});

function timagoProductLinkDiscoveryForCrawlerFixture(int $limit = 2): array
{
    $products = [
        [
            'url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
            'name' => 'Wózek inwalidzki elektryczny - Maya',
            'category_name' => 'Wózki elektryczne',
            'category_url' => 'https://www.timago.com/pl/rehabilitacja/wozki-elektryczne/',
            'top_category_name' => 'Rehabilitacja',
            'top_category_url' => 'https://www.timago.com/pl/rehabilitacja/',
            'category_path' => ['Rehabilitacja', 'Wózki elektryczne'],
        ],
        [
            'url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya-duplicate.html',
            'name' => 'Wózek inwalidzki elektryczny - Maya',
            'category_name' => 'Wózki elektryczne',
            'category_url' => 'https://www.timago.com/pl/rehabilitacja/wozki-elektryczne/',
            'top_category_name' => 'Rehabilitacja',
            'top_category_url' => 'https://www.timago.com/pl/rehabilitacja/',
            'category_path' => ['Rehabilitacja', 'Wózki elektryczne'],
        ],
    ];

    $products = array_slice($products, 0, $limit);

    return [
        'source' => 'timago',
        'product_urls' => array_column($products, 'url'),
        'products' => $products,
        'failed_urls' => [],
    ];
}

function timagoProductPageFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Wózek inwalidzki elektryczny - Maya | Timago</title>
                <meta name="description" content="Lekki elektryczny wózek inwalidzki Maya.">
                <meta property="og:title" content="Wózek inwalidzki elektryczny - Maya">
                <link rel="canonical" href="https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html">
                <meta itemprop="sku" content="MAYA">
                <meta itemprop="gtin13" content="5900000000001">
                <meta itemprop="brand" content="Timago">
                <meta property="product:price:amount" content="4999">
            </head>
            <body>
                <header><nav><a href="/pl/menu-product.html">Menu product</a></nav></header>
                <main>
                    <div class="breadcrumbs">
                        <a href="/pl/">Strona główna</a>
                        <a href="/pl/rehabilitacja/">Rehabilitacja</a>
                        <a href="/pl/rehabilitacja/wozki-elektryczne/">Wózki elektryczne</a>
                    </div>

                    <article class="product">
                        <h1>Wózek inwalidzki elektryczny - Maya</h1>

                        <div class="product__gallery">
                            <img src="/szablony/public/img/dot.png" srcset="/_pliki_/produkty/1306/b-wozek-inwalidzki-elektryczny-maya.jpg" alt="Wózek inwalidzki elektryczny - Maya">
                            <img src="/_pliki_/produkty/1306/wozek-inwalidzki-elektryczny-maya-side.jpg" alt="Maya bok">
                        </div>

                        <div class="product__price">4 999,00 zł</div>
                        <div class="availability">Dostępny</div>
                        <div class="delivery">48 godzin</div>

                        <table class="product__params">
                            <tr><th>Kod produktu</th><td>MAYA</td></tr>
                            <tr><th>Producent</th><td>Timago</td></tr>
                            <tr><th>Kod EAN</th><td>5900000000001</td></tr>
                        </table>

                        <label for="rozmiar">Rozmiar</label>
                        <select id="rozmiar" name="rozmiar">
                            <option>Wybierz rozmiar</option>
                            <option>S</option>
                            <option>M</option>
                        </select>

                        <div class="product__txt">
                            <p><strong>Bardzo lekki elektryczny wózek</strong> dla pacjentów.</p>
                            <p>Prezentowany produkt jest wyrobem medycznym.</p>
                            <p><a href="https://www.timago.com/pl/rehabilitacja/">Link supplier</a></p>
                            <img src="/_pliki_/produkty/1306/description.jpg" alt="Opis">
                        </div>
                    </article>
                </main>
            </body>
        </html>
    HTML;
}
