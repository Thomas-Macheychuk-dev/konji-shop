<?php

use App\Services\Wojdak\WojdakProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Wojdak shop product URLs from a WooCommerce category page', function (): void {
    Http::fake([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="products columns-3">
                    <li class="product">
                        <a href="https://sklep.wojdak.pl/produkt/bluza-e2002/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Bluza E2002</a>
                        <a href="https://sklep.wojdak.pl/produkt/bluza-e2002/" class="button">Wybierz opcje</a>
                    </li>
                    <li class="product">
                        <a href="/produkt/fartuch-m1031/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Fartuch M1031</a>
                    </li>
                </ul>
                <a href="https://wojdak.pl/product/old-page/">Old catalogue page</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/'],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://sklep.wojdak.pl/produkt/bluza-e2002/',
        'https://sklep.wojdak.pl/produkt/fartuch-m1031/',
    ]);

    expect($result['category_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
    ]);

    expect($result['visited_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
    ]);

    expect($result['failed_urls'])->toBe([]);
});

it('crawls all pagination pages for each Wojdak shop category', function (): void {
    Http::fake([
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="products columns-3">
                    <li class="product"><a class="woocommerce-LoopProduct-link" href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/">BW 120</a></li>
                </ul>
                <nav class="woocommerce-pagination" aria-label="Paginacja produktów">
                    <ul class="page-numbers">
                        <li><span class="page-numbers current">1</span></li>
                        <li><a aria-label="Strona 2" class="page-numbers" href="https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/page/2/">2</a></li>
                        <li><a class="next page-numbers" href="https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/page/2/">&rarr;</a></li>
                    </ul>
                </nav>
            </body></html>
            HTML),
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/page/2/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="products columns-3">
                    <li class="product"><a class="woocommerce-LoopProduct-link" href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-124/">BW 124</a></li>
                </ul>
                <nav class="woocommerce-pagination" aria-label="Paginacja produktów">
                    <a class="page-numbers" href="https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/">1</a>
                    <span class="page-numbers current">2</span>
                </nav>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/'],
        discoverCategories: false,
    );

    expect($result['visited_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/page/2/',
    ]);

    expect($result['product_urls'])->toBe([
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/',
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-124/',
    ]);
});

it('uses the hard coded Wojdak shop categories when no category is passed', function (): void {
    Http::fake([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/' => Http::response('<a href="https://sklep.wojdak.pl/produkt/bluza-e2002/">Bluza E2002</a>'),
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/' => Http::response('<a href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/">BW 120</a>'),
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/' => Http::response('<a href="https://sklep.wojdak.pl/produkt/bluza-e6001/">Bluza E6001</a>'),
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-meskie/' => Http::response('<a href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-meskie-bw-102/">BW 102</a>'),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/',
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-meskie/',
    ]);

    expect($result['product_urls'])->toBe([
        'https://sklep.wojdak.pl/produkt/bluza-e2002/',
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/',
        'https://sklep.wojdak.pl/produkt/bluza-e6001/',
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-meskie-bw-102/',
    ]);
});

it('discovers Wojdak shop product URLs embedded in raw category HTML payloads', function (): void {
    Http::fake([
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <script>
                    window.products = [
                        {"url":"https:\/\/sklep.wojdak.pl\/produkt\/obuwie-medyczne-damskie-bw-120\/"},
                        {"product_url":"\/produkt\/obuwie-medyczne-damskie-bw-124\/"}
                    ];
                </script>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/'],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/',
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-124/',
    ]);
});

it('records failed Wojdak category pages while returning product URLs from successful categories', function (): void {
    Http::fake([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/' => Http::response('<a href="https://sklep.wojdak.pl/produkt/bluza-e2002/">Bluza E2002</a>'),
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: [
            'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
            'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/',
        ],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://sklep.wojdak.pl/produkt/bluza-e2002/',
    ]);

    expect($result['failed_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/' => 'HTTP 500',
    ]);
});

it('respects the product URL discovery limit across paginated pages', function (): void {
    Http::fake([
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/">BW120</a>
                <a href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-124/">BW124</a>
                <nav class="woocommerce-pagination">
                    <a class="next page-numbers" href="https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/page/2/">2</a>
                </nav>
            </body></html>
            HTML),
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/page/2/' => Http::response('<a href="https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-126/">BW126</a>'),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/'],
        discoverCategories: false,
        limit: 1,
    );

    expect($result['product_urls'])->toBe([
        'https://sklep.wojdak.pl/produkt/obuwie-medyczne-damskie-bw-120/',
    ]);

    expect($result['visited_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/',
    ]);
});
