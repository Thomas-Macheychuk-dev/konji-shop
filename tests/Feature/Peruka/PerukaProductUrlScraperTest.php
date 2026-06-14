<?php

use App\Services\Peruka\PerukaProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Peruka product links from a category page and follows rel next pagination', function (): void {
    Http::fake([
        'https://www.peruka.pl/category/peruki-damskie' => Http::response(<<<'HTML'
            <html>
                <head><link rel="next" href="https://www.peruka.pl/category/peruki-damskie/2" /></head>
                <body>
                    <div class="product-list clearfix">
                        <p class="name"><a href="/orbit-chocolate-mix.html" class="product_name">Półperuka z naturalnych włosów remy ORBIT</a></p>
                        <p class="name"><a href="https://peruka.pl/ideal-mocca-mix-dopinka-naturalna.html?color=1" class="product_name">Półperuka IDEAL 14cm</a></p>
                        <a href="/product/search?query=orbit">Search</a>
                        <a href="/webpage/katalogi.html">Katalogi</a>
                    </div>
                </body>
            </html>
            HTML),
        'https://www.peruka.pl/category/peruki-damskie/2' => Http::response(<<<'HTML'
            <html><body>
                <div class="product-list clearfix">
                    <p class="name"><a href="/fluff-6-8-4r.html" class="product_name">Półperuka syntetyczna FLUFF</a></p>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(PerukaProductUrlScraper::class)->scrape(
        categoryUrls: ['https://www.peruka.pl/category/peruki-damskie'],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://www.peruka.pl/orbit-chocolate-mix.html',
        'https://www.peruka.pl/ideal-mocca-mix-dopinka-naturalna.html',
        'https://www.peruka.pl/fluff-6-8-4r.html',
    ]);

    expect($result['visited_urls'])->toBe([
        'https://www.peruka.pl/category/peruki-damskie',
        'https://www.peruka.pl/category/peruki-damskie/2',
    ]);

    expect($result['category_results'][0])->toMatchArray([
        'name' => 'Peruki Damskie',
        'url' => 'https://www.peruka.pl/category/peruki-damskie',
        'product_count' => 3,
    ]);
});

it('can discover categories first and then scrape Peruka products from those categories', function (): void {
    Http::fake([
        'https://www.peruka.pl/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="nav navbar-nav horizontal-categories">
                    <li>
                        <a href="/category/kosmetyki" class="category-link">Kosmetyki do peruk</a>
                    </li>
                    <li class="dropdown">
                        <a href="/category/turbany" class="dropdown-toggle category-link">Turbany</a>
                        <ul class="dropdown-menu">
                            <li><a href="/category/turbany-premium" class="category-link">PREMIUM</a></li>
                        </ul>
                    </li>
                </ul>
            </body></html>
            HTML),
        'https://www.peruka.pl/category/kosmetyki' => Http::response('<a href="/szampon-do-peruk.html" class="product_name">Szampon do peruk</a>'),
        'https://www.peruka.pl/category/turbany' => Http::response('<a href="/turban-aqua3-black-premium.html" class="product_name">Turban AQUA3</a>'),
        'https://www.peruka.pl/category/turbany-premium' => Http::response('<a href="/turban-anita-b3-m154-premium.html" class="product_name">Zestaw turban ANITA</a>'),
        '*' => Http::response('', 404),
    ]);

    $result = app(PerukaProductUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://www.peruka.pl/category/kosmetyki',
        'https://www.peruka.pl/category/turbany',
        'https://www.peruka.pl/category/turbany-premium',
    ]);

    expect($result['product_urls'])->toBe([
        'https://www.peruka.pl/szampon-do-peruk.html',
        'https://www.peruka.pl/turban-aqua3-black-premium.html',
        'https://www.peruka.pl/turban-anita-b3-m154-premium.html',
    ]);
});

it('respects Peruka product discovery limits', function (): void {
    Http::fake([
        'https://www.peruka.pl/category/turbany' => Http::response(<<<'HTML'
            <html>
                <head><link rel="next" href="https://www.peruka.pl/category/turbany/2" /></head>
                <body>
                    <a href="/turban-aqua3-black-premium.html" class="product_name">Turban AQUA3</a>
                    <a href="/turban-anita-b3-m154-premium.html" class="product_name">Zestaw turban ANITA</a>
                </body>
            </html>
            HTML),
        'https://www.peruka.pl/category/turbany/2' => Http::response('<a href="/turban-felicja-new-k-050-premium.html" class="product_name">Turban FELICJA</a>'),
        '*' => Http::response('', 404),
    ]);

    $result = app(PerukaProductUrlScraper::class)->scrape(
        categoryUrls: ['https://www.peruka.pl/category/turbany'],
        discoverCategories: false,
        limit: 1,
    );

    expect($result['product_urls'])->toBe([
        'https://www.peruka.pl/turban-aqua3-black-premium.html',
    ]);

    expect($result['visited_urls'])->toBe([
        'https://www.peruka.pl/category/turbany',
    ]);
});

it('records failed Peruka category pages while returning product links from successful categories', function (): void {
    Http::fake([
        'https://www.peruka.pl/category/akcesoria-do-peruk' => Http::response('<a href="/glowa-perukarska-plocienna-czarna.html" class="product_name">Głowa perukarska</a>'),
        'https://www.peruka.pl/category/turbany' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(PerukaProductUrlScraper::class)->scrape(
        categoryUrls: [
            'https://www.peruka.pl/category/akcesoria-do-peruk',
            'https://www.peruka.pl/category/turbany',
        ],
        discoverCategories: false,
    );

    expect($result['product_urls'])->toBe([
        'https://www.peruka.pl/glowa-perukarska-plocienna-czarna.html',
    ]);

    expect($result['failed_urls'])->toBe([
        'https://www.peruka.pl/category/turbany' => 'HTTP 500',
    ]);
});
