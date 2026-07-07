<?php

use App\Services\Apolonia\ApoloniaProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Apolonia product links from target categories with category context and counter pagination', function (): void {
    Http::fake([
        'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html' => Http::response(<<<'HTML'
            <html><body>
                <main id="content">
                    <div class="products">
                        <div class="product">
                            <a class="product__name" href="/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html">Bluza medyczna damska bordo BL 60</a>
                        </div>
                        <div class="product">
                            <a class="product__name" href="https://www.apolonia.com.pl/product-pol-3388-Bluzka-medyczna-damska-bez-nude-BZ-01-krotki-rekaw-Elegant-Stretch.html">Bluzka medyczna damska beż nude BZ 01</a>
                        </div>
                    </div>
                    <nav class="pagination"><a class="pagination__element" href="/pol_m_Odziez-medyczna-241.html?counter=1">2</a></nav>
                </main>
            </body></html>
            HTML),
        'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html?counter=1' => Http::response(<<<'HTML'
            <html><body>
                <main id="content">
                    <div class="products">
                        <div class="product">
                            <a class="product__name" href="/product-pol-105-Obuwie-medyczne-Wock-Waylite-04-z-pianki-EVA-czarne-unisex.html">Obuwie medyczne Wock Waylite 04</a>
                        </div>
                    </div>
                </main>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $categoryDiscovery = [
        'source' => 'apolonia',
        'visited_urls' => ['https://www.apolonia.com.pl/'],
        'failed_urls' => [],
        'product_category_urls' => ['https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html'],
        'categories' => [[
            'name' => 'Odzież medyczna',
            'url' => 'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'path' => ['Odzież medyczna'],
            'top_category_name' => 'Odzież medyczna',
            'top_category_url' => 'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
        ]],
    ];

    $result = app(ApoloniaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($categoryDiscovery);

    expect($result['source'])->toBe('apolonia')
        ->and($result['product_urls'])->toBe([
            'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
            'https://www.apolonia.com.pl/product-pol-3388-Bluzka-medyczna-damska-bez-nude-BZ-01-krotki-rekaw-Elegant-Stretch.html',
            'https://www.apolonia.com.pl/product-pol-105-Obuwie-medyczne-Wock-Waylite-04-z-pianki-EVA-czarne-unisex.html',
        ])
        ->and($result['products'][0])->toMatchArray([
            'url' => 'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
            'name' => 'Bluza medyczna damska bordo BL 60',
            'category_name' => 'Odzież medyczna',
            'category_url' => 'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'top_category_name' => 'Odzież medyczna',
            'category_path' => ['Odzież medyczna'],
        ])
        ->and($result['category_results'][0]['product_count'])->toBe(3)
        ->and($result['category_results'][0]['pages_scraped'])->toBe(2)
        ->and($result['category_results'][0]['visited_urls'])->toBe([
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html?counter=1',
        ])
        ->and($result['failed_urls'])->toBe([]);
});

it('ignores Apolonia header footer category and utility product-looking links while extracting products', function (): void {
    Http::fake([
        'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html' => Http::response(<<<'HTML'
            <html><body>
                <header><a href="/product-pol-9999-Header.html">Header product</a></header>
                <nav id="menu_categories"><a href="/product-pol-8888-Menu.html">Menu product</a></nav>
                <main id="content">
                    <div class="product">
                        <a class="product__name" href="/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html">Bluza medyczna damska bordo BL 60</a>
                    </div>
                    <a href="/pol_m_Odziez-medyczna-241.html">Category</a>
                    <a href="/login.php">Login</a>
                    <a href="mailto:apolonia@apolonia.com.pl">Email</a>
                    <a href="https://example.com/product-pol-1-Fake.html">External</a>
                </main>
                <footer><a href="/product-pol-7777-Footer.html">Footer product</a></footer>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ApoloniaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html']);

    expect($result['product_urls'])->toBe([
        'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
    ]);
});

it('records failed Apolonia product link category requests', function (): void {
    Http::fake([
        'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(ApoloniaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html']);

    expect($result['product_urls'])->toBe([])
        ->and($result['category_results'][0]['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html' => 'HTTP 500',
        ]);
});
