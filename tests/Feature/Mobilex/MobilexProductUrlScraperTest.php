<?php

use App\Services\Mobilex\MobilexProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('scrapes product links from lower categories and top categories without children', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/nowosci/">Nowości</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/">Wózki inwalidzkie</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/">Siedziska ortopedyczne</a></li>
                    <li><a href="https://mobilex.pl/obuwie-scholl/">Obuwie Scholl</a></li>
                    <li><a href="https://mobilex.pl/serwis/">Serwis</a></li>
                </ul>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-podstawowe/">wózki podstawowe</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-aktywne/">wózki aktywne</a></li>
                </ul>
                <article class="produkty"><a href="https://mobilex.pl/produkty/top-category-product-that-should-not-be-scraped/">Wrong product</a></article>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/' => Http::response(<<<'HTML'
            <html><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/siedzisko-testowe/"><img alt="Siedzisko testowe"></a>
                    <h2 class="tbp_title"><a href="https://mobilex.pl/produkty/siedzisko-testowe/">Siedzisko testowe</a></h2>
                </article>
            </body></html>
            HTML),
        'https://mobilex.pl/obuwie-scholl/' => Http::response(<<<'HTML'
            <html><body>
                <div id="kafle-sholl">
                    <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/obuwie-operacyjne-damskie/"><img alt="tile"></a></div>
                    <div class="module-text"><h2>Obuwie operacyjne damskie</h2></div>
                </div>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/' => Http::response(<<<'HTML'
            <html><head>
                <link rel="next" href="https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/2/">
            </head><body>
                <div class="builder-posts-wrap loops-wrapper produkty">
                    <article class="produkty">
                        <a href="https://mobilex.pl/produkty/wozek-inwalidzki-flipper/"><img alt="Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR"></a>
                        <h2 class="tbp_title"><a href="https://mobilex.pl/produkty/wozek-inwalidzki-flipper/">Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR</a></h2>
                    </article>
                    <article class="produkty">
                        <a href="/produkty/wozek-inwalidzki-flipper-doposazony/"><img alt="Wózek inwalidzki ręczny aluminiowy FLIPPER z doposażeniem"></a>
                        <h2 class="tbp_title"><a href="/produkty/wozek-inwalidzki-flipper-doposazony/">Wózek inwalidzki ręczny aluminiowy FLIPPER z doposażeniem</a></h2>
                    </article>
                </div>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/2/' => Http::response(<<<'HTML'
            <html><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/wozek-inwalidzki-seal-wheelchair/"><img alt="Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami"></a>
                    <h2 class="tbp_title"><a href="https://mobilex.pl/produkty/wozek-inwalidzki-seal-wheelchair/">Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami</a></h2>
                </article>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/' => Http::response(<<<'HTML'
            <html><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/wozek-aktywny-test/"><img alt="Wózek aktywny test"></a>
                    <h2 class="tbp_title"><a href="https://mobilex.pl/produkty/wozek-aktywny-test/">Wózek aktywny test</a></h2>
                </article>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/obuwie-operacyjne-damskie/' => Http::response(<<<'HTML'
            <html><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/clog-evo-wine/"><img alt="CLOG EVO &#8211; bordowy &#8211; wine"></a>
                    <h2 class="tbp_title"><a href="https://mobilex.pl/produkty/clog-evo-wine/">CLOG EVO &#8211; bordowy &#8211; wine</a></h2>
                </article>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/clog-evo-orange/"><img alt="CLOG EVO &#8211; pomarańczowy &#8211; orange"></a>
                    <h2 class="tbp_title"><a href="https://mobilex.pl/produkty/clog-evo-orange/">CLOG EVO &#8211; pomarańczowy &#8211; orange</a></h2>
                </article>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexProductUrlScraper::class)->scrape();

    expect($result['source_categories'])->toHaveCount(4);

    expect($result['product_urls'])->toBe([
        'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
        'https://mobilex.pl/produkty/wozek-inwalidzki-flipper-doposazony/',
        'https://mobilex.pl/produkty/wozek-inwalidzki-seal-wheelchair/',
        'https://mobilex.pl/produkty/wozek-aktywny-test/',
        'https://mobilex.pl/produkty/siedzisko-testowe/',
        'https://mobilex.pl/produkty/clog-evo-wine/',
        'https://mobilex.pl/produkty/clog-evo-orange/',
    ]);

    expect($result['product_urls'])->not->toContain('https://mobilex.pl/produkty/top-category-product-that-should-not-be-scraped/');

    expect($result['products'][0])->toMatchArray([
        'url' => 'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
        'name' => 'Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR',
        'category_name' => 'wózki podstawowe',
        'category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        'top_category_name' => 'Wózki inwalidzkie',
        'top_category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
    ]);

    expect($result['products'][5])->toMatchArray([
        'url' => 'https://mobilex.pl/produkty/clog-evo-wine/',
        'name' => 'CLOG EVO – bordowy – wine',
        'category_name' => 'Obuwie operacyjne damskie',
        'category_url' => 'https://mobilex.pl/kategoria-produktu/obuwie-operacyjne-damskie/',
        'top_category_name' => 'Obuwie Scholl',
        'top_category_url' => 'https://mobilex.pl/obuwie-scholl/',
    ]);

    expect($result['category_results'][0])->toMatchArray([
        'name' => 'wózki podstawowe',
        'url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        'product_count' => 3,
        'visited_urls' => [
            'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
            'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/2/',
        ],
    ]);
});

it('can scrape product links from an explicit category URL without hierarchy discovery', function (): void {
    Http::fake([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://mobilex.pl/kategoria-produktu/ignored-category/">Ignored category</a>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/wozek-inwalidzki-flipper/">Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR</a>
                </article>
                <article class="produkty">
                    <a href="https://example.com/produkty/not-mobilex/">External product</a>
                </article>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexProductUrlScraper::class)->scrapeCategories([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
    ]);

    expect($result['product_urls'])->toBe([
        'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
    ])->and($result['source_categories'])->toBe([
        [
            'name' => 'Wozki Podstawowe',
            'url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
            'parent_name' => null,
            'parent_url' => null,
        ],
    ]);
});

it('stops category pagination when a later page has no product links', function (): void {
    Http::fake([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/' => Http::response(<<<'HTML'
            <html><head>
                <link rel="next" href="https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/2/">
            </head><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/wozek-inwalidzki-flipper/">Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR</a>
                </article>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/2/' => Http::response(<<<'HTML'
            <html><head>
                <link rel="next" href="https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/3/">
            </head><body>
                <p>Mobilex can expose empty paginated archive pages. The scraper must stop here.</p>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/3/' => Http::response(<<<'HTML'
            <html><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/product-that-should-not-be-requested/">Wrong product</a>
                </article>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexProductUrlScraper::class)->scrapeCategories([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
    ]);

    expect($result['product_urls'])->toBe([
        'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
    ])->and($result['category_results'][0]['visited_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/page/2/',
    ]);
});

it('stops category pagination when a later page only repeats already collected product links', function (): void {
    Http::fake([
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/' => Http::response(<<<'HTML'
            <html><head>
                <link rel="next" href="https://mobilex.pl/kategoria-produktu/wozki-aktywne/page/2/">
            </head><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/wozek-aktywny-test/">Wózek aktywny test</a>
                </article>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/page/2/' => Http::response(<<<'HTML'
            <html><head>
                <link rel="next" href="https://mobilex.pl/kategoria-produktu/wozki-aktywne/page/3/">
            </head><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/wozek-aktywny-test/">Wózek aktywny test</a>
                </article>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/page/3/' => Http::response(<<<'HTML'
            <html><body>
                <article class="produkty">
                    <a href="https://mobilex.pl/produkty/product-that-should-not-be-requested/">Wrong product</a>
                </article>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexProductUrlScraper::class)->scrapeCategories([
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/',
    ]);

    expect($result['product_urls'])->toBe([
        'https://mobilex.pl/produkty/wozek-aktywny-test/',
    ])->and($result['category_results'][0]['visited_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/',
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/page/2/',
    ]);
});

it('records failed Mobilex product category URLs', function (): void {
    Http::fake([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexProductUrlScraper::class)->scrapeCategories([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
    ]);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/' => 'HTTP 500',
        ]);
});
