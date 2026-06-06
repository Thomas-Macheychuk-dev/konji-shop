<?php

use App\Services\Wojdak\WojdakProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Wojdak product URLs from product links on category pages', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://wojdak.pl/produkty/odziez-medyczna-damska/">Odzież damska</a>
                <a class="products-list__product" href="https://wojdak.pl/product/bluza-2002/">Bluza 2002</a>
                <a class="products-list__product" href="/product/bluza-2129/">Bluza 2041</a>
                <a class="button" href="https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/bluzy-damskie/">Przejdź do sklepu</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/'],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://wojdak.pl/product/bluza-2002/',
        'https://wojdak.pl/product/bluza-2129/',
    ]);

    expect($result['category_urls'])->toBe([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/',
    ]);

    expect($result['failed_urls'])->toBe([]);
});

it('runs phase 1 category discovery before discovering Wojdak product URLs when no category is passed', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/odziez-medyczna-damska/bluzy-damskie/">Bluzy</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/odziez-medyczna-meska/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/odziez-medyczna-meska/bluzy/">Bluzy męskie</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <a class="products-list__product" href="https://wojdak.pl/product/bluza-2002/">Bluza 2002</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/odziez-medyczna-meska/bluzy/' => Http::response(<<<'HTML'
            <html><body>
                <a class="products-list__product" href="https://wojdak.pl/product/bluza-meska-1201/">Bluza męska 1201</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/obuwie-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <a class="products-list__product" href="https://wojdak.pl/product/bw120/">BW120</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/obuwie-meskie/' => Http::response(<<<'HTML'
            <html><body>
                <a class="products-list__product" href="https://wojdak.pl/product/bm122/">BM122</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/',
        'https://wojdak.pl/produkty/odziez-medyczna-meska/bluzy/',
        'https://wojdak.pl/produkty/obuwie-damskie/',
        'https://wojdak.pl/produkty/obuwie-meskie/',
    ]);

    expect($result['product_urls'])->toBe([
        'https://wojdak.pl/product/bluza-2002/',
        'https://wojdak.pl/product/bluza-meska-1201/',
        'https://wojdak.pl/product/bw120/',
        'https://wojdak.pl/product/bm122/',
    ]);
});

it('discovers Wojdak product URLs embedded in raw category HTML payloads', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/obuwie-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <script>
                    window.products = [
                        {"url":"https:\/\/wojdak.pl\/product\/bw120\/"},
                        {"product_url":"\/product\/bw124\/"}
                    ];
                </script>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://wojdak.pl/produkty/obuwie-damskie/'],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://wojdak.pl/product/bw120/',
        'https://wojdak.pl/product/bw124/',
    ]);
});

it('records failed Wojdak category pages while returning product URLs from successful categories', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <a class="products-list__product" href="https://wojdak.pl/product/bluza-2002/">Bluza 2002</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/odziez-medyczna-damska/zakiety-damskie/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: [
            'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/',
            'https://wojdak.pl/produkty/odziez-medyczna-damska/zakiety-damskie/',
        ],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://wojdak.pl/product/bluza-2002/',
    ]);

    expect($result['failed_urls'])->toBe([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/zakiety-damskie/' => 'HTTP 500',
    ]);
});

it('respects the product URL discovery limit', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/obuwie-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <a class="products-list__product" href="https://wojdak.pl/product/bw120/">BW120</a>
                <a class="products-list__product" href="https://wojdak.pl/product/bw124/">BW124</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakProductUrlScraper::class)->scrape(
        categoryUrls: ['https://wojdak.pl/produkty/obuwie-damskie/'],
        discoverCategories: false,
        limit: 1,
    );

    expect($result['product_urls'])->toBe([
        'https://wojdak.pl/product/bw120/',
    ]);
});
