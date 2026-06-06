<?php

use App\Services\Wojdak\WojdakCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Wojdak category URLs from women and men root categories and appends hard coded footwear categories', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/">Produkty</a>
                <a href="/produkty/odziez-medyczna-damska/">Odzież medyczna damska</a>
                <a href="bluzy-damskie/">Bluzy</a>
                <a href="https://wojdak.pl/produkty/odziez-medyczna-damska/zakiety-damskie/">Żakiety</a>
                <a href="/kontakt/">Kontakt</a>
            </body></html>
            HTML),
        'https://wojdak.pl/produkty/odziez-medyczna-meska/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/odziez-medyczna-meska/">Odzież medyczna męska</a>
                <a href="/produkty/odziez-medyczna-meska/bluzy/">Bluzy</a>
                <a href="/produkty/odziez-medyczna-meska/spodnie-meskie/">Spodnie</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/',
        'https://wojdak.pl/produkty/odziez-medyczna-damska/zakiety-damskie/',
        'https://wojdak.pl/produkty/odziez-medyczna-meska/bluzy/',
        'https://wojdak.pl/produkty/odziez-medyczna-meska/spodnie-meskie/',
        'https://wojdak.pl/produkty/obuwie-damskie/',
        'https://wojdak.pl/produkty/obuwie-meskie/',
    ]);

    expect($result['failed_urls'])->toBe([]);
});

it('keeps only child categories of the scanned Wojdak root categories', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/odziez-medyczna-damska/tuniki-damskie/">Tuniki</a>
                <a href="/produkty/odziez-medyczna-meska/bluzy/">Men category visible in navigation</a>
                <a href="/produkty/obuwie-damskie/">Footwear navigation category</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakCategoryUrlScraper::class)->scrape(
        startUrls: ['https://wojdak.pl/produkty/odziez-medyczna-damska/'],
        includeHardCodedCategories: false,
    );

    expect($result['category_urls'])->toBe([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/tuniki-damskie/',
    ]);
});

it('records failed Wojdak root category requests but still returns hard coded footwear categories', function (): void {
    Http::fake([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(WojdakCategoryUrlScraper::class)->scrape(
        startUrls: ['https://wojdak.pl/produkty/odziez-medyczna-damska/'],
    );

    expect($result['category_urls'])->toBe([
        'https://wojdak.pl/produkty/obuwie-damskie/',
        'https://wojdak.pl/produkty/obuwie-meskie/',
    ]);

    expect($result['failed_urls'])->toBe([
        'https://wojdak.pl/produkty/odziez-medyczna-damska/' => 'HTTP 500',
    ]);
});
